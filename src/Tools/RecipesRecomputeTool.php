<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;

/**
 * MCP-Steuerbarkeit · D2: Aggregation eines team-eigenen Basisrezepts neu berechnen
 * (Yield/Allergene/Zusatzstoffe/EK) + Propagation an nutzende Eltern-Rezepte. Idempotent.
 */
class RecipesRecomputeTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.RECOMPUTE';
    }

    public function getDescription(): string
    {
        return 'Berechnet die Aggregation eines team-eigenen Basisrezepts neu (Yield/Allergene/EK) und '
            . 'propagiert an Eltern-Rezepte. Idempotent.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'description' => 'Basisrezept-Id (team-eigen).']],
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
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', false)->whereKey($id)->first();
        if ($recipe === null) {
            return ToolResult::error('Basisrezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $recipe->isOwnedBy($team)) {
            return ToolResult::error('Geerbtes Rezept — Neuberechnung nur durchs Besitzer-Team.', 'ACCESS_DENIED');
        }

        // Rückgabe = Liste betroffener recipe_ids (Start-Menge + transitive Eltern).
        $betroffen = app(RecipeRecomputeService::class)->recomputeAndPropagate($id);

        return ToolResult::success([
            'id' => $id,
            'recomputed' => true,
            'betroffene_rezepte' => count($betroffen),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'basisrezept', 'recompute', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipes.GET'],
            'examples' => ['Berechne Basisrezept 12 neu.'],
        ];
    }
}
