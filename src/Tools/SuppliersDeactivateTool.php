<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\SupplierService;

/** MCP-Steuerbarkeit · D4: Lieferanten deaktivieren/reaktivieren (team-eigen). */
class SuppliersDeactivateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.suppliers.DEACTIVATE';
    }

    public function getDescription(): string
    {
        return 'Deaktiviert (oder reaktiviert mit inactive=false) einen team-eigenen Lieferanten.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Lieferanten-Id (team-eigen).'],
                'inactive' => ['type' => 'boolean', 'description' => 'true = deaktivieren (Default), false = reaktivieren.'],
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
        if (($guard = $this->guardOwned($team, FoodAlchemistSupplier::class, $id, 'Lieferant')) !== null) {
            return $guard;
        }
        $inactive = ($arguments['inactive'] ?? true) !== false;

        try {
            app(SupplierService::class)->setInactive($team, $id, $inactive);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'inactive' => $inactive]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'lieferant', 'deaktivieren', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.suppliers.STATUS'],
            'examples' => ['Deaktiviere Lieferant 12.'],
        ];
    }
}
