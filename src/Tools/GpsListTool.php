<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpService;

/**
 * #504: Vollständige, seiten-basierte Auflistung der Grundprodukte (GPs) des Teams —
 * ergänzt gps.SEARCH (das braucht einen Suchbegriff und cappt bei 50). Offset-freies
 * page/per_page-Paging fürs komplette Katalog-Enumerieren. Detail via gps.GET.
 */
class GpsListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gps.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die Grundprodukte (GPs) des Teams vollständig und seitenweise auf (ohne Suchbegriff, '
            . 'ohne 50er-Cap). page/per_page-Paging (last_page zum Weiterblättern). Liefert je GP '
            . 'id, name, status, main_ingredient_slug; mit felder="voll" zusätzlich Warengruppe, sub_category, condition, zusatz, marke_offen, '
            . 'rezepturware und den Lead-Lieferantenartikel samt Lieferant. Filter (alle optional, kombinierbar): commodity_group_code (WG, z. B. "13"), '
            . 'sub_category, status, marke_offen, rezepturware (ja|nein|offen), ohne_lead (true = kein Lead-Lieferantenartikel), '
            . 'ohne_la (true = keine aktive Lieferantenartikel-Struktur), nur_la_pflicht (true = nur GPs, die einen Artikel brauchen: requires_la, kein Derivat), '
            . 'lead_lieferant_inaktiv (true = Lead-Artikel beim inaktiven oder gesperrten Lieferanten, false = Lead bestellbar). '
            . 'Details via foodalchemist.gps.GET, gezielte Suche via foodalchemist.gps.SEARCH.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => 'Seitennummer (last_page aus der Vorantwort).'],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100, 'description' => 'Seitengröße (max. 200).'],
                'commodity_group_code' => ['type' => 'string', 'description' => 'Warengruppe (Code, z. B. "13").'],
                'sub_category' => ['type' => 'string', 'description' => 'Unterkategorie, exakt (z. B. "16.6 Geschirr & Ausstattung").'],
                'status' => ['type' => 'string', 'enum' => ['approved', 'tentative', 'review', 'rejected', 'merged'], 'description' => 'GP-Status.'],
                'marke_offen' => ['type' => 'boolean', 'description' => 'true = nur GPs auf der Nachlauf-Liste „Marke offen“, false = ohne.'],
                'rezepturware' => ['type' => 'string', 'enum' => ['ja', 'nein', 'offen'], 'description' => 'Rezepturware (§5 Ausnahme 3): ja, nein oder offen (nicht entschieden).'],
                'ohne_lead' => ['type' => 'boolean', 'description' => 'true = nur GPs ohne Lead-Lieferantenartikel, false = nur mit.'],
                'ohne_la' => ['type' => 'boolean', 'description' => 'true = nur GPs ohne aktive Lieferantenartikel-Struktur (kein verknüpfter Artikel), false = nur mit.'],
                'lead_lieferant_inaktiv' => ['type' => 'boolean', 'description' => 'true = nur GPs, deren Lead-Lieferantenartikel bei einem inaktiven oder gesperrten Lieferanten liegt (Repick-Kandidaten); false = nur GPs mit bestellbarem Lead.'],
                'nur_la_pflicht' => ['type' => 'boolean', 'description' => 'true = nur GPs, die einen Lieferantenartikel brauchen (requires_la, kein Derivat) — zusammen mit ohne_la die echte Lückenliste.'],
                'felder' => ['type' => 'string', 'enum' => ['kurz', 'voll'], 'default' => 'kurz', 'description' => '„voll“ liefert zusätzlich WG, sub_category, condition, zusatz, marke_offen, rezepturware und den Lead samt Lieferant.'],
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
        $page = max(1, (int) ($arguments['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($arguments['per_page'] ?? 100)));
        Paginator::currentPageResolver(fn () => $page);
        $filter = ['search' => '', 'commodity_group' => $arguments['commodity_group_code'] ?? null, 'status' => $arguments['status'] ?? null,
            'sub_category' => $arguments['sub_category'] ?? null]
            + array_filter(array_intersect_key($arguments, array_flip(['marke_offen', 'rezepturware', 'ohne_lead', 'ohne_la', 'nur_la_pflicht', 'lead_lieferant_inaktiv'])), fn ($v) => $v !== null && $v !== '');
        if (isset($filter['rezepturware']) && ! in_array($filter['rezepturware'], ['ja', 'nein', 'offen'], true)) {
            return ToolResult::error('rezepturware muss ja, nein oder offen sein.', 'VALIDATION_ERROR');
        }
        $voll = ($arguments['felder'] ?? 'kurz') === 'voll';
        $treffer = app(GpService::class)->paginate($filter, $team, $perPage);
        if ($voll) {
            $treffer->getCollection()->load('leadLa.supplier:id,name');
        }

        return ToolResult::success([
            'total' => $treffer->total(),
            'page' => $treffer->currentPage(),
            'last_page' => $treffer->lastPage(),
            'per_page' => $treffer->perPage(),
            'gps' => collect($treffer->items())->map(fn ($gp) => [
                'id' => $gp->id, 'name' => $gp->name, 'status' => $gp->status,
                'main_ingredient_slug' => $gp->main_ingredient_slug,
            ] + ($voll ? [
                'commodity_group_code' => $gp->commodity_group_code, 'sub_category' => $gp->sub_category, 'condition' => $gp->condition,
                'zusatz' => $gp->zusatz, 'marke_offen' => (bool) $gp->marke_offen, 'rezepturware' => $gp->rezepturware,
                'lead_la_supplier_item_id' => $gp->lead_la_supplier_item_id,
                'lead_la' => $gp->leadLa !== null ? ['designation' => $gp->leadLa->designation, 'lieferant' => $gp->leadLa->supplier?->name] : null,
            ] : []))->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'list', 'katalog', 'paging'],
            'related_tools' => ['foodalchemist.gps.SEARCH', 'foodalchemist.gps.GET'],
            'examples' => ['Liste alle Grundprodukte auf', 'Zeig mir den kompletten GP-Katalog seitenweise'],
        ];
    }
}
