<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderService;

/** MCP-Steuerbarkeit · D11: Reklamation/Gutschrift je Bestellzeile pflegen. */
class OrdersClaimTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.CLAIM';
    }

    public function getDescription(): string
    {
        return 'Pflegt die Reklamation/Gutschrift einer Bestellzeile (claim_status open|tracked|covered|shortage|resolved|credited, '
            . 'claim_qty_packs, claim_note, credit_expected, credit_expected_net).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'line_id' => ['type' => 'integer', 'description' => 'Bestellzeilen-Id.'],
                'claim_status' => ['type' => 'string', 'description' => 'Reklamations-Status.'],
                'claim_qty_packs' => ['type' => 'number', 'description' => 'Reklamierte Gebinde.'],
                'claim_note' => ['type' => 'string', 'description' => 'Notiz.'],
                'credit_expected' => ['type' => 'boolean', 'description' => 'Gutschrift erwartet.'],
                'credit_expected_net' => ['type' => 'number', 'description' => 'Erwarteter Gutschrift-Netto-Betrag.'],
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
        $in = array_intersect_key($arguments, array_flip(['claim_status', 'claim_qty_packs', 'claim_note', 'credit_expected', 'credit_expected_net']));
        if ($in === []) {
            return ToolResult::error('Mindestens ein Reklamations-Feld angeben.', 'VALIDATION_ERROR');
        }

        try {
            app(OrderService::class)->updateClaimLine($team, $lineId, $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['line_id' => $lineId, 'updated' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'order', 'claim', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.orders.RECEIPT'],
            'examples' => ['Reklamiere bei Zeile 30 zwei fehlende Gebinde.'],
        ];
    }
}
