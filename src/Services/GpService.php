<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\GpStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;

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

    // ── D-5: Platzhalter-GPs (neutrale Abstrakta für Grundrezept-Templates) ──
    // Port von commands.rs (create_/rename_/delete_platzhalter_gp): abstrakt, kein
    // §3-WG/§8/LA → schlanker Pfad. WG '00', is_platzhalter=1, requires_la=0,
    // status approved, Name normalisiert auf "<Base> (neutral)", team-eigen (D1).
    // Vom Matcher ausgeschlossen, im Zutaten-Katalog (browseKatalog) aber findbar.

    /** Team-eigene Platzhalter (für die Verwaltung), inkl. Nutzungs-Zähler. */
    public function platzhalterListe(Team $team): Collection
    {
        return FoodAlchemistGp::where('team_id', $team->id)
            ->where('is_platzhalter', true)
            ->withCount(['recipeIngredients as in_zeilen'])
            ->orderBy('name')
            ->get();
    }

    /** Platzhalter anlegen (idempotent: existierender Key wird wiederverwendet). */
    public function createPlatzhalter(Team $team, string $name): FoodAlchemistGp
    {
        [$gpName, $slug, $gpKey, $base] = $this->platzhalterKeys($name);

        $existing = FoodAlchemistGp::where('team_id', $team->id)->where('gp_key', $gpKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        return FoodAlchemistGp::create([
            'team_id' => $team->id,
            'gp_key' => $gpKey,
            'name' => $gpName,
            'warengruppe_code' => '00',
            'hauptzutat_slug' => $slug,
            'hauptzutat_display' => $base,
            'status' => GpStatus::Approved,
            'requires_la' => false,
            'is_platzhalter' => true,
            'first_seen_at' => now(),
        ]);
    }

    /** Platzhalter umbenennen — nur is_platzhalter, nur team-eigen (D1), Key-Kollision verhindern. */
    public function renamePlatzhalter(Team $team, int $id, string $name): FoodAlchemistGp
    {
        $gp = FoodAlchemistGp::where('team_id', $team->id)->findOrFail($id);
        if (! $gp->is_platzhalter) {
            throw new \RuntimeException('GP ist kein Platzhalter (Bearbeiten über den GP-Editor).');
        }
        [$gpName, $slug, $gpKey, $base] = $this->platzhalterKeys($name);

        $kollision = FoodAlchemistGp::where('team_id', $team->id)
            ->where('gp_key', $gpKey)->where('id', '!=', $id)->exists();
        if ($kollision) {
            throw new \RuntimeException("Es gibt bereits einen Platzhalter „{$gpName}\".");
        }

        $gp->update([
            'gp_key' => $gpKey,
            'name' => $gpName,
            'hauptzutat_slug' => $slug,
            'hauptzutat_display' => $base,
        ]);

        return $gp->refresh();
    }

    /** Platzhalter löschen — nur is_platzhalter, nur team-eigen, blockt bei Verwendung. */
    public function deletePlatzhalter(Team $team, int $id): void
    {
        $gp = FoodAlchemistGp::where('team_id', $team->id)->findOrFail($id);
        if (! $gp->is_platzhalter) {
            throw new \RuntimeException('GP ist kein Platzhalter (Löschen über den GP-Editor).');
        }
        $n = FoodAlchemistRecipeIngredient::where('gp_id', $id)->whereNull('deleted_at')->count();
        if ($n > 0) {
            throw new \RuntimeException("Platzhalter wird in {$n} Rezept-Zeile(n) genutzt — dort erst entfernen.");
        }
        $gp->delete();
    }

    /**
     * Normalisiert einen freien Namen zu den Platzhalter-Schlüsseln. "(neutral)"-Suffix
     * wird vereinheitlicht (nie gedoppelt), Slug/Key über GpNamingService::slugify
     * (EIN-Zeichen-Faltung — byte-identisch zum Seed-Bestand, sonst Key-Kollision).
     *
     * @return array{0:string,1:string,2:string,3:string}  [gpName, slug, gpKey, base]
     */
    private function platzhalterKeys(string $name): array
    {
        $base = trim(preg_replace('/\(\s*neutral\s*\)\s*$/iu', '', trim($name)));
        if ($base === '') {
            throw new \RuntimeException('Name ist Pflicht.');
        }
        $slugPart = app(GpNamingService::class)->slugify($base);
        if ($slugPart === '') {
            throw new \RuntimeException('Name ergibt keinen gültigen Slug.');
        }

        return [
            $base . ' (neutral)',
            'platzhalter_' . $slugPart,
            'PLATZHALTER_' . mb_strtoupper($slugPart),
            $base,
        ];
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

    /**
     * GP-Kurationsstatus setzen (GL-05/GL-07). Autorisierung (Curate/D1) liegt beim
     * Aufrufer (Livewire prüft canCurate); der Service mutiert nur. Merged bleibt
     * System-Zustand (Merge-Tool) und ist im Editor nicht wählbar.
     */
    public function setStatus(FoodAlchemistGp $gp, GpStatus $status): FoodAlchemistGp
    {
        if ($status === GpStatus::Merged) {
            throw new \RuntimeException('Status „Zusammengeführt" wird nur durch das Merge-Werkzeug gesetzt.');
        }
        if ($gp->status !== $status) {
            $gp->update(['status' => $status]);
        }

        return $gp;
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

        // M8-04: Total OHNE den Lead-Join zählen (1:1-Join ändert die Zeilen-
        // zahl nicht, kostete im Paginator-COUNT aber ~120 ms auf 7,7k GPs)
        $seite = $q->paginate($perPage, total: $this->browserQuery($team, $filters)->count())->withQueryString();

        $preise = app(PriceService::class);
        $rezepteAktiv = \Illuminate\Support\Facades\Schema::hasTable('foodalchemist_recipes');

        // Effektive Allergen-Badges (GL-01, seit M3-04): Override > Mutter (Derivat) > LA-MAX — Bulk in 1 Query
        $zeilen = $seite->getCollection();
        $mutterIds = $zeilen->filter(fn ($gp) => $gp->is_derivat && $gp->derivat_von_gp_id !== null)->pluck('derivat_von_gp_id');
        $raenge = app(GpAggregateService::class)->laMaxRaengeBulk($zeilen->pluck('id')->merge($mutterIds)->unique()->values()->all());
        $muetter = $mutterIds->isNotEmpty() ? FoodAlchemistGp::whereIn('id', $mutterIds)->get()->keyBy('id') : collect();
        // M8-04: Rezept-Verwendungen in EINEM Query statt je Zeile (war N+1: 100×count)
        $rezeptCounts = $rezepteAktiv && $zeilen->isNotEmpty()
            ? \Illuminate\Support\Facades\DB::table('foodalchemist_recipe_ingredients')
                ->whereIn('gp_id', $zeilen->pluck('id'))->whereNull('deleted_at')
                ->selectRaw('gp_id, COUNT(*) AS n')->groupBy('gp_id')->pluck('n', 'gp_id')
            : collect();

        $zeilen->each(function ($gp) use ($preise, $rezepteAktiv, $raenge, $muetter, $rezeptCounts) {
            $gp->setAttribute('lead_vergleichspreis', $gp->lead_preis !== null
                ? $preise->vergleichspreis((object) ['qty' => $gp->lead_qty, 'unit_code' => $gp->lead_unit_code], (float) $gp->lead_preis)
                : null);
            $gp->setAttribute('rezepte_count', $rezepteAktiv ? (int) ($rezeptCounts[$gp->id] ?? 0) : null);

            $mutter = $gp->is_derivat ? $muetter->get($gp->derivat_von_gp_id) : null;
            $rangVon = ['enthalten' => 3, 'spuren' => 2, 'nicht_enthalten' => 1, 'unbekannt' => 0];
            $badges = [];
            $maxRang = 0;
            foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
                $override = $gp->getAttribute("allergen_{$feld}");                     // Prio 1: Override
                if ($override !== null) {
                    $r = $rangVon[$override] ?? 0;
                } elseif ($mutter !== null) {                                          // Prio 2: Derivat → Mutter
                    $mo = $mutter->getAttribute("allergen_{$feld}");
                    $r = $mo !== null ? ($rangVon[$mo] ?? 0) : (int) ($raenge[$mutter->id][$feld] ?? 0);
                } else {                                                               // Prio 3: LA-MAX
                    $r = (int) ($raenge[$gp->id][$feld] ?? 0);
                }
                $maxRang = max($maxRang, $r);
                if ($r === 3) {
                    $badges[] = $feld;
                }
            }
            $gp->setAttribute('allergen_badges', $badges);
            // 3-Status für die Liste: vorhanden (enthalten/Spuren) · frei (nur nicht_enthalten) · keine_daten (alles unbekannt)
            $gp->setAttribute('allergen_status', $maxRang >= 2 ? 'vorhanden' : ($maxRang === 1 ? 'frei' : 'keine_daten'));
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
