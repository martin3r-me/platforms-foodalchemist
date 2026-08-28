<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeReviseService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\RecipeStepService;

/**
 * MCP-Steuerbarkeit · D2c: Basisrezept per Freitext-Anweisung überarbeiten (grounded, `recipe.ueberarbeiten`).
 *
 * Draft-Quarantäne: nur stub/draft (approved/review bleiben gesperrt — Änderungen an gepflegten
 * Rezepten laufen über den Editor). accept=false (Default) liefert nur den Vorschlag (Vorschau, GL-07);
 * accept=true übernimmt ihn über dieselben Services wie der Editor (Zutaten-Sync + Text mit Lineage ki,
 * Override-First). Das Grounding (Regelwerk Basisrezepte) hängt im geteilten RecipeReviseService.
 */
class RecipesReviseTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.REVISE';
    }

    public function getDescription(): string
    {
        return 'Überarbeitet ein team-eigenes Basisrezept (stub/draft) per Freitext-Anweisung, geerdet am '
            . 'Regelwerk Basisrezepte. accept=false liefert nur den Vorschlag; accept=true übernimmt ihn '
            . '(Zutaten + Beschreibung/Zubereitung mit KI-Lineage, manuell gepflegte Felder bleiben).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Basisrezept-Id (team-eigen, stub/draft).'],
                'anweisung' => ['type' => 'string', 'description' => 'Freitext-Anweisung (z.B. „mach es vegan, halbiere den Zucker").'],
                'accept' => ['type' => 'boolean', 'description' => 'true übernimmt den Vorschlag; sonst nur Vorschau.'],
            ],
            'required' => ['recipe_id', 'anweisung'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $anweisung = trim((string) ($arguments['anweisung'] ?? ''));
        if ($anweisung === '') {
            return ToolResult::error('anweisung ist Pflicht.', 'VALIDATION_ERROR');
        }

        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', false)
            ->whereKey((int) ($arguments['recipe_id'] ?? 0))->first();
        if ($recipe === null) {
            return ToolResult::error('Basisrezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $recipe->isOwnedBy($team)) {
            return ToolResult::error('Geerbtes Rezept — Überarbeitung nur durchs Besitzer-Team.', 'ACCESS_DENIED');
        }
        if (($gesperrt = $this->kiEditGesperrt($recipe)) !== null) {
            return ToolResult::error($gesperrt, 'ACCESS_DENIED');   // Draft-Quarantäne
        }

        try {
            $roh = app(RecipeReviseService::class)->freitextVorschlag($team, $recipe, $anweisung);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
        $werte = $roh['werte'];

        $leer = empty($werte['zutaten']) && empty($werte['preparation']) && empty($werte['description']);
        if ($leer) {
            return ToolResult::success([
                'recipe_id' => (int) $recipe->id,
                'revision' => null,
                'hinweis' => 'KI lieferte keine verwertbare Überarbeitung (evtl. FakeProvider-Grenze).',
            ]);
        }

        if (($arguments['accept'] ?? false) !== true) {
            return ToolResult::success([
                'recipe_id' => (int) $recipe->id,
                'accepted' => false,
                'revision' => ['werte' => $werte, 'confidence' => $roh['confidence']],
            ]);
        }

        // accept=true: der EINE Schreib-Moment — dieselben Services wie der Editor (GL-07-Lineage).
        $applied = [];
        try {
            if (! empty($werte['zutaten']) && is_array($werte['zutaten'])) {
                $zeilen = app(RecipeReviseService::class)->syncZeilen($recipe, $werte['zutaten']);
                if ($zeilen !== []) {
                    app(RecipeService::class)->syncIngredients($team, (int) $recipe->id, $zeilen);
                    $applied[] = 'zutaten';
                }
            }
            $frisch = $recipe->fresh();
            if (is_string($werte['description'] ?? null) && trim($werte['description']) !== '' && $frisch->description_source !== 'manual') {
                $frisch->update(['description' => $werte['description'], 'description_source' => 'ki', 'description_ai_confidence' => $roh['confidence']]);
                $applied[] = 'description';
            }
            if (is_string($werte['preparation'] ?? null) && trim($werte['preparation']) !== '' && $frisch->preparation_source !== 'manual') {
                $frisch->update(['preparation_source' => 'ki', 'preparation_ai_confidence' => $roh['confidence']]);
                app(RecipeStepService::class)->ausMarkdown($frisch, $werte['preparation'], ueberschreiben: true);
                $applied[] = 'preparation';
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'recipe_id' => (int) $recipe->id,
            'accepted' => true,
            'applied' => $applied,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'basisrezept', 'revise', 'ueberarbeiten', 'ki', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipes.GET', 'foodalchemist.recipe_ingredients.PUT'],
            'examples' => ['Überarbeite Rezept 12: mach es vegan (nur Vorschau).', 'Übernimm die Überarbeitung von Rezept 12 (accept=true).'],
        ];
    }
}
