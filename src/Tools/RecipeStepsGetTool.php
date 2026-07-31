<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;

/**
 * Spec 27: die Zubereitung als strukturierte Schrittfolge lesen (Master-Sicht).
 * `recipes.GET` liefert die Zubereitung gar nicht, `recipes.PUT` nur den
 * Markdown-Spiegel — hier kommen Nummer, Abschnitt und die verknüpften Fotos.
 */
class RecipeStepsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_steps.GET';
    }

    public function getDescription(): string
    {
        return 'Liest die Zubereitung eines Rezepts als Schrittfolge: position (1-basiert, ergibt die '
            . 'Nummer), phase (Abschnitt wie „Mise en Place" oder null), text und die am Schritt '
            . 'verknüpften Fotos. Die Schritte sind der Master — das Markdown-Feld `preparation` ist '
            . 'nur ihr gerenderter Spiegel.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer'],
            ],
            'required' => ['recipe_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) ($arguments['recipe_id'] ?? 0))->first();
        if ($recipe === null) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        $steps = FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)
            ->with('photos')->orderBy('position')->orderBy('id')->get();

        return ToolResult::success([
            'recipe' => ['id' => $recipe->id, 'name' => $recipe->name],
            'n_steps' => $steps->count(),
            'steps' => $steps->map(fn (FoodAlchemistRecipeStep $s) => [
                'id' => $s->id,
                'position' => (int) $s->position,
                'phase' => $s->phase,
                'text' => $s->text,
                'photos' => $s->photos->map(fn ($f) => [
                    'id' => $f->id, 'url' => $f->url(), 'caption' => $f->caption,
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'rezept', 'recipe', 'zubereitung', 'steps', 'anleitung'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.recipe_steps.PUT', 'foodalchemist.recipes.GET'],
            'examples' => ['Zeig mir die Arbeitsschritte von Rezept 4711'],
        ];
    }
}
