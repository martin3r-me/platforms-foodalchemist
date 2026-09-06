<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ReifeService;

/**
 * Spec 50 · Schicht 4 — „Was fehlt an diesem Rezept noch?" **Read-only.**
 *
 * Der Server weiss längst, was an einem Artefakt offen ist: die Zielfelder der
 * Anreicherungs-Schrittfolge, die VK-Vorbedingungen, die Datenqualitäts-Ampel, die
 * Satelliten-Tabellen. Er hat es nur nie ausgesprochen — ein Agent, der per MCP ein Gericht
 * anlegte, musste die Befunde selbst zusammensuchen und merkte den fehlenden Lohn (FEK = 0)
 * erst im Review. Dieses Tool spricht es aus.
 *
 * Kein Provider-Call, keine Schreibwirkung.
 */
class RecipeReifeGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.REIFE';
    }

    public function getDescription(): string
    {
        return 'Vollständigkeit eines Basisrezepts oder Verkaufsgerichts: was ist offen, wie schwer wiegt es, '
            . 'und mit welchem Tool schliesst man es. Liefert `luecken` (je mit schwere blockiert|wichtig|hinweis '
            . 'und dem Weg), `erfuellt`, `nicht_messbar` (ehrliche Degradation statt geratener Aussage), '
            . '`kennzahlen` (VK, Wareneinsatz, Food-Cost-Ampel, Herkunft der Aufschlagsklasse) und '
            . '`naechste_schritte`. Ampel: rot = etwas blockiert (z. B. keine Darreichung ⇒ kein VK), '
            . 'gelb = Wichtiges offen, grün = nichts Gemessenes offen. Read-only, kein KI-Call. '
            . 'Ergänzt die Konformitätsprüfung (die fragt, ob das Vorhandene RICHTIG ist) um die Frage, '
            . 'ob überhaupt alles DA ist.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept- oder Gericht-Id (team-sichtbar). Die Ebene wird selbst erkannt.'],
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

        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->find($id);
        if ($recipe === null) {
            return ToolResult::error("Rezept {$id} nicht gefunden oder für dieses Team nicht sichtbar.", 'NOT_FOUND');
        }

        $kind = $recipe->is_sales_recipe ? 'sales_recipe' : 'recipe';
        $reife = app(ReifeService::class)->reife($team, $kind, $id);

        return ToolResult::success($reife + [
            'hinweis' => $reife['ampel'] === 'gruen'
                ? 'Nichts Gemessenes offen — das heisst nicht „freigabereif": die Freigabe bleibt menschlich.'
                : 'Offene Punkte in `luecken`; `naechste_schritte` nennt je einen Weg. `nicht_messbar` sind Punkte, '
                    . 'die ohne Vorbedingung keine ehrliche Aussage zulassen.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'reife', 'vollstaendigkeit', 'luecken', 'rezept', 'gericht', 'qualitaet'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => [
                'foodalchemist.recipes.POST', 'foodalchemist.verkaufsrezepte.PUT',
                'foodalchemist.recipe_darreichung.PUT', 'foodalchemist.kalkulation.GET',
            ],
            'examples' => [
                'Was fehlt an Gericht 1373 noch?',
                'Ist Basisrezept 812 vollständig — und wenn nicht, was blockiert den Preis?',
            ],
        ];
    }
}
