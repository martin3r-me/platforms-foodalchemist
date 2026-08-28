<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent as Equiv;
use Platform\FoodAlchemist\Services\ComponentEquivalentService;

/**
 * MCP-Steuerbarkeit · D1: Äquivalenz (Ersatz) lösen. Nur team-eigene Äquivalenzen (Service scoped team_id).
 */
class ComponentEquivalentsDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.component_equivalents.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löst eine team-eigene Ersatz-/Äquivalenz-Beziehung (per Id).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'description' => 'Äquivalenz-Id (team-eigen).']],
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
        if (! Equiv::where('team_id', $team->id)->whereKey($id)->exists()) {
            return ToolResult::error('Äquivalenz nicht vorhanden oder nicht team-eigen.', 'NOT_FOUND');
        }

        app(ComponentEquivalentService::class)->loese($team, $id);

        return ToolResult::success(['id' => $id, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'ersatz', 'aequivalenz', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.component_equivalents.POST'],
            'examples' => ['Lösche Äquivalenz 55.'],
        ];
    }
}
