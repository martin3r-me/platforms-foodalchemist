<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SupplierItemService;

/**
 * #504: Vollständige, seiten-basierte Auflistung der Lieferantenartikel des Teams —
 * ergänzt artikel.SEARCH (Suchbegriff, Cap 50). page/per_page-Paging fürs komplette
 * Enumerieren des Artikel-Katalogs.
 *
 * Spec 91 PR 1: dieselben Filter wie der Lieferanten-Browser (supplier_id, q, art, only_active) über dieselben
 * Service-Wege; vorher verwarf das Tool supplier_id still und blätterte den ganzen Katalog.
 */
class ArtikelListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.artikel.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die Lieferantenartikel des Teams vollständig und seitenweise auf (ohne 50er-Cap), wie der '
            . 'Lieferanten-Browser: supplier_id = Sortiment eines Lieferanten, q = Suche (Bezeichnung oder Artikelnummer-Präfix, '
            . 'jedes Wort muss treffen), art = food|nonfood|pruefen, only_active (Standard true: ausgelistete/eingestellte fallen raus). '
            . 'page/per_page-Paging (last_page zum Weiterblättern). Liefert je Artikel id, designation, article_number, supplier_id, supplier; '
            . 'felder="voll" zusätzlich Menge/Einheit, EK (aktiver Preis), Aktion bis, Lieferstatus, GP und Lead-Kennzeichen. '
            . 'Unbekannte Parameter stehen in warnungen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => 'Seitennummer (last_page aus der Vorantwort).'],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100, 'description' => 'Seitengröße (max. 200).'],
                'supplier_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Nur Artikel dieses Lieferanten (id aus suppliers.SEARCH).'],
                'q' => ['type' => 'string', 'description' => 'Suche: Bezeichnung oder Artikelnummer-Präfix, jedes Wort muss treffen.'],
                'art' => ['type' => 'string', 'enum' => ['food', 'nonfood', 'pruefen'], 'description' => 'food = ohne entschiedenes Non-Food, nonfood = entschiedenes Non-Food, pruefen = Prüfliste.'],
                'only_active' => ['type' => 'boolean', 'default' => true, 'description' => 'false zeigt auch ausgelistete und eingestellte Artikel.'],
                'felder' => ['type' => 'string', 'enum' => ['kurz', 'voll'], 'default' => 'kurz', 'description' => '„voll“ liefert zusätzlich Menge/Einheit, EK, Aktion bis, Lieferstatus, GP und Lead-Kennzeichen.'],
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
        if (isset($arguments['art']) && ! in_array($arguments['art'], ['food', 'nonfood', 'pruefen'], true)) {
            return ToolResult::error('art muss food, nonfood oder pruefen sein.', 'VALIDATION_ERROR');
        }
        $page = max(1, (int) ($arguments['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($arguments['per_page'] ?? 100)));
        Paginator::currentPageResolver(fn () => $page);
        $filter = ['onlyActive' => filter_var($arguments['only_active'] ?? true, FILTER_VALIDATE_BOOLEAN), 'art' => $arguments['art'] ?? ''];
        $q = trim((string) ($arguments['q'] ?? ''));
        $service = app(SupplierItemService::class);
        $treffer = isset($arguments['supplier_id'])
            ? $service->paginateForSupplier($team, (int) $arguments['supplier_id'], $filter + ['q' => $q], $perPage)
            : $service->searchGlobal($team, $q, $filter, $perPage);
        $voll = ($arguments['felder'] ?? 'kurz') === 'voll';
        $treffer->getCollection()->loadMissing('supplier:id,name');

        return $this->erfolgMitWarnungen([
            'total' => $treffer->total(),
            'page' => $treffer->currentPage(),
            'last_page' => $treffer->lastPage(),
            'per_page' => $treffer->perPage(),
            'artikel' => collect($treffer->items())->map(fn ($i) => [
                'id' => $i->id, 'designation' => $i->designation,
                'article_number' => $i->article_number,
                'supplier_id' => $i->supplier_id,
                'supplier' => $i->supplier?->name ?? null,
            ] + ($voll ? [
                'qty' => $i->qty !== null ? (float) $i->qty : null, 'unit_code' => $i->unit_code, 'ordering_unit' => $i->ordering_unit,
                'ek' => $i->aktiver_preis !== null ? (float) $i->aktiver_preis : null, 'aktion_bis' => $i->aktion_bis,
                'lieferstatus' => $i->lieferstatus, 'is_discontinued' => (bool) $i->is_discontinued,
                'gp' => $i->structure?->gp !== null ? ['id' => $i->structure->gp->id, 'name' => $i->structure->gp->name] : null,
                'ist_lead' => $i->structure?->gp !== null && (int) $i->structure->gp->lead_la_supplier_item_id === (int) $i->id,
            ] : []))->all(),
        ], $arguments);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'artikel', 'lieferantenartikel', 'list', 'katalog', 'paging'],
            'related_tools' => ['foodalchemist.artikel.SEARCH', 'foodalchemist.suppliers.SEARCH'],
            'examples' => ['Liste alle Lieferantenartikel auf', 'Zeig mir das Sortiment von Lieferant 12', 'Welche Artikel stehen auf der Non-Food-Prüfliste?'],
        ];
    }
}
