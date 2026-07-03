<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * Phase A (Weg-A-Ausnahme 2026-07-03): Rezept-Anlage aus dem LLM-Pfad —
 * IMMER status=draft + created_via=mcp (Draft-Quarantäne). Optional mit
 * Zutaten in einem Call; Aggregate (Yield/Allergene/EK) rechnet der
 * RecipeRecomputeService automatisch.
 */
class RecipesPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein neues Rezept als ENTWURF an (status=draft, created_via=mcp — nie automatisch aktiv). '
            . 'Zutaten optional direkt mit: pro Zeile name + menge + einheit (Slug, z. B. g/kg/ml/stk) und '
            . 'gp_id ODER referenced_recipe_id (XOR; vorher via foodalchemist.gps.MATCH erden — ungematcht nur als Ausnahme). '
            . 'Yield/Allergene/EK werden automatisch aggregiert. Freigabe (approved) macht nur ein Mensch im Editor.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Rezept-Name nach Basisrezept-Regelwerk §1'],
                'kategorie_id' => ['type' => 'integer'],
                'ist_verkaufsrezept' => ['type' => 'boolean', 'default' => false],
                'beschreibung' => ['type' => 'string'],
                'zubereitung' => ['type' => 'string', 'description' => 'Zubereitungs-Schritte (Klartext)'],
                'geschmacksrichtung' => ['type' => 'string'],
                'arbeitszeit_min' => ['type' => 'integer'],
                'yield_kg_manual' => ['type' => 'number', 'description' => 'Nur wenn Auto-Summe nicht passt'],
                'herkunft' => ['type' => 'string', 'description' => 'Menschenlesbare Provenienz, Default "MCP/KI"'],
                'zutaten' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'gp_id' => ['type' => 'integer', 'description' => 'GP aus foodalchemist.gps.MATCH'],
                            'referenced_recipe_id' => ['type' => 'integer', 'description' => 'Sub-Rezept statt GP (XOR)'],
                            'menge' => ['type' => 'number'],
                            'einheit' => ['type' => 'string', 'description' => 'Einheiten-Slug oder -Name, z. B. g, kg, ml, stk'],
                            'putzverlust_pct' => ['type' => 'number'],
                            'garverlust_pct' => ['type' => 'number'],
                            'is_optional' => ['type' => 'boolean'],
                            'note' => ['type' => 'string'],
                        ],
                        'required' => ['name', 'menge', 'einheit'],
                    ],
                ],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(RecipeService::class);

        try {
            $recipe = $svc->create($team, [
                'name' => (string) $arguments['name'],
                'kategorie_id' => $arguments['kategorie_id'] ?? null,
                'ist_verkaufsrezept' => (bool) ($arguments['ist_verkaufsrezept'] ?? false),
                'beschreibung' => $arguments['beschreibung'] ?? null,
                'geschmacksrichtung' => $arguments['geschmacksrichtung'] ?? null,
                'arbeitszeit_min' => $arguments['arbeitszeit_min'] ?? null,
                'yield_kg_manual' => $arguments['yield_kg_manual'] ?? null,
                'herkunft' => ($arguments['herkunft'] ?? '') ?: 'MCP/KI',
                'created_via' => 'mcp',
            ]);
            if (($arguments['zubereitung'] ?? '') !== '') {
                $recipe = $svc->update($team, $recipe->id, ['zubereitung' => $arguments['zubereitung']]);
            }
            if (! empty($arguments['zutaten'])) {
                $recipe = $svc->syncIngredients($team, $recipe->id, $this->normalisiereZutatZeilen($team, $arguments['zutaten']));
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'recipe' => [
                'id' => $recipe->id, 'name' => $recipe->name, 'recipe_key' => $recipe->recipe_key,
                'status' => $this->statusWert($recipe), 'created_via' => $recipe->created_via,
                'yield_kg' => $recipe->yield_kg, 'ek_total_eur' => $recipe->ek_total_eur,
                'n_zutaten_total' => $recipe->n_zutaten_total, 'n_zutaten_ungemappt' => $recipe->n_zutaten_ungemappt,
            ],
            'hinweis' => 'Entwurf (Draft-Quarantäne): fließt erst nach menschlichem Review in Verkauf/Kalkulation.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'recipe', 'anlegen', 'draft', 'ki'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.gps.MATCH', 'foodalchemist.recipe_ingredients.PUT', 'foodalchemist.recipes.PUT'],
            'examples' => ['Lege ein Rezept "Kürbis-Ingwer-Suppe" mit 5 Zutaten als Entwurf an'],
        ];
    }
}
