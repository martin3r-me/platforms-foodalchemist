<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpService;

/**
 * MCP-Steuerbarkeit · D1: Platzhalter-GP umbenennen (team-eigen).
 */
class PlatzhalterPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.platzhalter.PUT';
    }

    public function getDescription(): string
    {
        return 'Benennt ein team-eigenes Platzhalter-GP um.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Platzhalter-GP-Id (team-eigen).'],
                'name' => ['type' => 'string', 'description' => 'Neuer Basis-Name.'],
            ],
            'required' => ['id', 'name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (trim((string) ($arguments['name'] ?? '')) === '') {
            return ToolResult::error('name ist Pflicht.', 'VALIDATION_ERROR');
        }

        $id = (int) ($arguments['id'] ?? 0);
        if (! FoodAlchemistGp::where('team_id', $team->id)->where('is_platzhalter', true)->whereKey($id)->exists()) {
            return ToolResult::error('Platzhalter nicht vorhanden oder nicht team-eigen.', 'NOT_FOUND');
        }

        try {
            $gp = app(GpService::class)->renamePlatzhalter($team, $id, (string) $arguments['name']);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $gp->id, 'name' => $gp->name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'platzhalter', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.platzhalter.POST', 'foodalchemist.platzhalter.DELETE'],
            'examples' => ['Benenne Platzhalter 90 in „Bindemittel" um.'],
        ];
    }
}
