<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\GpService;

/** M8-01: Grundprodukte durchsuchen (D-3) — Tool → Service, team-scoped. */
class GpsSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gps.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die Grundprodukte (GPs) des aktuellen Teams. Hybrid: lexikalisch '
            . '(Name/Slug) plus — sofern der Embedding-Provider aktiv ist — ein semantischer '
            . 'Pass, der Synonyme/Komposita/Übersetzungen findet, die die Tokensuche verfehlt '
            . '(via: lexical|semantic je Treffer). Ohne Provider rein lexikalisch. '
            . 'Liefert id, name, status, main_ingredient_slug — Details via foodalchemist.gps.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Suchbegriff (Name/Hauptzutat)'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
            ],
            'required' => ['q'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $q = (string) $arguments['q'];
        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 10)));
        $treffer = app(GpService::class)->paginate(['search' => $q], $team, $limit);

        $gps = collect($treffer->items())->map(fn ($gp) => [
            'id' => $gp->id, 'name' => $gp->name, 'status' => $gp->status,
            'main_ingredient_slug' => $gp->main_ingredient_slug, 'via' => 'lexical',
        ])->all();

        // E4 (#507): semantische Ergänzung — nur was die Lexik NICHT schon fand.
        $semScores = $this->semanticPoolIds($team, $q, PoolEmbeddingService::ENTITY_TYPE_GP, array_column($gps, 'id'), $limit);
        if ($semScores !== []) {
            $rows = FoodAlchemistGp::visibleToTeam($team)->whereIn('status', ['approved', 'tentative'])
                ->where('is_platzhalter', false)->whereIn('id', array_keys($semScores))
                ->get(['id', 'name', 'status', 'main_ingredient_slug'])->keyBy('id');
            arsort($semScores);
            foreach ($semScores as $id => $score) {
                $gp = $rows->get($id);
                if ($gp === null || count($gps) >= $limit) {
                    continue;
                }
                $gps[] = [
                    'id' => $gp->id, 'name' => $gp->name, 'status' => $gp->status,
                    'main_ingredient_slug' => $gp->main_ingredient_slug,
                    'via' => 'semantic', 'semantic_score' => round($score, 3),
                ];
            }
        }

        return ToolResult::success(['total' => count($gps), 'gps' => $gps]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'search'],
            'examples' => ['Suche Grundprodukte mit Zander', 'Welche GPs gibt es zu Kartoffel?'],
        ];
    }
}
