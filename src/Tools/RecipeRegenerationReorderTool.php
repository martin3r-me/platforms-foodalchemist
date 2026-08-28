<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/** MCP-Steuerbarkeit · D3: Regenerations-Programme eines team-eigenen Gerichts neu ordnen. */
class RecipeRegenerationReorderTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_regeneration.REORDER';
    }

    public function getDescription(): string
    {
        return 'Ordnet die Regenerations-Programme eines team-eigenen Gerichts neu (ids in Zielreihenfolge).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Gericht-Id (team-eigen).'],
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Regenerations-Ids in Zielreihenfolge.'],
            ],
            'required' => ['recipe_id', 'ids'],
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

        $recipeId = (int) ($arguments['recipe_id'] ?? 0);
        if (($guard = $this->guardVkRecipe($team, $recipeId)) !== null) {
            return $guard;
        }

        try {
            app(SalesRecipeService::class)->reorderRegenerations($team, $recipeId, array_map('intval', $ids));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['recipe_id' => $recipeId, 'ids' => array_map('intval', $ids)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gericht', 'regeneration', 'reorder', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipe_regeneration.PUT'],
            'examples' => ['Ordne die Regenerationen von Gericht 501 als [3,1,2].'],
        ];
    }
}
