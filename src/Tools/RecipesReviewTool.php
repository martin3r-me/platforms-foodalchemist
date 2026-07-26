<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeReviewService;

/**
 * 03·L6: der Copilot-Prüfpass über MCP — **read-only**. Er liefert dieselben
 * geprüften Befunde wie die UI-Fläche (gleicher `RecipeReviewService`, kein
 * zweiter Pfad), aber er wendet nichts an: die Übernahme bleibt ein bewusster
 * menschlicher Klick bzw. ein expliziter Schreib-Call.
 *
 * Warum das keine Bequemlichkeits-Grenze ist: `auto_applicable` ist eine
 * MASCHINEN-Einschätzung („anwendbar wäre es"), keine Freigabe. Ein Client, der
 * einen Befund umsetzen will, nimmt die mitgelieferten Felder und ruft den
 * passenden Schreib-Weg (`recipe_ingredients.PUT`) — damit steht die Änderung
 * in der Schreib-Kaskade mit ihrer Draft-Quarantäne, nicht neben ihr.
 */
class RecipesReviewTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.REVIEW';
    }

    public function getDescription(): string
    {
        return 'Prüft ein Rezept oder Gericht als Sous-Chef/Copilot und liefert konkrete BEFUNDE (read-only, ändert nichts). '
            . 'Je Befund: art (menge | einheit | entfernen | fehlt | hinweis), Zielzeile, Vorschlagswert, Begründung, Konfidenz '
            . 'und auto_applicable. Basisrezepte werden auf Mengenverhältnisse, Einheiten, fehlende Schlüsselkomponenten '
            . '(Säure/Salz/Fett/Bindung) und Überflüssiges geprüft; Gerichte (is_sales_recipe) zusätzlich auf Portionierung '
            . 'gegen die Speisen-Klasse, Teller-Logik, Service-Tauglichkeit und Wording. '
            . 'WICHTIG: ein "fehlt"-Befund ist nur dann auto_applicable, wenn die Zutat im Bestand (GP oder Basisrezept) '
            . 'gefunden wurde — ohne Treffer steht der Hard-Stop-Hinweis (gp_anlegen / basisrezept_anlegen) drin und es wird '
            . 'NICHTS geraten. Umsetzen dann mit foodalchemist.recipe_ingredients.PUT. Braucht einen LLM-Provider.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'ID des Rezepts oder Gerichts. Der VK-Zweig wird automatisch gewählt (is_sales_recipe)'],
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
        $recipeId = (int) ($arguments['recipe_id'] ?? 0);
        if ($recipeId <= 0) {
            return ToolResult::error('recipe_id ist Pflicht.', 'VALIDATION_ERROR');
        }
        // Tenancy: Sichtbarkeit der Team-Kette (Read) — #504-Muster
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->find($recipeId);
        if ($recipe === null) {
            return ToolResult::error("Rezept {$recipeId} nicht gefunden oder nicht sichtbar.", 'NOT_FOUND');
        }

        try {
            $ergebnis = app(RecipeReviewService::class)->pruefe($team, $recipeId);
        } catch (\Platform\FoodAlchemist\Exceptions\KiDeaktiviertException) {
            return ToolResult::error('KI ist für dieses Team deaktiviert — der Prüfpass braucht sie.', 'KI_DEAKTIVIERT');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        $anwendbar = count(array_filter($ergebnis['befunde'], fn (array $b) => $b['auto_applicable']));
        $hardstop = count(array_filter($ergebnis['befunde'], fn (array $b) => $b['status'] === 'kein_treffer'));

        return ToolResult::success([
            'recipe' => ['id' => $recipe->id, 'name' => $recipe->name, 'is_sales_recipe' => (bool) $recipe->is_sales_recipe],
            'gesamturteil' => $ergebnis['gesamturteil'],
            'confidence' => $ergebnis['confidence'],
            'befunde' => $ergebnis['befunde'],
            'zusammenfassung' => [
                'gesamt' => count($ergebnis['befunde']),
                'anwendbar' => $anwendbar,
                'hardstop' => $hardstop,
            ],
            'hinweis' => ($hardstop > 0
                    ? "⚠ {$hardstop} Befund(e) verweisen auf Zutaten, die es im Bestand nicht gibt — bewusst NICHT geraten. "
                        . 'Erst GP/Basisrezept anlegen (foodalchemist.gp_proposals.POST), dann nachziehen. '
                    : '')
                . 'Read-only: hier wurde nichts geändert. Übernahme je Befund über foodalchemist.recipe_ingredients.PUT oder im Editor.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'analysis',
            'tags' => ['foodalchemist', 'rezept', 'recipe', 'copilot', 'review', 'qualitaet', 'ki'],
            'read_only' => true,
            'idempotent' => false,                                    // LLM-Call: gleiche Eingabe, nicht garantiert gleiche Befunde
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => [],
            'cost_class' => 'llm_call',
            'related_tools' => ['foodalchemist.recipe_ingredients.PUT', 'foodalchemist.recipes.GET', 'foodalchemist.gps.MATCH', 'foodalchemist.recipes.GENERATE'],
            'examples' => [
                'Prüfe Rezept 412 auf Plausibilität und zeig mir die Befunde',
                'Was ist am Gericht 980 verkaufs-technisch schief?',
            ],
        ];
    }
}
