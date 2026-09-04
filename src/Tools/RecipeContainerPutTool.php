<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/**
 * Spec 51 · MCP-Steuerbarkeit: Behälter je ZWECK an einem team-eigenen Rezept setzen.
 *
 * Gilt für JEDES Rezept: das Basisrezept trägt hier seinen Default, das Gericht seinen Override.
 * Ein Zweck kommt genau einmal je Rezept vor — erneutes PUT aktualisiert.
 */
class RecipeContainerPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_container.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt den Behälter für einen Zweck an einem Rezept (abfuellen|regenerieren|ausgabe|transport). '
            . 'felder: container_vocab_id (Pflicht), referenz_menge_kg (»so viel passt in GENAU diesen Behälter« — '
            . 'am grössten praktikablen angeben), skalierung (tiefer_fuellbar|hoehe_gebunden|lagenware), '
            . 'max_schichthoehe_mm, stueck_je_behaelter (nur Lagenware), note. '
            . 'Die ANZAHL wird NICHT gesetzt — sie rechnet die Produktion aus der produzierten Menge.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept-Id (team-eigen) — Basisrezept ODER Gericht.'],
                'zweck' => ['type' => 'string', 'enum' => FoodAlchemistVocabContainer::ZWECKE,
                    'description' => 'abfuellen (nach der Produktion) | regenerieren (Einsatztag) | ausgabe (Pass) | transport (Träger).'],
                'felder' => ['type' => 'object', 'description' => 'container_vocab_id, referenz_menge_kg, skalierung, max_schichthoehe_mm, stueck_je_behaelter, note.'],
            ],
            'required' => ['recipe_id', 'zweck', 'felder'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $zweck = (string) ($arguments['zweck'] ?? '');
        if (! in_array($zweck, FoodAlchemistVocabContainer::ZWECKE, true)) {
            return ToolResult::error('zweck muss abfuellen|regenerieren|ausgabe|transport sein.', 'VALIDATION_ERROR');
        }
        $felder = $arguments['felder'] ?? null;
        if (! is_array($felder) || $felder === []) {
            return ToolResult::error('felder muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }

        $recipeId = (int) ($arguments['recipe_id'] ?? 0);
        if (($guard = $this->guardRecipe($team, $recipeId)) !== null) {
            return $guard;
        }

        try {
            app(SalesRecipeService::class)->upsertContainer($team, $recipeId, $zweck, $felder);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['recipe_id' => $recipeId, 'zweck' => $zweck, 'upserted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'behaelter', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipe_container.GET', 'foodalchemist.recipe_container.DELETE',
                'foodalchemist.behaelter_bedarf.GET'],
            'examples' => ['Setze am Basisrezept 812 für den Zweck abfuellen den Eimer 10 l, Referenzmenge 9 kg.'],
        ];
    }
}
