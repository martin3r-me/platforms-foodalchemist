<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderService;

/**
 * Spec 20 · E2 (write): „Neue Bestellung" — eine (leere) Draft-Bestellschiene für einen
 * Lieferanten anlegen bzw. die bestehende offene zurückgeben (findOrCreate je (team,
 * supplier), idempotent). Nur team-sichtbare Lieferanten. Optionale Kopf-Felder (Anlass,
 * Wunsch-Liefertermin, Notiz) werden direkt gesetzt. Zeilen kommen danach über ADD_LINE
 * (manuell) bzw. ADD_NEED (aus einem Ziel).
 */
class OrdersCreateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.CREATE';
    }

    public function getDescription(): string
    {
        return 'Legt eine offene Bestellschiene (Draft) für einen Lieferanten an oder gibt die '
            . 'bestehende offene zurück (findOrCreate, idempotent je Lieferant). supplier_id = Lieferant, '
            . 'optional reference (Anlass), desired_delivery_date (YYYY-MM-DD), note. Liefert order_id + '
            . 'Status. Zeilen danach via orders.ADD_LINE (manueller Artikel) oder orders.ADD_NEED (aus Ziel).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_id' => ['type' => 'integer'],
                'reference' => ['type' => 'string', 'description' => 'Anlass (optional)'],
                'desired_delivery_date' => ['type' => 'string', 'description' => 'Wunsch-Liefertermin YYYY-MM-DD (optional)'],
                'note' => ['type' => 'string', 'description' => 'Notiz (optional)'],
            ],
            'required' => ['supplier_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $header = array_intersect_key($arguments, array_flip(['reference', 'desired_delivery_date', 'note']));

        try {
            $order = app(OrderService::class)->createDraft(
                $team,
                (int) $arguments['supplier_id'],
                $header,
                method_exists($context->user, 'getKey') ? (int) $context->user->getKey() : null,
            );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_FOUND');
        } catch (\Throwable $e) {
            return ToolResult::error('Bestellung konnte nicht angelegt werden.', 'ERROR');
        }

        $status = $order->status instanceof \Platform\FoodAlchemist\Enums\OrderStatus
            ? $order->status->value
            : (string) $order->status;

        return ToolResult::success([
            'order_id' => (int) $order->id,
            'supplier_id' => (int) $order->supplier_id,
            'status' => $status,
            'reference' => $order->reference,
            'desired_delivery_date' => $order->desired_delivery_date?->toDateString(),
            'note' => $order->note,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'bestellung', 'order', 'direktbestellung', 'einkauf', 'schiene'],
            'read_only' => false,
            'idempotent' => true,   // findOrCreate je (team, supplier)
            'risk_level' => 'low',
        ];
    }
}
