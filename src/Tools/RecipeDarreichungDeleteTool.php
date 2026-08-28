<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\DarreichungService;

/** MCP-Steuerbarkeit · D3: Darreichungsform eines team-eigenen Gerichts löschen. */
class RecipeDarreichungDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_darreichung.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht eine Darreichungsform (Servierform) eines team-eigenen Gerichts.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['presentation_id' => ['type' => 'integer', 'description' => 'Darreichungs-Id.']],
            'required' => ['presentation_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['presentation_id'] ?? 0);
        if (($guard = $this->guardDarreichungOwned($team, $id)) !== null) {
            return $guard;
        }

        try {
            app(DarreichungService::class)->loeschen($team, $id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['presentation_id' => $id, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'darreichung', 'loeschen', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.recipe_darreichung.POST'],
            'examples' => ['Lösche Darreichung 88.'],
        ];
    }
}
