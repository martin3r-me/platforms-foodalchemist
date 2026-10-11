<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * Zutaten-Schreibweg für stub/draft: Voll-Sync oder schmale Garverlust-Korrektur.
 */
class RecipeIngredientsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_ingredients.PUT';
    }

    public function getDescription(): string
    {
        return 'Pflegt Zutaten eines stub/draft-Rezepts. Für einen Garverlust ausschließlich `garverluste` '
            . 'mit ingredient_id + cooking_loss_pct senden; das ändert keine anderen Zutatenfelder. Alternativ '
            . 'ersetzt `zutaten` die KOMPLETTE Zutatenliste (Voll-Sync: Reihenfolge = '
            . 'Array-Reihenfolge, fehlende Zeilen werden gelöscht). Pro Zeile: name + quantity + unit '
            . '(Slug wie g/kg/ml/stk) + gp_id ODER referenced_recipe_id (XOR; via foodalchemist.gps.MATCH erden). '
            . 'Bestehende Zeilen mit id aus recipes.GET senden: ausgelassene optionale Felder (z.B. cooking_loss_pct) bleiben erhalten. '
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
                            'id' => ['type' => 'integer', 'description' => 'Bestehende Zutaten-ID aus recipes.GET. Weggelassene optionale Felder bleiben bei dieser ID erhalten; null löscht sie. Ohne ID wird eine neue Zeile angelegt.'],
                            'name' => ['type' => 'string'],
                            'gp_id' => ['type' => 'integer'],
                            'referenced_recipe_id' => ['type' => 'integer'],
                            'quantity' => ['type' => 'number'],
                            'quantity_max' => ['type' => 'number'],
                            'unit' => ['type' => 'string'],
                            'trimming_loss_pct' => ['type' => 'number'],
                            'cooking_loss_pct' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 100],
                            'is_optional' => ['type' => 'boolean'],
                            'note' => ['type' => 'string'],
                            'role' => ['type' => 'string'],
                        ],
                        'required' => ['name', 'quantity', 'unit'],
                    ],
                ],
                'garverluste' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'description' => 'Teilaktualisierung: nur Garverlust vorhandener Zutaten ändern; null löscht den Wert.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'ingredient_id' => ['type' => 'integer'],
                            'cooking_loss_pct' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 100],
                        ],
                        'required' => ['ingredient_id', 'cooking_loss_pct'],
                    ],
                ],
            ],
            'required' => ['recipe_id'],
            'oneOf' => [
                ['required' => ['zutaten']],
                ['required' => ['garverluste']],
            ],
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

        $hatZutaten = array_key_exists('zutaten', $arguments);
        $hatVerluste = array_key_exists('garverluste', $arguments);
        if ($hatZutaten === $hatVerluste) {
            return ToolResult::error('Genau eines von zutaten oder garverluste ist Pflicht.', 'VALIDATION_ERROR');
        }

        $validator = \Illuminate\Support\Facades\Validator::make($arguments, [
            'zutaten' => 'sometimes|array|min:1',
            'zutaten.*.id' => 'sometimes|integer|min:1|distinct',
            'zutaten.*.name' => 'required|string',
            'zutaten.*.quantity' => 'required|numeric|gt:0',
            'zutaten.*.unit' => 'required|string',
            'zutaten.*.cooking_loss_pct' => 'nullable|numeric|between:0,100',
            'garverluste' => 'sometimes|array|min:1',
            'garverluste.*.ingredient_id' => 'required_with:garverluste|integer|min:1|distinct',
            'garverluste.*.cooking_loss_pct' => 'present|nullable|numeric|between:0,100',
        ]);
        if ($validator->fails()) {
            return ToolResult::error($validator->errors()->first(), 'VALIDATION_ERROR');
        }

        try {
            $recipe = $hatVerluste
                ? app(RecipeService::class)->updateCookingLosses($team, $recipe->id, $arguments['garverluste'])
                : app(RecipeService::class)->syncIngredients(
                    $team, $recipe->id, $this->normalisiereZutatZeilen($team, $arguments['zutaten']), preserveMissing: true,
                );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success($this->mitReife([
            'recipe' => [
                'id' => $recipe->id, 'name' => $recipe->name, 'status' => $this->statusWert($recipe),
                'yield_kg' => $recipe->yield_kg, 'ek_total_eur' => $recipe->ek_total_eur,
                'n_ingredients_total' => $recipe->n_ingredients_total, 'n_ingredients_unmapped' => $recipe->n_ingredients_unmapped,
            ],
        ], $team, (int) $recipe->id, (bool) $recipe->is_sales_recipe));
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
            'examples' => [
                'Setze die Zutatenliste von Entwurf 4711 auf diese 6 Positionen',
                'Setze bei Zutaten-ID 812 den Garverlust auf 12 Prozent',
            ],
        ];
    }
}
