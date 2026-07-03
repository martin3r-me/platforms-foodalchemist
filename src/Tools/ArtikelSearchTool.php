<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
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
        return 'Durchsucht die Lieferanten-Artikel des Teams global (Bezeichnung/Artikelnummer). '
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
        $treffer = app(SupplierItemService::class)->searchGlobal(
            $team, (string) $arguments['q'], [], min(50, max(1, (int) ($arguments['limit'] ?? 10))),
        );

        return ToolResult::success([
            'total' => $treffer->total(),
            'artikel' => collect($treffer->items())->map(fn ($i) => [
                'id' => $i->id, 'designation' => $i->designation,
                'article_number' => $i->article_number,
                'supplier' => $i->supplier?->name ?? null,
            ])->all(),
        ]);
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
