<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderService;

/** MCP-Steuerbarkeit · D11: eine Bestellzeile entfernen. Confirm=true Pflicht. */
class OrdersRemoveLineTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.REMOVE_LINE';
    }

    public function getDescription(): string
    {
        return 'Entfernt eine Zeile aus einer team-eigenen Bestellung. Erfordert confirm=true.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'line_id' => ['type' => 'integer', 'description' => 'Bestellzeilen-Id.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (destruktive Aktion).'],
            ],
            'required' => ['line_id', 'confirm'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Entfernen erfordert confirm=true (destruktive Aktion).', 'CONFIRM_REQUIRED');
        }
        $lineId = (int) ($arguments['line_id'] ?? 0);
        if (($guard = $this->guardOrderLineOwned($team, $lineId)) !== null) {
            return $guard;
        }

        try {
            app(OrderService::class)->removeLine($team, $lineId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['line_id' => $lineId, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'order', 'line', 'delete'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.orders.SWITCH_ARTICLE'],
            'examples' => ['Entferne Bestellzeile 30 (confirm=true).'],
        ];
    }
}
