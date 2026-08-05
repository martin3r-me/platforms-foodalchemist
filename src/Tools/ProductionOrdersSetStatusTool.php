<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * Spec 18 (write): Status eines Produktionsauftrags setzen (Guard). Erlaubt:
 * planned→in_progress|cancelled · in_progress→done|cancelled. „in_progress"
 * friert den Zeilen-Snapshot ein (letztes Recompute vor dem Start). Nur
 * eigene Team-Belege.
 */
class ProductionOrdersSetStatusTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.SET_STATUS';
    }

    public function getDescription(): string
    {
        return 'Setzt den Status eines Produktionsauftrags (Guard): planned→in_progress (Produktion starten, '
            . 'friert Snapshot ein) | →done (fertig) | →cancelled (storniert). Nur eigene Team-Belege.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['in_progress', 'done', 'cancelled']],
            ],
            'required' => ['order_id', 'status'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ziel = ProductionOrderStatus::tryFrom((string) $arguments['status']);
        if ($ziel === null) {
            return ToolResult::error('Unbekannter Status.', 'VALIDATION_ERROR');
        }

        try {
            $order = app(ProductionOrderService::class)->setStatus($team, (int) $arguments['order_id'], $ziel);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Produktionsauftrag nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'order_id' => (int) $order->id,
            'status' => $order->status instanceof ProductionOrderStatus ? $order->status->value : (string) $order->status,
            'started_at' => $order->started_at?->toDateTimeString(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'produktion', 'production_order', 'status'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'medium',
        ];
    }
}
