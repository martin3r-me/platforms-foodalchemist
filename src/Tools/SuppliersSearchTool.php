<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * Spec 15 §5a: Lieferanten (Entität, NICHT Artikel) durchsuchen. Hybrid: lexikalisch
 * (Name) plus — sofern der Embedding-Provider aktiv ist — ein semantischer Pass über
 * Name/Branche/Stadt (behebt „Lieferant nicht gefunden"). Für Artikel/Sortiment →
 * foodalchemist.artikel.SEARCH.
 */
class SuppliersSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.suppliers.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die Lieferanten (Entität) des aktuellen Teams. Hybrid: lexikalisch (Name) '
            . 'plus — sofern der Embedding-Provider aktiv ist — ein semantischer Pass über Name/Branche/'
            . 'Stadt (via: lexical|semantic je Treffer). Ohne Provider rein lexikalisch. Liefert id, name, '
            . 'branch, city, is_inactive — Details via foodalchemist.suppliers.GET. Für Artikel/Sortiment '
            . 'eines Lieferanten → foodalchemist.artikel.SEARCH.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Suchbegriff (Name/Branche/Stadt)'],
                'include_inactive' => ['type' => 'boolean', 'default' => false],
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
        $includeInactive = (bool) ($arguments['include_inactive'] ?? false);

        $base = fn () => FoodAlchemistSupplier::visibleToTeam($team)
            ->when(! $includeInactive, fn ($x) => $x->where('is_inactive', false));

        $suppliers = $q === '' ? []
            : $base()->where('name', 'like', '%' . $q . '%')->orderBy('name')->limit($limit)
                ->get(['id', 'name', 'branch', 'city', 'is_inactive'])
                ->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->name, 'branch' => $s->branch,
                    'city' => $s->city, 'is_inactive' => (bool) $s->is_inactive, 'via' => 'lexical',
                ])->all();

        // Spec 15 §5a: semantische Ergänzung — nur was die Lexik NICHT schon fand.
        $semScores = $this->semanticPoolIds($team, $q, PoolEmbeddingService::ENTITY_TYPE_SUPPLIER, array_column($suppliers, 'id'), $limit);
        if ($semScores !== []) {
            $rows = $base()->whereIn('id', array_keys($semScores))
                ->get(['id', 'name', 'branch', 'city', 'is_inactive'])->keyBy('id');
            arsort($semScores);
            foreach ($semScores as $id => $score) {
                $s = $rows->get($id);
                if ($s === null || count($suppliers) >= $limit) {
                    continue;
                }
                $suppliers[] = [
                    'id' => $s->id, 'name' => $s->name, 'branch' => $s->branch,
                    'city' => $s->city, 'is_inactive' => (bool) $s->is_inactive,
                    'via' => 'semantic', 'semantic_score' => round($score, 3),
                ];
            }
        }

        return ToolResult::success(['total' => count($suppliers), 'suppliers' => $suppliers]);
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
            'tags' => ['foodalchemist', 'supplier', 'lieferant', 'search'],
            'related_tools' => ['foodalchemist.suppliers.GET', 'foodalchemist.artikel.SEARCH'],
            'examples' => ['Suche Lieferant Hanos', 'Welche Großhändler gibt es in Venlo?'],
        ];
    }
}
