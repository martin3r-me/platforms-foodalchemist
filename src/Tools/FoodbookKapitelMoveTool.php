<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FoodbookService;

/** MCP-Steuerbarkeit · D7: Kapitel unter ein anderes Eltern-Kapitel hängen (Baum umhängen). */
class FoodbookKapitelMoveTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook_kapitel.MOVE';
    }

    public function getDescription(): string
    {
        return 'Hängt ein Kapitel unter ein anderes Eltern-Kapitel (new_parent_id null/weglassen = Wurzel-Ebene). Nur im Entwurf-Foodbook.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kapitel_id' => ['type' => 'integer', 'description' => 'Zu verschiebendes Kapitel.'],
                'new_parent_id' => ['type' => 'integer', 'description' => 'Neues Eltern-Kapitel (weglassen = Wurzel).'],
            ],
            'required' => ['kapitel_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $kapitelId = (int) ($arguments['kapitel_id'] ?? 0);
        if (($guard = $this->guardFoodbookEditable($team, $this->foodbookVonKapitel($team, $kapitelId))) !== null) {
            return $guard;
        }

        try {
            app(FoodbookService::class)->moveKapitel($team, $kapitelId, isset($arguments['new_parent_id']) ? (int) $arguments['new_parent_id'] : null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['kapitel_id' => $kapitelId, 'new_parent_id' => isset($arguments['new_parent_id']) ? (int) $arguments['new_parent_id'] : null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'kapitel', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.foodbook_kapitel.REORDER'],
            'examples' => ['Hänge Kapitel 12 unter Kapitel 3.'],
        ];
    }
}
