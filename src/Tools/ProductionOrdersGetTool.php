<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * Spec 18 (read): Produktionsaufträge. Ohne order_id → Liste (optional nach
 * Status gefiltert); mit order_id → Detail inkl. Rezept-/Ansätze-Zeilen,
 * Ziele, Kennzahlen (Ansätze/Portionen/Arbeitszeit gesamt) und Warnungen.
 */
class ProductionOrdersGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.GET';
    }

    public function getDescription(): string
    {
        return 'Produktionsaufträge des Teams. Ohne order_id: Liste (offene/geplante zuerst; optional '
            . 'status=planned|in_progress|done|cancelled). Mit order_id: Detail mit Rezept-/Ansätze-Zeilen '
            . '(Ansätze, Portionen, Arbeitszeit, Zubereitung, Darreichung), Zielen und Kennzahlen. Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['planned', 'in_progress', 'done', 'cancelled']],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(ProductionOrderService::class);

        if (! empty($arguments['order_id'])) {
            try {
                return ToolResult::success($svc->detail($team, (int) $arguments['order_id']));
            } catch (\Throwable $e) {
                return ToolResult::error('Produktionsauftrag nicht sichtbar/vorhanden.', 'NOT_FOUND');
            }
        }

        $status = $arguments['status'] ?? null;
        $liste = $svc->listForTeam($team, $status)->map(fn ($o) => [
            'id' => (int) $o->id,
            'production_date' => $o->production_date?->toDateString(),
            'status' => $o->status instanceof ProductionOrderStatus ? $o->status->value : (string) $o->status,
            'reference' => $o->reference,
        ])->all();

        return ToolResult::success(['production_orders' => $liste, 'count' => count($liste)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'produktion', 'production_order'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
        ];
    }
}
