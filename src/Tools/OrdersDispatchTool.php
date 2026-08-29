<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Services\OrderService;

/**
 * MCP-Steuerbarkeit · D11: ausgewählte Bestell-Entwürfe an die Lieferanten VERSENDEN (echte Mails).
 * Outward + irreversibel → confirm=true Pflicht.
 */
class OrdersDispatchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.DISPATCH';
    }

    public function getDescription(): string
    {
        return 'Versendet ausgewählte team-eigene Bestell-Entwürfe an die Lieferanten (echte E-Mails). '
            . 'Nur versandfertige Entwürfe gehen raus; nicht versandfähige werden zurückgemeldet. Erfordert confirm=true.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Zu versendende Bestell-Ids.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (echter Lieferanten-Versand).'],
            ],
            'required' => ['order_ids', 'confirm'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Versand erfordert confirm=true (echte Lieferanten-Mails).', 'CONFIRM_REQUIRED');
        }
        $orderIds = array_values(array_filter(array_map('intval', (array) ($arguments['order_ids'] ?? []))));
        if ($orderIds === []) {
            return ToolResult::error('order_ids muss mindestens eine Bestell-Id enthalten.', 'VALIDATION_ERROR');
        }
        foreach ($orderIds as $oid) {
            if (($guard = $this->guardOwned($team, FoodAlchemistOrder::class, $oid, 'Bestellung')) !== null) {
                return $guard;
            }
        }

        try {
            $res = app(OrderService::class)->sendSelectedDrafts($team, $orderIds);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['order_ids' => $orderIds, 'result' => $res]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'order', 'dispatch', 'outward'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'external',
            'side_effects' => ['sends_email', 'updates'],
            'related_tools' => ['foodalchemist.orders.CONFIRM_SUPPLIER'],
            'examples' => ['Versende die Bestell-Entwürfe 5 und 6 an die Lieferanten (confirm=true).'],
        ];
    }
}
