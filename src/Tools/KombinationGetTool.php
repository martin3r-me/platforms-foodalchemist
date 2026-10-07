<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Pairing\Kombinationslogik;

/**
 * Spec 60 · P7: die Kombinationslogik headless — dieselben Aussagen wie das Detail-Panel.
 * Entweder ein Rezept (Gericht: Bestandteile = Basisrezepte, mit Vorschlägen; Basisrezept:
 * Aromenprofil + Aussagen über die Zutaten) oder eine freie Anker-Auswahl (Planung).
 */
class KombinationGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.kombination.GET';
    }

    public function getDescription(): string
    {
        return 'Kombinationslogik (Spec 60): klare Aussagen, ob und wie Bestandteile zusammenpassen — je mit Grundlage '
            . '(gemessen Foodpairing / belegt Nährwerte / aus Kategorie / aus Dossier ungeprüft|geprüft). Aussage-Typen: '
            . 'harmoniert (≥ 10 % gemeinsame Aromamasse, nur 3★), passt (nur 2★, zählt nicht), neutral, spannung (ein Bedarf '
            . 'wird gedeckt, z. B. Säure für Kürbis), bedarf_offen (fehlt dem Teller), konflikt, kombination (Klassiker), '
            . 'unbekannt (ohne Aroma-Zuordnung). Mit recipe_id: ein Gericht wird über seine Basisrezepte bewertet und bekommt '
            . 'vorschlaege (erst Formwechsel einer vorhandenen Komponente, dann passende Basisrezepte); ein Basisrezept liefert '
            . 'sein Aromenprofil (Kern-Anker mit Anteil). Mit anker_ids: dieselbe Logik über eine freie Auswahl (Planung), '
            . 'optional diaet (vegan|vegetarisch) und geschmacksrichtung (herzhaft|suess) für die Vorschläge. Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Gericht oder Basisrezept'],
                'anker_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'freie Anker-Auswahl (statt recipe_id), IDs via composer.ANKER_SUCHE'],
                'diaet' => ['type' => 'string', 'enum' => ['vegan', 'vegetarisch'], 'description' => 'nur mit anker_ids'],
                'geschmacksrichtung' => ['type' => 'string', 'enum' => ['herzhaft', 'suess'], 'description' => 'nur mit anker_ids'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $logik = app(Kombinationslogik::class);
        if (isset($arguments['recipe_id'])) {
            $rezept = FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) $arguments['recipe_id'])->first();
            if ($rezept === null) {
                return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
            }

            return ToolResult::success(['recipe' => ['id' => $rezept->id, 'name' => $rezept->name]] + $logik->daten($rezept));
        }
        $ids = array_values(array_filter(array_map('intval', (array) ($arguments['anker_ids'] ?? [])), fn ($i) => $i > 0));
        if (count($ids) < 2) {
            return ToolResult::error('recipe_id oder mindestens zwei anker_ids angeben.', 'VALIDATION_ERROR');
        }

        return ToolResult::success($logik->datenAusAnkern($ids, $arguments['diaet'] ?? null, $arguments['geschmacksrichtung'] ?? null, (int) $team->id));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'pairing', 'kombination', 'harmonie', 'kontrast', 'gericht', 'basisrezept', 'planung'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.composer.ANKER_SUCHE', 'foodalchemist.recipes.SEARCH', 'foodalchemist.pairings.SUGGEST'],
            'examples' => ['Passt Gericht 2619 zusammen, was fehlt?', 'Welches Aromenprofil hat Basisrezept 384?'],
        ];
    }
}
