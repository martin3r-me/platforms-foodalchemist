<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Services\OrderService;

/** MCP-Steuerbarkeit · D11: Freigabe-Status am Bestellkopf pflegen (requested/approved/rejected). */
class OrdersUpdateApprovalTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.UPDATE_APPROVAL';
    }

    public function getDescription(): string
    {
        return 'Pflegt den Freigabe-Status einer team-eigenen Bestellung (approval_status requested|approved|rejected, '
            . 'approval_note). Nur vor Abschluss editierbar.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer', 'description' => 'Bestell-Id.'],
                'approval_status' => ['type' => 'string', 'enum' => ['requested', 'approved', 'rejected'], 'description' => 'Freigabe-Status.'],
                'approval_note' => ['type' => 'string', 'description' => 'Notiz.'],
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
        $in = array_intersect_key($arguments, array_flip(['approval_status', 'approval_note']));
        if ($in === []) {
            return ToolResult::error('Mindestens ein Freigabe-Feld angeben.', 'VALIDATION_ERROR');
        }

        try {
            app(OrderService::class)->updateApproval($team, $orderId, $in, $context->user->id ?? null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['order_id' => $orderId, 'updated' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'order', 'approval', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.orders.DISPATCH'],
            'examples' => ['Gib Bestellung 5 frei (approved).'],
        ];
    }
}
