<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;

/**
 * Stateless Lese-/Such-Service für Grundprodukte (D-3-Teilmenge des Vertical Slice).
 * Schreibwege (Naming-Validierung GL-12, Merge, Lebenszyklus GL-07) folgen in der D-3-Ausbaustufe.
 */
class GpService
{
    /** D1 Eltern→Kinder: sichtbar = Team-Kette; ohne Team nichts (Leak-Schutz). */
    private function scoped(Builder $query, ?Team $team): Builder
    {
        return $team ? $query->visibleToTeam($team) : $query->whereRaw('1 = 0');
    }

    /**
     * GP-Liste mit Suche + Filtern, hierarchie-gescoped (D1).
     *
     * @param array{search?: string, warengruppe?: string, status?: string} $filters
     */
    public function paginate(array $filters, ?Team $team, int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->scoped(FoodAlchemistGp::query(), $team)
            ->with('warengruppe');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            // LOWER()-Vergleich statt LIKE-Collation-Annahmen (02_DATENMODELL Typ-Port / NOCASE-Ersatz)
            $needle = mb_strtolower($search);
            $query->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(hauptzutat_slug) LIKE ?', ["%{$needle}%"]);
            });
        }

        if (! empty($filters['warengruppe'])) {
            $query->where('warengruppe_code', $filters['warengruppe']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query
            ->orderBy('name')
            ->orderBy('id') // Determinismus-Anker (GL-04-Lehre: ORDER BY id als Tiebreaker)
            ->paginate($perPage);
    }

    public function find(int $id, ?Team $team): ?FoodAlchemistGp
    {
        return $this->scoped(FoodAlchemistGp::query(), $team)
            ->with(['warengruppe', 'preferredCountUnit', 'derivatVon', 'mergedInto', 'leadLa.supplier'])
            ->find($id);
    }

    /**
     * LA-Liste eines GP fürs Detail (GL-05-Sicht): Structure + Artikel + Lieferant + aktiver Preis.
     * Aktiver Preis = neueste nicht-blockierte Zeile (valid_to DESC, id DESC) — GL-11-Soll
     * „einheitlich aktiver_preis()" (A-2-Vereinheitlichung); Kategorisierung T1 folgt in D-2.
     */
    public function lasForGp(FoodAlchemistGp $gp): \Illuminate\Support\Collection
    {
        return $gp->structures()
            ->with(['item.supplier'])
            ->get()
            ->map(function ($structure) {
                $item = $structure->item;
                // M2-04: Aktiv-Preis-Regel zentral im PriceService (GL-11 §3.3 — eine Stelle!)
                $price = $item !== null ? app(PriceService::class)->activeFor($item->id) : null;

                return (object) [
                    'structure' => $structure,
                    'item' => $item,
                    'supplier' => $item?->supplier,
                    'price' => $price,
                ];
            })
            ->sortBy(fn ($la) => $la->supplier?->name ?? '~')
            ->values();
    }

    /** Warengruppen-Optionen für Filter-Dropdowns (code => "code name"). */
    public function warengruppenOptions(?Team $team): Collection
    {
        return $this->scoped(FoodAlchemistLookupWarengruppe::query(), $team)
            ->orderBy('code')
            ->get(['code', 'name']);
    }

    /** Status-Zähler für Dashboard-Tiles / Filter-Badges. */
    public function statusCounts(?Team $team): array
    {
        return $this->scoped(FoodAlchemistGp::query(), $team)
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();
    }

    /**
     * M1-03 / D-1 AT-D1-03: Sub-Kategorie auf den GPs umbenennen — NUR eigene Zeilen
     * (D1: geerbte Katalog-GPs bleiben unangetastet). Transaktional, gibt affected zurück.
     */
    public function renameSubKategorie(Team $team, string $warengruppeCode, string $alt, string $neu): int
    {
        return \Illuminate\Support\Facades\DB::transaction(fn () => FoodAlchemistGp::query()
            ->where('team_id', $team->id)
            ->where('warengruppe_code', $warengruppeCode)
            ->where('sub_kategorie', $alt)
            ->update(['sub_kategorie' => $neu]));
    }

    /** M1-03: Sub-Kategorie-Wert auf NULL setzen (Housekeeping) — nur eigene Zeilen. */
    public function clearSubKategorie(Team $team, string $warengruppeCode, string $wert): int
    {
        return FoodAlchemistGp::query()
            ->where('team_id', $team->id)
            ->where('warengruppe_code', $warengruppeCode)
            ->where('sub_kategorie', $wert)
            ->update(['sub_kategorie' => null]);
    }

    // ── M3-01/02: Browser-Neubau ────────────────────────────────────────

    /** WG-Baum-Zähler in EINER GROUP-BY-Query (P-1), optional unter aktuellem Filter. */
    public function wgCounts(?Team $team, array $filters = []): array
    {
        return $this->browserQuery($team, array_diff_key($filters, ['warengruppe' => 1]))
            ->selectRaw('warengruppe_code, COUNT(*) AS n')
            ->groupBy('warengruppe_code')
            ->pluck('n', 'warengruppe_code')
            ->all();
    }

    /** Sub-Kategorie-Zähler der gewählten WG (Screen 1: zweite Baum-Spalte). */
    public function subKategorieCounts(?Team $team, string $warengruppeCode): array
    {
        return $this->browserQuery($team, ['warengruppe' => $warengruppeCode])
            ->whereNotNull('sub_kategorie')
            ->selectRaw('sub_kategorie, COUNT(*) AS n')
            ->groupBy('sub_kategorie')
            ->orderBy('sub_kategorie')
            ->pluck('n', 'sub_kategorie')
            ->all();
    }

    /**
     * M3-02: GP-Tabelle mit Lead-Preis-Spalte (Join auf Lead-LA + Aktiv-Preis-Subquery,
     * eine Regel-Stelle: PriceService) — Rezepte-Zähler folgt mit M4 (hasTable-Guard).
     */
    public function paginateBrowser(array $filters, ?Team $team, int $perPage = 100): LengthAwarePaginator
    {
        $q = $this->browserQuery($team, $filters)
            ->leftJoin('foodalchemist_supplier_items AS lead', 'lead.id', '=', 'foodalchemist_gps.lead_la_supplier_item_id')
            ->select('foodalchemist_gps.*', 'lead.qty AS lead_qty', 'lead.unit_code AS lead_unit_code')
            ->selectSub(app(PriceService::class)->activePriceSubquery('lead.id')->toBase(), 'lead_preis')
            ->with('warengruppe')
            ->orderBy('foodalchemist_gps.name');

        $seite = $q->paginate($perPage)->withQueryString();

        $preise = app(PriceService::class);
        $rezepteAktiv = \Illuminate\Support\Facades\Schema::hasTable('foodalchemist_recipes');
        $seite->getCollection()->each(function ($gp) use ($preise, $rezepteAktiv) {
            $gp->setAttribute('lead_vergleichspreis', $gp->lead_preis !== null
                ? $preise->vergleichspreis((object) ['qty' => $gp->lead_qty, 'unit_code' => $gp->lead_unit_code], (float) $gp->lead_preis)
                : null);
            $gp->setAttribute('rezepte_count', $rezepteAktiv
                ? (int) \Illuminate\Support\Facades\DB::table('foodalchemist_recipe_ingredients')->where('gp_id', $gp->id)->whereNull('deleted_at')->count()
                : null);
        });

        return $seite;
    }

    /** Gemeinsame Browser-Filter (Suche/WG/Sub-Kategorie/Status), team-gescoped. */
    private function browserQuery(?Team $team, array $filters): Builder
    {
        return $this->scoped(FoodAlchemistGp::query(), $team)
            ->when(($filters['search'] ?? '') !== '', function (Builder $q) use ($filters) {
                $such = mb_strtolower(trim($filters['search']));
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', ['%' . $such . '%'])
                    ->orWhereRaw('LOWER(hauptzutat_slug) LIKE ?', ['%' . $such . '%']));
            })
            ->when(($filters['warengruppe'] ?? '') !== '', fn (Builder $q) => $q->where('warengruppe_code', $filters['warengruppe']))
            ->when(($filters['sub_kategorie'] ?? '') !== '', fn (Builder $q) => $q->where('sub_kategorie', $filters['sub_kategorie']))
            ->when(($filters['status'] ?? '') !== '', fn (Builder $q) => $q->where('status', $filters['status']));
    }
}
