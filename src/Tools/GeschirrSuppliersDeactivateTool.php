<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGeschirrSupplier;
use Platform\FoodAlchemist\Services\GeschirrService;

/** MCP-Steuerbarkeit · D4: Geschirr-Lieferant deaktivieren/reaktivieren (team-eigen). */
class GeschirrSuppliersDeactivateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.geschirr_suppliers.DEACTIVATE';
    }

    public function getDescription(): string
    {
        return 'Deaktiviert (inactive=true, Default) oder reaktiviert (false) einen team-eigenen Geschirr-Lieferanten.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Geschirr-Lieferanten-Id (team-eigen).'],
                'inactive' => ['type' => 'boolean', 'description' => 'true = deaktivieren (Default).'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistGeschirrSupplier::class, $id, 'Geschirr-Lieferant')) !== null) {
            return $guard;
        }
        $inactive = ($arguments['inactive'] ?? true) !== false;

        try {
            app(GeschirrService::class)->setSupplierInactive($team, $id, $inactive);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'inactive' => $inactive]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'geschirr', 'lieferant', 'deaktivieren', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.geschirr_suppliers.PUT'],
            'examples' => ['Deaktiviere Geschirr-Lieferant 3.'],
        ];
    }
}
