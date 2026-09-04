<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/** MCP-Steuerbarkeit · D3: Regenerations-Programm eines team-eigenen Gerichts löschen. */
class RecipeRegenerationDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_regeneration.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht ein Regenerations-Programm eines team-eigenen Gerichts.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept-Id (team-eigen) — Basisrezept ODER Gericht.'],
                'id' => ['type' => 'integer', 'description' => 'Regenerations-Id.'],
            ],
            'required' => ['recipe_id', 'id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $recipeId = (int) ($arguments['recipe_id'] ?? 0);
        if (($guard = $this->guardRecipe($team, $recipeId)) !== null) {
            return $guard;
        }

        try {
            app(SalesRecipeService::class)->deleteRegeneration($team, $recipeId, (int) ($arguments['id'] ?? 0));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['recipe_id' => $recipeId, 'id' => (int) ($arguments['id'] ?? 0), 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gericht', 'regeneration', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.recipe_regeneration.PUT'],
            'examples' => ['Lösche Regenerations-Programm 7 von Gericht 501.'],
        ];
    }
}
