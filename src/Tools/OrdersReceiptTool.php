<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderService;

/** MCP-Steuerbarkeit · D11: Wareneingang je Bestellzeile erfassen (empfangene Gebinde-Menge + Notiz). */
class OrdersReceiptTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.RECEIPT';
    }

    public function getDescription(): string
    {
        return 'Erfasst den Wareneingang einer Bestellzeile (received_qty_packs = empfangene Gebinde; optional note). '
            . 'Bestellung mit orders.GET vorher prüfen; Abschluss über die Beleg-Fläche.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'line_id' => ['type' => 'integer', 'description' => 'Bestellzeilen-Id.'],
                'received_qty_packs' => ['type' => 'number', 'description' => 'Empfangene Gebinde (null = offen).'],
                'note' => ['type' => 'string', 'description' => 'Notiz.'],
            ],
            'required' => ['line_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $lineId = (int) ($arguments['line_id'] ?? 0);
        if (($guard = $this->guardOrderLineOwned($team, $lineId)) !== null) {
            return $guard;
        }

        try {
            app(OrderService::class)->updateReceiptLine(
                $team,
                $lineId,
                array_key_exists('received_qty_packs', $arguments) ? $arguments['received_qty_packs'] : null,
                isset($arguments['note']) ? (string) $arguments['note'] : null
            );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['line_id' => $lineId, 'received' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'order', 'receipt', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.orders.CLAIM'],
            'examples' => ['Erfasse bei Zeile 30 einen Wareneingang von 5 Gebinden.'],
        ];
    }
}
