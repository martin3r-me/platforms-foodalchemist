<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\FoodbookService;

/** MCP-Steuerbarkeit · D7: Kapitel einer Ebene (gleicher parent) in einem Entwurf-Foodbook neu ordnen. */
class FoodbookKapitelReorderTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook_kapitel.REORDER';
    }

    public function getDescription(): string
    {
        return 'Ordnet Kapitel einer Ebene (gleicher parent_id, null = Wurzel) in einem team-eigenen Entwurf-Foodbook neu (ids in Zielreihenfolge).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'foodbook_id' => ['type' => 'integer', 'description' => 'Foodbook-Id.'],
                'parent_id' => ['type' => 'integer', 'description' => 'Eltern-Kapitel (weglassen/null = Wurzel-Ebene).'],
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Kapitel-Ids in Zielreihenfolge.'],
            ],
            'required' => ['foodbook_id', 'ids'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ids = $arguments['ids'] ?? null;
        if (! is_array($ids) || $ids === []) {
            return ToolResult::error('ids muss ein nicht-leeres Array sein.', 'VALIDATION_ERROR');
        }
        $foodbookId = (int) ($arguments['foodbook_id'] ?? 0);
        if (($guard = $this->guardFoodbookEditable($team, FoodAlchemistFoodbook::visibleToTeam($team)->whereKey($foodbookId)->first())) !== null) {
            return $guard;
        }

        try {
            app(FoodbookService::class)->reorderKapitel(
                $team,
                $foodbookId,
                isset($arguments['parent_id']) ? (int) $arguments['parent_id'] : null,
                array_map('intval', $ids)
            );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['foodbook_id' => $foodbookId, 'ids' => array_map('intval', $ids)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'kapitel', 'reorder', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.foodbook_kapitel.MOVE'],
            'examples' => ['Ordne die Wurzel-Kapitel von Foodbook 5 als [3,1,2].'],
        ];
    }
}
