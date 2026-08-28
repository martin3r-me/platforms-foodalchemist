<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\SupplierService;

/** MCP-Steuerbarkeit · D4: Status eines team-eigenen Lieferanten setzen. */
class SuppliersStatusTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.suppliers.STATUS';
    }

    public function getDescription(): string
    {
        return 'Setzt den Status eines team-eigenen Lieferanten (Wert wird vom Service validiert).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Lieferanten-Id (team-eigen).'],
                'status' => ['type' => 'string', 'description' => 'Neuer Status.'],
            ],
            'required' => ['id', 'status'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $status = trim((string) ($arguments['status'] ?? ''));
        if ($status === '') {
            return ToolResult::error('status ist Pflicht.', 'VALIDATION_ERROR');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistSupplier::class, $id, 'Lieferant')) !== null) {
            return $guard;
        }

        try {
            $s = app(SupplierService::class)->setStatus($team, $id, $status);
        } catch (\RuntimeException | \ValueError $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $s->id, 'status' => $s->status instanceof \BackedEnum ? $s->status->value : (string) $s->status]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'lieferant', 'status', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.suppliers.DEACTIVATE'],
            'examples' => ['Setze Lieferant 12 auf aktiv.'],
        ];
    }
}
