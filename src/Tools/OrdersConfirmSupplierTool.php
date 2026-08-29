<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Services\OrderService;

/** MCP-Steuerbarkeit · D11: Lieferantenbestätigung an einer Bestellung pflegen (erst nach Versand). */
class OrdersConfirmSupplierTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.CONFIRM_SUPPLIER';
    }

    public function getDescription(): string
    {
        return 'Pflegt die Lieferantenbestätigung einer team-eigenen Bestellung (supplier_order_number, '
            . 'confirmed_delivery_date, supplier_confirmation_note). Setzt „Sent" → „Confirmed".';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer', 'description' => 'Bestell-Id.'],
                'supplier_order_number' => ['type' => 'string', 'description' => 'Auftragsnummer des Lieferanten.'],
                'confirmed_delivery_date' => ['type' => 'string', 'description' => 'Bestätigtes Lieferdatum YYYY-MM-DD.'],
                'supplier_confirmation_note' => ['type' => 'string', 'description' => 'Notiz.'],
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
        $in = array_intersect_key($arguments, array_flip(['supplier_order_number', 'confirmed_delivery_date', 'supplier_confirmation_note']));
        if ($in === []) {
            return ToolResult::error('Mindestens ein Bestätigungs-Feld angeben.', 'VALIDATION_ERROR');
        }

        try {
            app(OrderService::class)->updateSupplierConfirmation($team, $orderId, $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['order_id' => $orderId, 'updated' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'order', 'supplier', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.orders.RECEIPT'],
            'examples' => ['Trage bei Bestellung 5 die Auftragsnummer des Lieferanten ein.'],
        ];
    }
}
