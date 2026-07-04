<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * Phase A: Rezept-Update aus dem LLM-Pfad — NUR stub/draft (Draft-Quarantäne).
 * Einzige erlaubte Status-Transition: draft → review (»fertig, bitte Review«).
 * approved setzt nur ein Mensch im Editor.
 */
class RecipesPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.PUT';
    }

    public function getDescription(): string
    {
        return 'Aktualisiert ein Rezept im Status stub/draft (Felder: name, description, preparation, '
            . 'taste_direction, work_time_min, yield_kg_manual, category_id). status="review" reicht '
            . 'den Entwurf zum menschlichen Review ein (einzige erlaubte Transition). Gepflegte Rezepte '
            . '(review/approved/archived) sind für den MCP-Pfad locked.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'preparation' => ['type' => 'string'],
                'taste_direction' => ['type' => 'string'],
                'work_time_min' => ['type' => 'integer'],
                'yield_kg_manual' => ['type' => 'number'],
                'category_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['review'], 'description' => 'Nur draft→review erlaubt'],
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
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) $arguments['recipe_id'])->first();
        if ($recipe === null) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (($sperre = $this->kiEditGesperrt($recipe)) !== null) {
            return ToolResult::error($sperre, 'ACCESS_DENIED');
        }

        $in = array_intersect_key($arguments, array_flip([
            'name', 'description', 'preparation', 'taste_direction',
            'work_time_min', 'yield_kg_manual', 'category_id',
        ]));
        if (($arguments['status'] ?? null) === 'review') {
            $in['status'] = 'review';
        }

        try {
            $recipe = app(RecipeService::class)->update($team, $recipe->id, $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'recipe' => [
                'id' => $recipe->id, 'name' => $recipe->name, 'status' => $this->statusWert($recipe),
                'version' => $recipe->version, 'yield_kg' => $recipe->yield_kg,
            ],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'recipe', 'update', 'draft', 'review'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['updates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.recipes.POST', 'foodalchemist.recipe_ingredients.PUT'],
            'examples' => ['Ergänze die Zubereitung von Rezept 4711', 'Reiche Entwurf 4711 zum Review ein'],
        ];
    }
}
