<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * Phase A: Voll-Sync der Zutatenliste aus dem LLM-Pfad — NUR stub/draft.
 * Service erzwingt XOR gp/sub, Zyklus-Check, Transaktion + GENAU EIN
 * recomputeAndPropagate (Yield/Allergene/EK inkl. Eltern-Rezepte).
 */
class RecipeIngredientsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_ingredients.PUT';
    }

    public function getDescription(): string
    {
        return 'Ersetzt die KOMPLETTE Zutatenliste eines stub/draft-Rezepts (Voll-Sync: Reihenfolge = '
            . 'Array-Reihenfolge, fehlende Zeilen werden gelöscht). Pro Zeile: name + menge + einheit '
            . '(Slug wie g/kg/ml/stk) + gp_id ODER referenced_recipe_id (XOR; via foodalchemist.gps.MATCH erden). '
            . 'Aggregate (Yield/Allergene/EK) werden automatisch neu gerechnet und in Eltern-Rezepte propagiert.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer'],
                'zutaten' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'gp_id' => ['type' => 'integer'],
                            'referenced_recipe_id' => ['type' => 'integer'],
                            'menge' => ['type' => 'number'],
                            'menge_max' => ['type' => 'number'],
                            'einheit' => ['type' => 'string'],
                            'putzverlust_pct' => ['type' => 'number'],
                            'garverlust_pct' => ['type' => 'number'],
                            'is_optional' => ['type' => 'boolean'],
                            'note' => ['type' => 'string'],
                            'rolle' => ['type' => 'string'],
                        ],
                        'required' => ['name', 'menge', 'einheit'],
                    ],
                ],
            ],
            'required' => ['recipe_id', 'zutaten'],
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

        try {
            $recipe = app(RecipeService::class)->syncIngredients(
                $team, $recipe->id, $this->normalisiereZutatZeilen($team, $arguments['zutaten']),
            );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'recipe' => [
                'id' => $recipe->id, 'name' => $recipe->name, 'status' => $this->statusWert($recipe),
                'yield_kg' => $recipe->yield_kg, 'ek_total_eur' => $recipe->ek_total_eur,
                'n_zutaten_total' => $recipe->n_zutaten_total, 'n_zutaten_ungemappt' => $recipe->n_zutaten_ungemappt,
            ],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'zutaten', 'ingredients', 'sync', 'draft'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates', 'updates', 'deletes'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.gps.MATCH', 'foodalchemist.recipes.POST'],
            'examples' => ['Setze die Zutatenliste von Entwurf 4711 auf diese 6 Positionen'],
        ];
    }
}
