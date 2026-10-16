<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\SupplierService;

/** MCP-Steuerbarkeit · D4: Lieferanten-Stammdaten bearbeiten (team-eigen). */
class SuppliersPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.suppliers.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet die Stammdaten eines team-eigenen Lieferanten. In input kann auch `group` (Code oder Id aus foodalchemist.supplier_groups.LIST, z. B. GETRAENKE, VERLEIH) oder group_id gesetzt werden; leer löst die Gruppe. Eine so gesetzte Gruppe gilt als von Hand und wird vom Vorschlag nie überschrieben.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Lieferanten-Id (team-eigen).'],
                'input' => ['type' => 'object', 'description' => 'Zu schreibende Stammdaten.'],
            ],
            'required' => ['id', 'input'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $input = $arguments['input'] ?? null;
        if (! is_array($input) || $input === []) {
            return ToolResult::error('input muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistSupplier::class, $id, 'Lieferant')) !== null) {
            return $guard;
        }

        try {
            $s = app(SupplierService::class)->update($team, $id, $input);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $s->id, 'name' => $s->name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'lieferant', 'bearbeiten', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.suppliers.STATUS', 'foodalchemist.supplier_conditions.PUT'],
            'examples' => ['Ändere die Adresse von Lieferant 12.'],
        ];
    }
}
