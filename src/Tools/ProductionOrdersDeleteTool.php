<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * Spec 30 E7 (write): Produktionsauftrag löschen.
 *
 * Nur `planned` oder `cancelled`. Ein laufender Auftrag ist Arbeit, die gerade passiert —
 * der wird storniert, nicht gelöscht; ein fertiger ist ein Protokoll und bleibt.
 * Soft-Delete wie überall im Modul.
 */
class ProductionOrdersDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht einen geplanten oder stornierten Produktionsauftrag samt Zeilen (Soft-Delete). '
            . 'Laufende Aufträge werden storniert statt gelöscht, fertige bleiben als Protokoll. Nur eigene Belege.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
            ],
            'required' => ['order_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $orderId = (int) $arguments['order_id'];

        try {
            app(ProductionOrderService::class)->deleteOrder($team, $orderId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Produktionsauftrag nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success(['order_id' => $orderId, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'produktion', 'production_order', 'loeschen'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'medium',
        ];
    }
}
