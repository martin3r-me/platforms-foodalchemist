<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GeschirrService;

/** MCP-Steuerbarkeit · D4: Geschirr-Artikel listen — je Lieferant (supplier_id) oder global (q). */
class GeschirrItemsListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.geschirr_items.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet Geschirr-Artikel (team-sichtbar): mit supplier_id je Lieferant, sonst global (optional q-Filter).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_id' => ['type' => 'integer', 'description' => 'Optional: nur Artikel dieses Geschirr-Lieferanten.'],
                'q' => ['type' => 'string', 'description' => 'Optional: globaler Suchbegriff.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $svc = app(GeschirrService::class);
        $supplierId = isset($arguments['supplier_id']) ? (int) $arguments['supplier_id'] : 0;
        $treffer = $supplierId > 0
            ? $svc->paginateForSupplier($team, $supplierId)
            : $svc->searchGlobal($team, (string) ($arguments['q'] ?? ''));

        return ToolResult::success([
            'total' => $treffer->total(),
            'items' => collect($treffer->items())->map(fn ($i) => [
                'id' => (int) $i->id,
                'name' => $i->label ?? $i->name ?? null,
                'is_inactive' => (bool) ($i->is_inactive ?? false),
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'geschirr', 'artikel', 'liste'],
            'related_tools' => ['foodalchemist.geschirr_items.POST'],
            'examples' => ['Zeig die Geschirr-Artikel von Lieferant 3.'],
        ];
    }
}
