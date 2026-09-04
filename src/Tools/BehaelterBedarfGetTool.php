<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer;
use Platform\FoodAlchemist\Services\BehaelterBedarfService;

/**
 * Spec 51 · MCP: den gerechneten Behälter-Bedarf abfragen, ohne einen Auftrag anzulegen.
 *
 * Das ist der Weg, die Bemessung zu PRÜFEN: Menge rein, Basis-Variante plus Alternativen raus —
 * jeweils mit Konfidenz (hoch = Referenz-Füllung gepflegt, mittel = aus der Dichteklasse
 * geschätzt) oder mit einem GRUND, warum nicht gerechnet werden kann.
 */
class BehaelterBedarfGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.behaelter_bedarf.GET';
    }

    public function getDescription(): string
    {
        return 'Rechnet den Behälter-Bedarf eines Rezepts für eine Menge: welche Behälter, wie viele, '
            . 'plus Alternativen und Konfidenz. Ohne menge_kg wird die Ausbeute (yield_kg) des Rezepts '
            . 'genommen. Antwortet mit einem Grund statt einer Zahl, wenn Referenzmenge, Dichteklasse '
            . 'oder Ausbeute fehlen — es wird nichts geraten.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept-Id (sichtbar fürs Team).'],
                'menge_kg' => ['type' => 'number', 'description' => 'Zu bemessende Menge; leer = Ausbeute des Rezepts.'],
                'zweck' => ['type' => 'string', 'enum' => FoodAlchemistVocabContainer::ZWECKE,
                    'description' => 'Nur diesen Zweck rechnen; leer = alle hinterlegten.'],
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

        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->find((int) ($arguments['recipe_id'] ?? 0));
        if ($recipe === null) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        $mengeKg = isset($arguments['menge_kg']) && $arguments['menge_kg'] !== null
            ? (float) $arguments['menge_kg']
            : ($recipe->yield_kg !== null ? (float) $recipe->yield_kg : null);

        $nurZweck = $arguments['zweck'] ?? null;
        $service = app(BehaelterBedarfService::class);

        // abfuellen kommt vom Rezept selbst; regenerieren und ausgabe laufen ueber den
        // Komponenten-Pfad — hier mit dem Rezept ALS Komponente, damit sich die Bemessung
        // pruefen laesst, ohne einen Produktionsauftrag anzulegen.
        $alle = array_filter([
            $service->abfuellen($team, $recipe, $mengeKg),
            ...$service->jeKomponente($team, [[
                'recipe' => $recipe, 'label' => $recipe->name, 'menge_kg' => (float) ($mengeKg ?? 0),
            ]]),
        ]);

        $raus = [];
        foreach ($alle as $bedarf) {
            if ($nurZweck !== null && $bedarf['zweck'] !== $nurZweck) {
                continue;
            }
            $raus[$bedarf['zweck']] = [
                'berechenbar' => $bedarf['berechenbar'],
                'grund' => $bedarf['grund'],
                'menge_kg' => $bedarf['menge_kg'],
                'varianten' => $bedarf['varianten'],
                'kurz' => BehaelterBedarfService::varianteKurz($bedarf),
            ];
        }

        return ToolResult::success([
            'recipe_id' => (int) $recipe->id,
            'name' => $recipe->name,
            'menge_kg' => $mengeKg,
            'dichteklasse' => $recipe->dichteklasse,
            'zwecke' => $raus,
            'hinweis' => $raus === []
                ? 'Für dieses Rezept ist kein Behälter hinterlegt — recipe_container.PUT setzt einen.'
                : 'transport wird nicht gerechnet: ein Träger nimmt Füllbehälter auf, er wird nicht befüllt.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'behaelter', 'produktion', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'read',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.recipe_container.PUT', 'foodalchemist.behaelter_katalog.GET'],
            'examples' => ['Wie viele Behälter brauche ich für 40 kg von Rezept 812?'],
        ];
    }
}
