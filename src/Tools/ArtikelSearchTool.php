<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
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
            . 'Filter wie der Lieferanten-Browser, für beide Pässe: supplier_id, art = food|nonfood|pruefen, only_active (Standard true). '
            . 'Liefert id, designation, supplier_id, supplier, article_number. Vollständiges Blättern (auch ohne Suchbegriff) → '
            . 'foodalchemist.artikel.LIST. Unbekannte Parameter stehen in warnungen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
                'supplier_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Nur Artikel dieses Lieferanten.'],
                'art' => ['type' => 'string', 'enum' => ['food', 'nonfood', 'pruefen']],
                'only_active' => ['type' => 'boolean', 'default' => true, 'description' => 'false zeigt auch ausgelistete und eingestellte Artikel.'],
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
        if (isset($arguments['art']) && ! in_array($arguments['art'], ['food', 'nonfood', 'pruefen'], true)) {
            return ToolResult::error('art muss food, nonfood oder pruefen sein.', 'VALIDATION_ERROR');
        }
        $filter = ['onlyActive' => filter_var($arguments['only_active'] ?? true, FILTER_VALIDATE_BOOLEAN), 'art' => $arguments['art'] ?? '',
            'supplier_ids' => isset($arguments['supplier_id']) ? [(int) $arguments['supplier_id']] : []];
        $treffer = app(SupplierItemService::class)->searchGlobal($team, $q, $filter, $limit);
        $artikel = collect($treffer->items())->map(fn ($i) => [
            'id' => $i->id, 'designation' => $i->designation,
            'article_number' => $i->article_number, 'supplier_id' => $i->supplier_id,
            'supplier' => $i->supplier?->name ?? null, 'via' => 'lexical',
        ])->all();

        // Hybrid (Spec 15 §5a): semantischer Pass über den Lieferantenartikel-Pool — die Artikel
        // werden bereits vom SupplierItemEmbeddingObserver embeddet; hier nur NEUES ergänzen.
        $sem = $this->semanticPoolIds($team, $q, PoolEmbeddingService::ENTITY_TYPE_SUPPLIER_ITEM, array_column($artikel, 'id'), $limit);
        if ($sem !== []) {
            arsort($sem);
            $rows = app(SupplierItemService::class)->byIds($team, array_keys($sem), $filter)->load('supplier:id,name')->keyBy('id');
            foreach ($sem as $id => $score) {
                $i = $rows->get($id);
                if ($i === null || count($artikel) >= $limit) {
                    continue;
                }
                $artikel[] = [
                    'id' => $i->id, 'designation' => $i->designation,
                    'article_number' => $i->article_number, 'supplier_id' => $i->supplier_id,
                    'supplier' => $i->supplier?->name ?? null,
                    'via' => 'semantic', 'semantic_score' => round($score, 3),
                ];
            }
        }

        return $this->erfolgMitWarnungen(['total' => count($artikel), 'artikel' => $artikel], $arguments);
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
            'related_tools' => ['foodalchemist.artikel.LIST', 'foodalchemist.suppliers.SEARCH'],
            'examples' => ['Suche Lieferantenartikel zu Zander'],
        ];
    }
}
