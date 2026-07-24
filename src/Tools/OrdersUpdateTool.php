<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderService;

/**
 * Spec 20 · E1 (write): Kopf-Felder einer OFFENEN Bestellschiene pflegen — Anlass
 * (reference), Wunsch-Liefertermin (desired_delivery_date) und Notiz (note). Nur eigene
 * Belege, nur solange draft (gesendete Belege sind eingefroren). Zeilen werden hier NICHT
 * angefasst (dafür ADD_NEED/UPDATE_LINE).
 */
class OrdersUpdateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.UPDATE';
    }

    public function getDescription(): string
    {
        return 'Ändert die Kopf-Felder einer offenen Bestellschiene: reference (Anlass; "" löscht), '
            . 'desired_delivery_date (YYYY-MM-DD; "" löscht), note (Notiz; "" löscht). Nur eigene Belege, '
            . 'nur im draft. Zeilen bleiben unberührt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
                'reference' => ['type' => 'string', 'description' => 'Anlass; "" löscht'],
                'desired_delivery_date' => ['type' => 'string', 'description' => 'Wunsch-Liefertermin YYYY-MM-DD; "" löscht'],
                'note' => ['type' => 'string', 'description' => 'Notiz; "" löscht'],
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

        $input = array_intersect_key($arguments, array_flip(['reference', 'desired_delivery_date', 'note']));
        if ($input === []) {
            return ToolResult::error('Nichts zu ändern (reference/desired_delivery_date/note angeben).', 'NO_CHANGE');
        }

        try {
            $order = app(OrderService::class)->updateHeader($team, (int) $arguments['order_id'], $input);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Bestellung nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'order_id' => (int) $order->id,
            'reference' => $order->reference,
            'desired_delivery_date' => $order->desired_delivery_date?->toDateString(),
            'note' => $order->note,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'bestellung', 'order', 'kopf', 'einkauf'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'low',
        ];
    }
}
