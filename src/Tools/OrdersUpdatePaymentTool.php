<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Services\OrderService;

/** MCP-Steuerbarkeit · D11: Zahlungsstatus am Rechnungsbeleg pflegen (open/paid/disputed). */
class OrdersUpdatePaymentTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.UPDATE_PAYMENT';
    }

    public function getDescription(): string
    {
        return 'Pflegt den Zahlungsstatus einer team-eigenen Bestellung (payment_status open|paid|disputed, '
            . 'invoice_paid_at, payment_note). Braucht zuerst einen Rechnungskopf.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer', 'description' => 'Bestell-Id.'],
                'payment_status' => ['type' => 'string', 'enum' => ['open', 'paid', 'disputed'], 'description' => 'Zahlungsstatus.'],
                'invoice_paid_at' => ['type' => 'string', 'description' => 'Zahldatum YYYY-MM-DD.'],
                'payment_note' => ['type' => 'string', 'description' => 'Notiz.'],
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
        $orderId = (int) ($arguments['order_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistOrder::class, $orderId, 'Bestellung')) !== null) {
            return $guard;
        }
        $in = array_intersect_key($arguments, array_flip(['payment_status', 'invoice_paid_at', 'payment_note']));
        if ($in === []) {
            return ToolResult::error('Mindestens ein Zahlungs-Feld angeben.', 'VALIDATION_ERROR');
        }

        try {
            app(OrderService::class)->updatePayment($team, $orderId, $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['order_id' => $orderId, 'updated' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'order', 'payment', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.orders.UPDATE_INVOICE'],
            'examples' => ['Markiere Bestellung 5 als bezahlt.'],
        ];
    }
}
