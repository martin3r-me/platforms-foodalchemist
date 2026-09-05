<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\ReifeService;

/**
 * Spec 50 · E-1 — Anreicherung für ein BESTEHENDES Rezept. Die Wurzel aus §4.1.
 *
 * Bis 2026-09-05 war der Anreicherungs-Pass für den Alltags-Weg unerreichbar:
 * `RecipeOneShotService::anreichern()` läuft nur aus `recipes.GENERATE` (also nur für frisch
 * GENERIERTE Rezepte), und `PlanningCascadeService::enrichBestehendesRezept()` — die Methode
 * mit genau der richtigen Semantik — hatte als einzigen Aufrufer eine Livewire-Klasse.
 * Ein per MCP von Hand angelegtes Rezept konnte deshalb überhaupt nicht angereichert werden.
 *
 * Folge, gemessen auf demo (Spec 50 §9): 939 von 950 Gerichten ohne VK-Wording — obwohl
 * `wording` in der Schrittfolge steht. Und 912 ohne `work_time_min`, weshalb die Kalkulation
 * dort FEK und FGK als 0 rechnet.
 *
 * Kein neuer Fachpfad: derselbe Service, den der Editor benutzt.
 */
class RecipesEnrichTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.ENRICH';
    }

    public function getDescription(): string
    {
        return 'Reichert ein BESTEHENDES Basisrezept oder Verkaufsgericht an (der Weg, den es bisher nur im Editor gab): '
            . 'füllt die leeren Zielfelder der Schrittfolge, mintet fehlende GPs, und bei einem Gericht zusätzlich '
            . 'Kohärenz-Urteil und Wirtschaftlichkeit (Preisklasse, Standard-Darreichung, Auto-VK, Food-Cost-Ampel). '
            . 'Sub-Rezepte laufen mit. Läuft ASYNCHRON über den Worker — die Antwort liefert run_id und step_id, '
            . 'den Fortschritt zeigt foodalchemist.planung_kaskade.GET, das Ergebnis foodalchemist.recipes.REIFE. '
            . 'Bereits gefüllte Felder werden nie angetastet (Override-First). Kostet Provider-Calls je fehlendem Feld.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept- oder Gericht-Id (team-eigen). Die Ebene wird selbst erkannt.'],
                'complete_coverage' => ['type' => 'boolean', 'default' => true,
                    'description' => 'Volle Tiefe: zusätzlich Fertigungstiefe, Eigenschaften/Zeiten, Equipment, Posten, Prozessanker, '
                        . 'Aroma-Anker, Pairings, Eignung, Step-by-Step und Sensorik. false = nur die Textfelder (deutlich weniger Calls). '
                        . 'Hinweis: `work_time_min` — ohne das die Kalkulation FEK = 0 rechnet — kommt aus dem Eigenschaften-Glied, also nur mit true.'],
                'ki_bilder' => ['type' => 'boolean', 'default' => false,
                    'description' => 'KI-Fotos miterzeugen. Default AUS und bewusst so: Bilder sind der teuerste Teil und werden auf Bedarf angefordert.'],
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
        $id = (int) ($arguments['recipe_id'] ?? 0);
        if ($id <= 0) {
            return ToolResult::error('recipe_id ist Pflicht.', 'VALIDATION_ERROR');
        }
        if (($guard = $this->guardOwned($team, FoodAlchemistRecipe::class, $id, 'Rezept')) !== null) {
            return $guard;                                          // D1: nur eigene Rezepte anreichern
        }

        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($id);
        $vorher = app(ReifeService::class)->kurz($team, $recipe->is_sales_recipe ? 'sales_recipe' : 'recipe', $id);

        try {
            $run = app(PlanningCascadeService::class)->enrichBestehendesRezept(
                $team, $id, (bool) $recipe->is_sales_recipe, null,
                (bool) ($arguments['complete_coverage'] ?? true),
                (bool) ($arguments['ki_bilder'] ?? false),
            );
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'ENRICH_FEHLGESCHLAGEN');
        }

        return ToolResult::success([
            'run_id' => (int) $run->id,
            'step_id' => (int) ($run->steps()->value('id') ?? 0),
            'recipe' => ['id' => $recipe->id, 'name' => $recipe->name, 'ist_gericht' => (bool) $recipe->is_sales_recipe],
            // Der Ausgangszustand, damit nach dem Lauf vergleichbar ist, was der Pass bewirkt hat.
            'reife_vorher' => $vorher,
            'hinweis' => 'Läuft asynchron. Fortschritt: foodalchemist.planung_kaskade.GET (run_id). '
                . 'Danach foodalchemist.recipes.REIFE für den neuen Stand. Freigabe bleibt menschlich.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'gericht', 'anreicherung', 'enrich', 'ki'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'llm_call',
            'related_tools' => [
                'foodalchemist.recipes.REIFE', 'foodalchemist.planung_kaskade.GET',
                'foodalchemist.recipes.POST', 'foodalchemist.verkaufsrezepte.PUT',
            ],
            'examples' => [
                'Reichere Gericht 1373 vollständig an',
                'Basisrezept 812 anreichern, aber ohne Bilder und nur die Textfelder',
            ],
        ];
    }
}
