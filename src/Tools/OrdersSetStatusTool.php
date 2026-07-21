<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Services\OrderService;

/**
 * Spec 17/S2 (write): Status einer Bestellung setzen (Lebenszyklus mit Guard).
 * Erlaubt: draft→sent|cancelled · sent→confirmed|delivered|cancelled ·
 * confirmed→delivered|cancelled. „sent" friert den Beleg-Snapshot ein (E2);
 * „delivered" ist ein manueller Haken OHNE Bestandsbuchung (E4). Nur eigene Belege.
 */
class OrdersSetStatusTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.SET_STATUS';
    }

    public function getDescription(): string
    {
        return 'Setzt den Status einer Bestellung (Guard): draft→sent (versenden, friert Snapshot ein) '
            . '| sent→confirmed (bestätigt) | →delivered (geliefert, manueller Haken ohne Bestand) | →cancelled. '
            . 'Nur eigene Team-Belege.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['sent', 'confirmed', 'delivered', 'cancelled']],
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
        $ziel = OrderStatus::tryFrom((string) $arguments['status']);
        if ($ziel === null) {
            return ToolResult::error('Unbekannter Status.', 'VALIDATION_ERROR');
        }

        try {
            $order = app(OrderService::class)->setStatus($team, (int) $arguments['order_id'], $ziel);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Bestellung nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'order_id' => (int) $order->id,
            'status' => $order->status instanceof OrderStatus ? $order->status->value : (string) $order->status,
            'sent_at' => $order->sent_at?->toDateTimeString(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'bestellung', 'order', 'status', 'versenden'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'medium',
        ];
    }
}
