<?php

namespace Platform\FoodAlchemist\Tools;

/** VK-Gerichte haben eine eigene Schrittfolge und dürfen nie durch den Basisrezept-Endpunkt laufen. */
class VerkaufsrezepteEnrichTool extends RecipesEnrichTool
{
    public function getName(): string
    {
        return 'foodalchemist.verkaufsrezepte.ENRICH';
    }

    public function getDescription(): string
    {
        return 'Reichert ein BESTEHENDES Verkaufsgericht an. Füllt die VK-Schrittfolge '
            . '(Beschreibung, Wording, Plating, Speisen-Klasse, Geschmack, Servier-Vehikel und Zutaten-Rollen). '
            . 'Basisrezepte laufen getrennt über foodalchemist.recipes.ENRICH. Läuft asynchron; die Antwort '
            . 'liefert eine cascade_run_id für foodalchemist.planung_kaskade.GET.';
    }

    public function getSchema(): array
    {
        $schema = parent::getSchema();
        $schema['properties']['recipe_id']['description'] = 'ID eines team-eigenen Verkaufsgerichts (is_sales_recipe=true).';

        return $schema;
    }

    protected function salesRecipeExpected(): bool
    {
        return true;
    }

    protected function wrongTypeMessage(): string
    {
        return 'Die ID gehört zu einem Basisrezept. Dafür foodalchemist.recipes.ENRICH verwenden.';
    }

    public function getMetadata(): array
    {
        return array_replace(parent::getMetadata(), [
            'tags' => ['foodalchemist', 'verkaufsrezept', 'gericht', 'anreicherung', 'enrich', 'ki'],
            'related_tools' => [
                'foodalchemist.recipes.REIFE', 'foodalchemist.planung_kaskade.GET',
                'foodalchemist.verkaufsrezepte.GET', 'foodalchemist.verkaufsrezepte.PUT',
            ],
            'examples' => ['Reichere Verkaufsgericht 1373 vollständig an'],
        ]);
    }
}
