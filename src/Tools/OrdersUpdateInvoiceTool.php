<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Services\OrderService;

/** MCP-Steuerbarkeit · D11: Rechnungskopf einer Bestellung pflegen (Nummer/Datum/Notiz; erst nach Versand). */
class OrdersUpdateInvoiceTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.UPDATE_INVOICE';
    }

    public function getDescription(): string
    {
        return 'Pflegt den Rechnungskopf einer team-eigenen Bestellung (invoice_number, invoice_date, invoice_note). '
            . 'Erst nach dem Absenden möglich. Zeilenprüfung ist separat.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer', 'description' => 'Bestell-Id.'],
                'invoice_number' => ['type' => 'string', 'description' => 'Rechnungsnummer.'],
                'invoice_date' => ['type' => 'string', 'description' => 'Rechnungsdatum YYYY-MM-DD.'],
                'invoice_note' => ['type' => 'string', 'description' => 'Notiz.'],
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
        $in = array_intersect_key($arguments, array_flip(['invoice_number', 'invoice_date', 'invoice_note']));
        if ($in === []) {
            return ToolResult::error('Mindestens ein Rechnungskopf-Feld angeben.', 'VALIDATION_ERROR');
        }

        try {
            app(OrderService::class)->updateInvoiceHeader($team, $orderId, $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['order_id' => $orderId, 'updated' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'order', 'invoice', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.orders.UPDATE_PAYMENT'],
            'examples' => ['Trage bei Bestellung 5 die Rechnungsnummer R-2027-001 ein.'],
        ];
    }
}
