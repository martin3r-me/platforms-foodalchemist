<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\RecipeStatus;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * #504: Vollständige, seiten-basierte Auflistung der Basisrezepte des Teams —
 * ergänzt recipes.SEARCH (Suchbegriff Pflicht, Cap 50). page/per_page-Paging fürs
 * komplette Enumerieren. Detail via recipes.GET.
 */
class RecipesListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die Basisrezepte des Teams vollständig und seitenweise auf (ohne 50er-Cap), mit denselben Filtern wie der Rezept-Browser: '
            . 'q (Name/Schlüssel, jedes Wort muss treffen), hauptgruppe, kategorie, ohne_kategorie, status, geschmack, fertigung, nur_templates, rolle — kombinierbar. '
            . 'page/per_page-Paging (last_page zum Weiterblättern). Liefert je Rezept id, name, status, yield_kg, ek_total_eur, rollen, kategorie, hauptgruppe_id, '
            . 'geschmack, fertigung, zutaten_anzahl, zutaten_offen (ohne Produkt-Zuordnung), allergen_konfidenz. '
            . 'Detail via foodalchemist.recipes.GET, semantische Suche via foodalchemist.recipes.SEARCH. Unbekannte Parameter stehen in warnungen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Suchtext in Name und Rezept-Schlüssel (jedes Wort muss treffen), wie die Suche im Rezept-Browser.'],
                'hauptgruppe' => ['type' => 'integer', 'description' => 'Hauptgruppe (id, steht in jedem Ergebnis als hauptgruppe_id): alle Kategorien darunter.'],
                'kategorie' => ['type' => 'integer', 'description' => 'Rezept-Kategorie (id, steht in jedem Ergebnis als kategorie.id).'],
                'ohne_kategorie' => ['type' => 'boolean', 'description' => 'true = nur Basisrezepte ohne Kategorie (Arbeitsvorrat).'],
                'status' => ['type' => 'string', 'enum' => [...array_column(RecipeStatus::cases(), 'value'), ''], 'description' => 'Status-Filter. Leer = alle.'],
                'geschmack' => ['type' => 'string', 'enum' => ['suess', 'herzhaft', 'neutral', ''], 'description' => 'Geschmacksrichtung.'],
                'fertigung' => ['type' => 'string', 'enum' => ['from_scratch', 'teilfertig', 'convenience', ''], 'description' => 'Fertigungstiefe.'],
                'nur_templates' => ['type' => 'boolean', 'description' => 'true = nur Vorlagen (Templates).'],
                'rolle' => ['type' => 'string', 'description' => 'Optional: nur Basisrezepte, die diese Teller-Rolle tragen können '
                    . '(Vokabular Regel gericht.teller_rolle: basis, protein, beilage, sauce, crunch, saeure, aroma, garnitur).'],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => 'Seitennummer (last_page aus der Vorantwort).'],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100, 'description' => 'Seitengröße (max. 200).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if ($fehler = $this->enumFehler($arguments)) {
            return $fehler;
        }
        $page = max(1, (int) ($arguments['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($arguments['per_page'] ?? 100)));
        Paginator::currentPageResolver(fn () => $page);
        $treffer = app(RecipeService::class)->paginateBrowser(
            ['search' => trim((string) ($arguments['q'] ?? '')), 'status' => (string) ($arguments['status'] ?? ''),
                'hauptgruppe' => $arguments['hauptgruppe'] ?? null, 'category' => $arguments['kategorie'] ?? null,
                'ohne_kategorie' => filter_var($arguments['ohne_kategorie'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'geschmack' => (string) ($arguments['geschmack'] ?? ''), 'fertigung' => (string) ($arguments['fertigung'] ?? ''),
                'nur_templates' => filter_var($arguments['nur_templates'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'rolle' => (string) (\Platform\FoodAlchemist\Support\TellerRolle::kanonisch($arguments['rolle'] ?? null) ?? '')], $team, $perPage,
        );

        return $this->erfolgMitWarnungen([
            'total' => $treffer->total(),
            'page' => $treffer->currentPage(),
            'last_page' => $treffer->lastPage(),
            'per_page' => $treffer->perPage(),
            'recipes' => collect($treffer->items())->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'status' => $r->status->value,
                'yield_kg' => $r->yield_kg, 'ek_total_eur' => $r->ek_total_eur,
                'rollen' => app(\Platform\FoodAlchemist\Services\RecipeRollenService::class)->rollen((int) $r->id),
                // Spec 91 D18: die Spalten des Rezept-Browsers
                'kategorie' => $r->category !== null ? ['id' => $r->category->id, 'label' => $r->category->label] : null,
                'hauptgruppe_id' => $r->category?->main_group_id, 'geschmack' => $r->taste_direction, 'fertigung' => $r->production_depth,
                'zutaten_anzahl' => $r->n_ingredients_total, 'zutaten_offen' => $r->n_ingredients_unmapped, 'allergen_konfidenz' => $r->allergens_confidence,
            ])->all(),
        ], $arguments);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'rezept', 'basisrezept', 'list', 'katalog', 'paging'],
            'related_tools' => ['foodalchemist.recipes.SEARCH', 'foodalchemist.recipes.GET'],
            'examples' => ['Liste alle Basisrezepte auf', 'Zeig mir alle draft-Rezepte seitenweise'],
        ];
    }
}
