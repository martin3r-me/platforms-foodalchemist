<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\RecipeStatus;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/**
 * #504: Vollständige, seiten-basierte Auflistung der Verkaufsrezepte (Gerichte) des
 * Teams — ergänzt verkaufsrezepte.SEARCH (Suchbegriff Pflicht, Cap 50). page/per_page-Paging
 * fürs komplette Enumerieren des Gerichte-Katalogs.
 */
class VerkaufsrezepteListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.verkaufsrezepte.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die Verkaufsrezepte (Gerichte) des Teams vollständig und seitenweise auf (ohne 50er-Cap), mit denselben Filtern wie der '
            . 'Gerichte-Browser: q (Name, Wording, Marketing- und Kundennamen; jedes Wort muss treffen), hauptgruppe, klasse, status, geschmack — kombinierbar. '
            . 'page/per_page-Paging (last_page zum Weiterblättern). Liefert je Gericht id, name, status, sales_net, ek_total_eur, speisen_klasse, '
            . 'dish_class_id, dish_main_group_id, geschmack, komponenten_anzahl, deklaration (Allergen-Konfidenz), presentations (Darreichungsformen). '
            . 'Semantische Suche via foodalchemist.verkaufsrezepte.SEARCH. Unbekannte Parameter stehen in warnungen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Suchtext in Name, Standard-Wording, Marketing-Text und Kundennamen (jedes Wort muss treffen), wie die Suche im Gerichte-Browser.'],
                'hauptgruppe' => ['type' => 'integer', 'description' => 'Speisen-Hauptgruppe (dish_main_group_id, siehe foodalchemist.dish_classes.GET).'],
                'klasse' => ['type' => 'integer', 'description' => 'Speisen-Klasse / Diätform (dish_class_id, siehe foodalchemist.dish_classes.GET).'],
                'status' => ['type' => 'string', 'enum' => [...array_column(RecipeStatus::cases(), 'value'), ''], 'description' => 'Status-Filter. Leer = alle.'],
                'geschmack' => ['type' => 'string', 'enum' => ['suess', 'herzhaft', 'neutral', ''], 'description' => 'Geschmacksrichtung.'],
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
        $treffer = app(SalesRecipeService::class)->paginateBrowser(['search' => trim((string) ($arguments['q'] ?? '')),
            'hauptgruppe' => $arguments['hauptgruppe'] ?? null, 'class' => $arguments['klasse'] ?? null,
            'status' => (string) ($arguments['status'] ?? ''), 'geschmack' => (string) ($arguments['geschmack'] ?? '')], $team, $perPage);

        return $this->erfolgMitWarnungen([
            'total' => $treffer->total(),
            'page' => $treffer->currentPage(),
            'last_page' => $treffer->lastPage(),
            'per_page' => $treffer->perPage(),
            'verkaufsrezepte' => collect($treffer->items())->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'status' => $r->status->value, 'sales_net' => $r->vk_netto,   // Spec 85 D-1: Standard-Darreichung
                'ek_total_eur' => $r->ek_total_eur,
                'speisen_klasse' => $r->dishClass?->label,
                'dish_main_group_id' => $r->dish_main_group_id !== null ? (int) $r->dish_main_group_id : null,   // Taxonomie-Neutralisierung
                'dish_class_id' => $r->dish_class_id !== null ? (int) $r->dish_class_id : null,
                'geschmack' => $r->taste_direction, 'komponenten_anzahl' => $r->n_ingredients_total, 'deklaration' => $r->allergens_confidence,   // Spec 91 D18
                'presentations' => $this->darreichungenSummary($r),
            ])->all(),
        ], $arguments);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'verkaufsrezept', 'gericht', 'list', 'katalog', 'paging'],
            'related_tools' => ['foodalchemist.verkaufsrezepte.SEARCH'],
            'examples' => ['Liste alle Gerichte auf', 'Zeig mir den kompletten Verkaufsrezept-Katalog seitenweise'],
        ];
    }
}
