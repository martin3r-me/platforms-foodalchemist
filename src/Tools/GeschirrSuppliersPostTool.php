<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GeschirrService;

/** MCP-Steuerbarkeit · D4: Geschirr-Lieferant anlegen (team-eigen). */
class GeschirrSuppliersPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.geschirr_suppliers.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Geschirr-Lieferanten an (team-eigen). input.name ist Pflicht.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['input' => ['type' => 'object', 'description' => 'Stammdaten (name Pflicht).']],
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
            $s = app(GeschirrService::class)->createSupplier($team, $input);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $s->id, 'name' => $s->name ?? null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'geschirr', 'lieferant', 'anlegen', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.geschirr_suppliers.PUT', 'foodalchemist.geschirr_items.POST'],
            'examples' => ['Lege den Geschirr-Lieferanten „Villeroy & Boch" an.'],
        ];
    }
}
