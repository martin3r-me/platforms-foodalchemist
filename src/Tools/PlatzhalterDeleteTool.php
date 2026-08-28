<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpService;

/**
 * MCP-Steuerbarkeit · D1: Platzhalter-GP löschen (team-eigen). Der Service blockt bei Nutzung in Rezepten.
 */
class PlatzhalterDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.platzhalter.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht ein team-eigenes Platzhalter-GP. Blockiert, solange es in Rezepten genutzt wird.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'description' => 'Platzhalter-GP-Id (team-eigen).']],
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
        if (! FoodAlchemistGp::where('team_id', $team->id)->where('is_platzhalter', true)->whereKey($id)->exists()) {
            return ToolResult::error('Platzhalter nicht vorhanden oder nicht team-eigen.', 'NOT_FOUND');
        }

        try {
            app(GpService::class)->deletePlatzhalter($team, $id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'platzhalter', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.platzhalter.POST'],
            'examples' => ['Lösche Platzhalter 90.'],
        ];
    }
}
