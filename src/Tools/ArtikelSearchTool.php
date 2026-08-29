<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\SupplierItemService;

/** M8-01: Lieferanten-Artikel global durchsuchen (D-2). */
class ArtikelSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.artikel.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die Lieferanten-Artikel des Teams global. Hybrid: lexikalisch (Bezeichnung/'
            . 'Artikelnummer) plus — sofern der Embedding-Provider aktiv ist — ein semantischer Pass über '
            . 'den Artikel-Pool (findet auch, was nur im klassifizierten Haupt-Slug steht; via: lexical|semantic). '
            . 'Liefert id, designation, supplier, article_number.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string'],
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
        $q = trim((string) ($arguments['q'] ?? ''));
        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 10)));
        $treffer = app(SupplierItemService::class)->searchGlobal($team, $q, [], $limit);
        $artikel = collect($treffer->items())->map(fn ($i) => [
            'id' => $i->id, 'designation' => $i->designation,
            'article_number' => $i->article_number,
            'supplier' => $i->supplier?->name ?? null, 'via' => 'lexical',
        ])->all();

        // Hybrid (Spec 15 §5a): semantischer Pass über den Lieferantenartikel-Pool — die Artikel
        // werden bereits vom SupplierItemEmbeddingObserver embeddet; hier nur NEUES ergänzen.
        $sem = $this->semanticPoolIds($team, $q, PoolEmbeddingService::ENTITY_TYPE_SUPPLIER_ITEM, array_column($artikel, 'id'), $limit);
        if ($sem !== []) {
            arsort($sem);
            $rows = FoodAlchemistSupplierItem::visibleToTeam($team)->with('supplier')
                ->whereIn('id', array_keys($sem))->get()->keyBy('id');
            foreach ($sem as $id => $score) {
                $i = $rows->get($id);
                if ($i === null || count($artikel) >= $limit) {
                    continue;
                }
                $artikel[] = [
                    'id' => $i->id, 'designation' => $i->designation,
                    'article_number' => $i->article_number,
                    'supplier' => $i->supplier?->name ?? null,
                    'via' => 'semantic', 'semantic_score' => round($score, 3),
                ];
            }
        }

        return ToolResult::success(['total' => count($artikel), 'artikel' => $artikel]);
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
            'tags' => ['foodalchemist', 'artikel', 'lieferantenartikel', 'lieferant', 'search'],
            'examples' => ['Suche Lieferantenartikel zu Zander'],
        ];
    }
}
