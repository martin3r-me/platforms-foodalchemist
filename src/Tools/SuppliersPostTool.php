<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SupplierService;

/** MCP-Steuerbarkeit · D4: Lieferant anlegen (team-eigen; Dedup über den Namen). */
class SuppliersPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.suppliers.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Lieferanten an (team-eigen). input.name ist Pflicht; weitere Stammdaten optional.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['input' => ['type' => 'object', 'description' => 'Lieferanten-Stammdaten (name Pflicht).']],
            'required' => ['input'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $input = $arguments['input'] ?? null;
        if (! is_array($input) || trim((string) ($input['name'] ?? '')) === '') {
            return ToolResult::error('input.name ist Pflicht.', 'VALIDATION_ERROR');
        }

        try {
            $s = app(SupplierService::class)->create($team, $input);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $s->id, 'name' => $s->name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'lieferant', 'anlegen', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.suppliers.PUT', 'foodalchemist.suppliers.GET'],
            'examples' => ['Lege den Lieferanten „Hanos" an.'],
        ];
    }
}
