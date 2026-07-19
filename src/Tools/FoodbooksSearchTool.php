<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * Spec 15 §5a: Foodbooks durchsuchen. Hybrid: lexikalisch (Label/Kunde) plus —
 * sofern der Embedding-Provider aktiv ist — ein semantischer Pass über Titel/Kunde/
 * Beschreibung (via: lexical|semantic je Treffer). Details via foodalchemist.foodbooks.GET.
 */
class FoodbooksSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbooks.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die Foodbooks des aktuellen Teams. Hybrid: lexikalisch (Label/Kunde) plus — '
            . 'sofern der Embedding-Provider aktiv ist — ein semantischer Pass über Titel/Kunde/Beschreibung '
            . '(via: lexical|semantic je Treffer). Ohne Provider rein lexikalisch. Liefert id, label, customer, '
            . 'jahr, status — Details (Kapitel/Blöcke) via foodalchemist.foodbooks.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Suchbegriff (Label/Kunde)'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 15],
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
        $q = trim((string) $arguments['q']);
        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 15)));

        $cols = ['id', 'label', 'customer', 'jahr', 'status'];
        $foodbooks = $q === '' ? []
            : FoodAlchemistFoodbook::visibleToTeam($team)
                ->where(fn ($w) => $w->where('label', 'like', '%' . $q . '%')->orWhere('customer', 'like', '%' . $q . '%'))
                ->orderBy('label')->limit($limit)->get($cols)
                ->map(fn ($f) => [
                    'id' => $f->id, 'label' => $f->label, 'customer' => $f->customer,
                    'jahr' => $f->jahr, 'status' => $f->status, 'via' => 'lexical',
                ])->all();

        // Spec 15 §5a: semantische Ergänzung — nur was die Lexik NICHT schon fand.
        $semScores = $this->semanticPoolIds($team, $q, PoolEmbeddingService::ENTITY_TYPE_FOODBOOK, array_column($foodbooks, 'id'), $limit);
        if ($semScores !== []) {
            $rows = FoodAlchemistFoodbook::visibleToTeam($team)->whereIn('id', array_keys($semScores))->get($cols)->keyBy('id');
            arsort($semScores);
            foreach ($semScores as $id => $score) {
                $f = $rows->get($id);
                if ($f === null || count($foodbooks) >= $limit) {
                    continue;
                }
                $foodbooks[] = [
                    'id' => $f->id, 'label' => $f->label, 'customer' => $f->customer,
                    'jahr' => $f->jahr, 'status' => $f->status,
                    'via' => 'semantic', 'semantic_score' => round($score, 3),
                ];
            }
        }

        return ToolResult::success(['total' => count($foodbooks), 'foodbooks' => $foodbooks]);
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
            'tags' => ['foodalchemist', 'foodbook', 'search'],
            'related_tools' => ['foodalchemist.foodbooks.GET'],
            'examples' => ['Suche Foodbook Sommerkarte', 'Welche Foodbooks für Broich gibt es?'],
        ];
    }
}
