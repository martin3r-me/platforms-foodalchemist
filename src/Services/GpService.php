<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\GpStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent;
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
     * @param array{search?: string, commodity_group?: string, status?: string} $filters
     */
    public function paginate(array $filters, ?Team $team, int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->scoped(FoodAlchemistGp::query(), $team)
            ->with('commodity_group');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            // Multi-Wort: jedes Token muss treffen (Name ODER main_ingredient_slug)
            \Platform\FoodAlchemist\Support\Suche::likeAny($query, ['name', 'main_ingredient_slug'], $search);
        }

        if (! empty($filters['commodity_group'])) {
            $query->where('commodity_group_code', $filters['commodity_group']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        // MCP gps.LIST (Bestandsarbeit per MCP, 29.10.): weitere Filter, nur wenn gesetzt
        if (($filters['sub_category'] ?? '') !== '') {
            $query->where('sub_category', $filters['sub_category']);
        }
        if (isset($filters['marke_offen'])) {
            $query->where('marke_offen', filter_var($filters['marke_offen'], FILTER_VALIDATE_BOOLEAN));   // auch "false" als Text
        }
        if (isset($filters['rezepturware'])) {   // ja | nein | offen (nicht entschieden)
            $filters['rezepturware'] === 'offen' ? $query->whereNull('rezepturware') : $query->where('rezepturware', $filters['rezepturware'] === 'ja');
        }
        if (isset($filters['ohne_lead'])) {
            filter_var($filters['ohne_lead'], FILTER_VALIDATE_BOOLEAN) ? $query->whereNull('lead_la_supplier_item_id') : $query->whereNotNull('lead_la_supplier_item_id');
        }
        if (isset($filters['ohne_la'])) {   // Bestandsarbeit „GPs ohne Lieferantenartikel“: keine aktive LA-Struktur
            $mitLa = fn ($q) => $q->select(\Illuminate\Support\Facades\DB::raw(1))->from('foodalchemist_supplier_item_structures AS la_s')
                ->whereColumn('la_s.gp_id', 'foodalchemist_gps.id')->whereNull('la_s.deleted_at');
            filter_var($filters['ohne_la'], FILTER_VALIDATE_BOOLEAN) ? $query->whereNotExists($mitLa) : $query->whereExists($mitLa);
        }
        if (isset($filters['lead_lieferant_inaktiv'])) {   // #632: Lead beim inaktiven/gesperrten Lieferanten — Kandidaten für den Lead-Repick
            $leadInaktiv = fn ($q) => $q->select(\Illuminate\Support\Facades\DB::raw(1))->from('foodalchemist_supplier_items AS lead_li')
                ->join('foodalchemist_suppliers AS sup', 'sup.id', '=', 'lead_li.supplier_id')
                ->whereColumn('lead_li.id', 'foodalchemist_gps.lead_la_supplier_item_id')
                ->whereRaw(\Platform\FoodAlchemist\Models\FoodAlchemistSupplier::nichtBestellbarSql('sup'));
            filter_var($filters['lead_lieferant_inaktiv'], FILTER_VALIDATE_BOOLEAN)
                ? $query->whereExists($leadInaktiv)
                : $query->whereNotNull('lead_la_supplier_item_id')->whereNotExists($leadInaktiv);
        }
        if (isset($filters['nur_la_pflicht']) && filter_var($filters['nur_la_pflicht'], FILTER_VALIDATE_BOOLEAN)) {   // braucht einen LA: requires_la, kein Derivat
            $query->where('requires_la', true)->where(fn ($w) => $w->whereNull('is_derivat')->orWhere('is_derivat', false));
        }

        return $query
            ->orderBy('name')
            ->orderBy('id') // Determinismus-Anker (GL-04-Lehre: ORDER BY id als Tiebreaker)
            ->paginate($perPage);
    }

    public function find(int $id, ?Team $team): ?FoodAlchemistGp
    {
        return $this->scoped(FoodAlchemistGp::query(), $team)
            ->with(['commodity_group', 'preferredCountUnit', 'derivatVon', 'mergedInto', 'leadLa.supplier'])
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
            'commodity_group_code' => '00',
            'main_ingredient_slug' => $slug,
            'main_ingredient_display' => $base,
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
            'main_ingredient_slug' => $slug,
            'main_ingredient_display' => $base,
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
    public function lasForGp(FoodAlchemistGp $gp, ?Team $team = null): \Illuminate\Support\Collection
    {
        return $gp->structures()
            ->with(['item.supplier'])
            ->get()
            ->map(function ($structure) use ($team) {
                $item = $structure->item;
                // M2-04: Aktiv-Preis-Regel zentral im PriceService (GL-11 §3.3 — eine Stelle!)
                $price = $item !== null ? app(PriceService::class)->activeFor($item->id, $team) : null; // Spec 82 3a
                // Rückfall (27.10.): eingestellter Artikel ohne aktiven Preis → letzter bekannter Preis mit Kennzeichen; `price` bleibt der aktive (oder null)
                $letzter = $price === null && $item !== null ? app(PriceService::class)->letzterPreisFuer($item->id, $team) : null;

                return (object) [
                    'structure' => $structure,
                    'item' => $item,
                    'supplier' => $item?->supplier,
                    'price' => $price,
                    'letzter_preis' => $letzter,
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
        // Spec 77a: ab Kuratieren im Besitzer-Team des Grundprodukts (ohne angemeldeten Benutzer keine Prüfung)
        if (($besitzer = \Platform\Core\Models\Team::find($gp->team_id)) !== null) {
            app(\Platform\FoodAlchemist\Services\FaRechte::class)->pruefeAngemeldet($besitzer, \Platform\FoodAlchemist\Enums\FaRolle::Kuratieren, 'Grundprodukt-Status setzen');
        }
        if ($status === GpStatus::Merged) {
            throw new \RuntimeException('Status „Zusammengeführt" wird nur durch das Merge-Werkzeug gesetzt.');
        }
        if ($gp->status !== $status) {
            $gp->update(['status' => $status]);

            // Schicht 3 · Slice 6: Freigabe → EINMAL gegen das GP-Regelwerk prüfen. Bei approved
            // ist der Critic detect-only (die Heilung guardt auf tentative) → ein Verstoß erscheint
            // als Signal, das freigegebene GP wird nicht angetastet. Best-effort, mit User-Kontext
            // (der KI-Call braucht Auth); ein Dispatch-Fehler darf die Freigabe nie kippen.
            if ($status === GpStatus::Approved && $gp->team_id !== null
                && ($confUid = \Illuminate\Support\Facades\Auth::id()) !== null) {
                try {
                    \Platform\FoodAlchemist\Jobs\ConformanceCheckJob::dispatch(
                        (int) $gp->team_id, (int) $confUid, 'gp', (int) $gp->id,
                        extern: app(\Platform\FoodAlchemist\Services\Ai\KiExternModus::class)->fuerDispatch(),
                    );
                } catch (\Throwable $e) {
                    // Dispatch-Fehler schlucken — die Freigabe steht.
                }
            }
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
     * Plattform-Basis 18.10.: Sub-Kategorien sind global und nur der Master benennt sie um — er zieht dabei die globalen
     * GPs mit (sie hängen per Text an der Sub-Kategorie, sonst stünde dort ein verwaister Freitext). Mandanten-GPs bleiben.
     */
    public function renameSubKategorie(Team $team, string $warengruppeCode, string $alt, string $neu): int
    {
        $mitGlobal = \Platform\FoodAlchemist\Support\MasterTeam::istMaster($team);

        return \Illuminate\Support\Facades\DB::transaction(fn () => FoodAlchemistGp::ohneTeamSicht('master-kuration')
            ->where(fn ($q) => $q->where('team_id', $team->id)->when($mitGlobal, fn ($q) => $q->orWhereNull('team_id')))
            ->where('commodity_group_code', $warengruppeCode)
            ->where('sub_category', $alt)
            ->update(['sub_category' => $neu]));
    }

    /** M1-03: Sub-Kategorie-Wert auf NULL setzen (Housekeeping) — nur eigene Zeilen. */
    public function clearSubKategorie(Team $team, string $warengruppeCode, string $wert): int
    {
        return FoodAlchemistGp::query()
            ->where('team_id', $team->id)
            ->where('commodity_group_code', $warengruppeCode)
            ->where('sub_category', $wert)
            ->update(['sub_category' => null]);
    }

    // ── M3-01/02: Browser-Neubau ────────────────────────────────────────

    /** WG-Baum-Zähler in EINER GROUP-BY-Query (P-1), optional unter aktuellem Filter. */
    public function wgCounts(?Team $team, array $filters = []): array
    {
        return $this->browserQuery($team, array_diff_key($filters, ['commodity_group' => 1]))
            ->selectRaw('commodity_group_code, COUNT(*) AS n')
            ->groupBy('commodity_group_code')
            ->pluck('n', 'commodity_group_code')
            ->all();
    }

    /** Sub-Kategorie-Zähler der gewählten WG (Screen 1: zweite Baum-Spalte). */
    public function subKategorieCounts(?Team $team, string $warengruppeCode): array
    {
        return $this->browserQuery($team, ['commodity_group' => $warengruppeCode])
            ->whereNotNull('sub_category')
            ->selectRaw('sub_category, COUNT(*) AS n')
            ->groupBy('sub_category')
            ->orderBy('sub_category')
            ->pluck('n', 'sub_category')
            ->all();
    }

    /**
     * M3-02: GP-Tabelle mit Lead-Preis-Spalte (Join auf Lead-LA + Aktiv-Preis-Subquery,
     * eine Regel-Stelle: PriceService) — Rezepte-Zähler folgt mit M4 (hasTable-Guard).
     */
    public function paginateBrowser(array $filters, ?Team $team, int $perPage = 100): LengthAwarePaginator
    {
        $q = $this->browserQuery($team, $filters)
            ->leftJoin('foodalchemist_supplier_items AS lead', function ($j) use ($team) {
                $j->on('lead.id', '=', 'foodalchemist_gps.lead_la_supplier_item_id');
                // Spec 82 §12 A3: ein Lead aus dem Stamm-Paket ohne Opt-in zeigt weder Menge noch Einheit.
                \Platform\FoodAlchemist\Support\StammPaket::begrenzen($j, 'lead', $team, 'foodalchemist_supplier_items');
            })
            ->select('foodalchemist_gps.*', 'lead.unit_code AS lead_unit_code')
            ->selectRaw(\Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::mengeSql('lead').' AS lead_qty')   // korrigierte Menge vor Necta-qty
            ->selectSub(app(PriceService::class)->activePriceSubquery('lead.id', $team)->toBase(), 'lead_preis') // Spec 82 3a
            ->addSelect('lead.lieferstatus_seit AS lead_eingestellt_seit')
            ->tap(fn ($q) => app(PriceService::class)->mitLetztemPreis($q, 'lead.id', $team))   // Rückfall: letzter Preis eines eingestellten Leads (27.10.)
            ->with('commodity_group')
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
            // Kein aktiver Preis, Lead eingestellt → letzter bekannter Preis, gekennzeichnet (nie still als normaler Preis)
            $gp->setAttribute('lead_letzter', null);
            if ($gp->lead_preis === null && $gp->letzter_preis !== null) {
                $gp->setAttribute('lead_preis', $gp->letzter_preis);
                $gp->setAttribute('lead_letzter', PriceService::letzterPreisKennzeichen($gp->letzter_preis_ab, $gp->lead_eingestellt_seit));
            }
            $gp->setAttribute('lead_vergleichspreis', $gp->lead_preis !== null
                ? $preise->vergleichspreis((object) ['qty' => $gp->lead_qty, 'unit_code' => $gp->lead_unit_code], (float) $gp->lead_preis)
                : null);
            $gp->setAttribute('rezepte_count', $rezepteAktiv ? (int) ($rezeptCounts[$gp->id] ?? 0) : null);

            $mutter = $gp->is_derivat ? $muetter->get($gp->derivat_von_gp_id) : null;
            $rangVon = ['enthalten' => 3, 'spuren' => 2, 'nicht_enthalten' => 1, 'unbekannt' => 0];
            $kiQuelle = fn (FoodAlchemistGp $g): bool => $g->allergens_source === 'ki' || $g->allergens_confidence !== null;
            // LA-belegt = irgendein Allergen hat konkrete LA-Daten (eigene bzw. Mutter-LAs bei Derivaten)
            // — unabhängig davon, ob ein Override die Prio gewinnt (Override + LA-Bestätigung ≠ reine Schätzung).
            $laBelegt = collect($raenge[$gp->id] ?? [])->max() > 0
                || ($mutter !== null && collect($raenge[$mutter->id] ?? [])->max() > 0);
            $badges = [];
            $maxRang = 0;
            $ausKiOverride = false; // mind. ein konkreter Wert stammt aus einem KI-geschätzten Override
            foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
                $override = $gp->getAttribute("allergen_{$feld}");                     // Prio 1: Override
                if ($override !== null) {
                    $r = $rangVon[$override] ?? 0;
                    $ausKiOverride = $ausKiOverride || ($r > 0 && $kiQuelle($gp));
                } elseif ($mutter !== null) {                                          // Prio 2: Derivat → Mutter
                    $mo = $mutter->getAttribute("allergen_{$feld}");
                    $r = $mo !== null ? ($rangVon[$mo] ?? 0) : (int) ($raenge[$mutter->id][$feld] ?? 0);
                    $ausKiOverride = $ausKiOverride || ($mo !== null && $r > 0 && $kiQuelle($mutter));
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
            // ✨-Marker (LMIV-Transparenz, 2026-07-02): Urteil beruht NUR auf KI-geschätzten
            // Overrides ohne jede LA-Untermauerung — in der Liste sichtbar kennzeichnen.
            $gp->setAttribute('allergen_ki', $maxRang >= 1 && ! $laBelegt && $ausKiOverride);
            $gp->setAttribute('allergen_ki_conf', $gp->allergens_confidence ?? $gp->allergens_confidence);
        });

        return $seite;
    }

    /** Gemeinsame Browser-Filter (Suche/WG/Sub-Kategorie/Status), team-gescoped. */
    private function browserQuery(?Team $team, array $filters): Builder
    {
        return $this->scoped(FoodAlchemistGp::query(), $team)
            // Merged-GPs sind System-Tombstones des Merge-Werkzeugs — im Browser
            // komplett unsichtbar (User-Entscheidung 2026-07-02): kein Filter, keine Zeilen.
            ->where('foodalchemist_gps.status', '!=', GpStatus::Merged->value)
            ->when(($filters['search'] ?? '') !== '', fn (Builder $q) => \Platform\FoodAlchemist\Support\Suche::likeAny($q, ['name', 'main_ingredient_slug'], $filters['search']))
            ->when(($filters['commodity_group'] ?? '') !== '', fn (Builder $q) => $q->where('commodity_group_code', $filters['commodity_group']))
            ->when(($filters['sub_category'] ?? '') !== '', fn (Builder $q) => $q->where('sub_category', $filters['sub_category']))
            ->when(($filters['status'] ?? '') !== '', fn (Builder $q) => $q->where('status', $filters['status']));
    }

    // ── Lösch- & Ersetzungs-Pfad (User-Entscheidung 2026-07-02: Löschen nur ohne Referenzen) ──

    /**
     * Alle Referenzen, die ein Löschen blockieren — bewusst UNGESCOPED: der Katalog
     * ist global (D1), auch Rezept-Zeilen fremder Teams zählen.
     *
     * @return array{las: int, rezept_zeilen: int, rezepte: int, derivate: int, merge_quellen: int, ersatz: int, summe: int}
     */
    public function referenzen(FoodAlchemistGp $gp): array
    {
        $zeilen = FoodAlchemistRecipeIngredient::where('gp_id', $gp->id);
        $ref = [
            'las' => $gp->structures()->count(),
            'rezept_zeilen' => (clone $zeilen)->count(),
            'rezepte' => (clone $zeilen)->distinct()->count('recipe_id'),
            'derivate' => FoodAlchemistGp::where('derivat_von_gp_id', $gp->id)->count(),
            'merge_quellen' => FoodAlchemistGp::where('merged_into_id', $gp->id)->count(),
            'ersatz' => FoodAlchemistComponentEquivalent::where(fn (Builder $w) => $w
                ->where(fn (Builder $q) => $q->where('source_kind', FoodAlchemistComponentEquivalent::KIND_GP)->where('source_id', $gp->id))
                ->orWhere(fn (Builder $q) => $q->where('alt_kind', FoodAlchemistComponentEquivalent::KIND_GP)->where('alt_id', $gp->id)))
                ->count(),
        ];
        $ref['summe'] = $ref['las'] + $ref['rezept_zeilen'] + $ref['derivate'] + $ref['merge_quellen'] + $ref['ersatz'];

        return $ref;
    }

    /** GP löschen (Soft-Delete) — nur ohne jede Referenz; Platzhalter haben ihren eigenen Pfad (D-5). */
    public function deleteGp(FoodAlchemistGp $gp): void
    {
        if ($gp->is_platzhalter) {
            throw new \RuntimeException('Platzhalter werden über die Platzhalter-Verwaltung gelöscht.');
        }
        $ref = $this->referenzen($gp);
        if ($ref['summe'] > 0) {
            $teile = array_filter([
                $ref['las'] > 0 ? "{$ref['las']} Lieferantenartikel" : null,
                $ref['rezept_zeilen'] > 0 ? "{$ref['rezept_zeilen']} Rezept-Zeile(n) in {$ref['rezepte']} Rezept(en)" : null,
                $ref['derivate'] > 0 ? "{$ref['derivate']} Derivat(e)" : null,
                $ref['merge_quellen'] > 0 ? "{$ref['merge_quellen']} Merge-Verweis(e)" : null,
                $ref['ersatz'] > 0 ? "{$ref['ersatz']} Ersatz-Verknüpfung(en)" : null,
            ]);
            throw new \RuntimeException('Löschen blockiert — wird referenziert: ' . implode(', ', $teile) . '.');
        }
        $gp->delete();
    }

    /**
     * GP KOMPLETT ersetzen: Lieferantenartikel UND Rezept-Zeilen wandern auf das Ziel (Vorstufe zum Löschen).
     *
     * Artikel gehen einzeln den normalen Weg {@see LeadLaService::entknuepfen()} → {@see LeadLaService::verknuepfen()} (dort greift der
     * Allergen-Guard) in EINER Transaktion je Artikel: lehnt der Guard ab, bleibt der Artikel am Quell-GP und wird aufgelistet.
     * Leads (Quelle und Ziel) werden dabei neu gewählt, `n_las_total` beider GPs am Ende aus dem Ist-Stand gesetzt. Danach die
     * Rezept-Zeilen wie {@see self::ersetzeInRezepten()}. Nur wer beide GPs kuratieren darf (bei globalen Stamm-Paket-GPs der Master).
     *
     * @return array{zeilen: int, rezepte: int, artikel_umgehaengt: int, artikel_abgelehnt: list<array{id: int, bezeichnung: string, grund: string}>}
     */
    public function ersetzeKomplett(Team $team, FoodAlchemistGp $von, FoodAlchemistGp $nach): array
    {
        $this->pruefeErsatzZiel($von, $nach);
        if (! $von->isOwnedBy($team)) {
            throw new \Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException('das GP', self::GEERBT);
        }
        $artikel = \Illuminate\Support\Facades\DB::table('foodalchemist_supplier_item_structures')
            ->where('gp_id', $von->id)->whereNull('deleted_at')->orderBy('id')->pluck('supplier_item_id')->map(fn ($v) => (int) $v)->all();
        if ($artikel !== [] && ! $nach->isOwnedBy($team)) {
            throw new \Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException('das Ziel-GP', self::GEERBT);
        }

        $lead = app(LeadLaService::class);
        $umgehaengt = 0;
        $abgelehnt = [];
        foreach ($artikel as $laId) {
            try {
                \Illuminate\Support\Facades\DB::transaction(function () use ($lead, $team, $von, $nach, $laId) {
                    $lead->entknuepfen($team, $von->refresh(), $laId);
                    $lead->verknuepfen($team, $nach->refresh(), $laId);
                });
                $umgehaengt++;
            } catch (\Platform\FoodAlchemist\Exceptions\DeklarationsprofilAbweichtException|\RuntimeException $e) {
                $bez = (string) (\Illuminate\Support\Facades\DB::table('foodalchemist_supplier_items')->where('id', $laId)->value('designation') ?? "#{$laId}");
                $abgelehnt[] = ['id' => $laId, 'bezeichnung' => $bez, 'grund' => $e->getMessage()];
            }
        }
        foreach ([$von, $nach] as $gp) {
            $gp->refresh();
            $n = \Illuminate\Support\Facades\DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $gp->id)->whereNull('deleted_at')->count();
            if ((int) $gp->n_las_total !== $n) {
                $gp->update(['n_las_total' => $n]);
            }
        }

        $rezepte = $this->ersetzeInRezepten($von->refresh(), $nach->refresh());

        return $rezepte + ['artikel_umgehaengt' => $umgehaengt, 'artikel_abgelehnt' => $abgelehnt];
    }

    /** Gemeinsame Zielprüfung für das Ersetzen (Rezepte allein und komplett). */
    private function pruefeErsatzZiel(FoodAlchemistGp $von, FoodAlchemistGp $nach): void
    {
        if ($von->id === $nach->id) {
            throw new \RuntimeException('Quelle und Ziel sind identisch.');
        }
        if (in_array($nach->status, [GpStatus::Merged, GpStatus::Rejected], true)) {
            throw new \RuntimeException("Ziel-GP ist „{$nach->status->label()}\" — kein gültiges Ersetzungsziel.");
        }
        if ($nach->istNonFood()) {
            throw new \Platform\FoodAlchemist\Exceptions\NonFoodAlsZutatException([(string) $nach->name]);
        }
    }

    /**
     * GP in ALLEN Rezept-Zeilen durch einen anderen ersetzen (Vorstufe zum Löschen:
     * Referenzen umhängen). Global wie der Katalog selbst; alle betroffenen Rezepte
     * werden anschließend neu berechnet (Yield/Allergene/Kosten + Propagation).
     *
     * @return array{zeilen: int, rezepte: int}
     */
    public function ersetzeInRezepten(FoodAlchemistGp $von, FoodAlchemistGp $nach): array
    {
        $this->pruefeErsatzZiel($von, $nach);

        $recipeIds = FoodAlchemistRecipeIngredient::where('gp_id', $von->id)
            ->distinct()->pluck('recipe_id');
        $zeilen = \Illuminate\Support\Facades\DB::transaction(
            fn () => FoodAlchemistRecipeIngredient::where('gp_id', $von->id)->update(['gp_id' => $nach->id])
        );

        // V-049: die Ersetzung trifft eine Menge — einmal rechnen, nicht je Rezept.
        app(RecipeRecomputeService::class)->recomputeMany($recipeIds->all());

        return ['zeilen' => (int) $zeilen, 'rezepte' => $recipeIds->count()];
    }

    // ── Spec 84 C5: Schreibwege des GP-Modals (vorher direkte Writes in der Komponente) ──────────────────────────────

    /** Meldung wie bisher im Modal (D1). */
    private const GEERBT = 'Geerbtes Katalog-GP — Pflege nur durchs Besitzer-Team (D1).';

    /** GP zum Schreiben: sichtbar UND eigen (isOwnedBy = Curate::canCurate; globale Stamm-Paket-Zeilen pflegt der Master). */
    private function eigenesGp(Team $team, int $gpId): FoodAlchemistGp
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->findOrFail($gpId);
        if (! $gp->isOwnedBy($team)) {
            throw new \Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException('das GP', self::GEERBT);
        }

        return $gp;
    }

    /**
     * Zustand (§9) mit Lineage: 'ki' (manuell gepflegt → abgewiesen; nur §9-Vokabular), 'manual', null = Reset.
     * Der Wert wird über GpNamingService::normalisiereZustand normalisiert.
     */
    public function setzeZustand(Team $team, int $gpId, ?string $quelle, ?string $wert = null, ?float $konfidenz = null, ?string $begruendung = null): FoodAlchemistGp
    {
        $gp = $this->eigenesGp($team, $gpId);
        if ($quelle === null) {
            $gp->update(['condition' => null, 'condition_source' => null, 'condition_ai_confidence' => null, 'condition_ai_reasoning' => null]);

            return $gp->refresh();
        }
        if ($quelle === 'ki' && $gp->condition_source === 'manual') {
            throw new \RuntimeException('condition ist manuell gepflegt — erst Reset (clear), dann KI übernehmen.');
        }
        $naming = app(GpNamingService::class);
        $norm = $naming->normalisiereZustand($wert);
        if ($norm === null || ($quelle === 'ki' && ! in_array($norm, GpNamingService::ZUSTAND_VOCAB, true))) {
            throw new \RuntimeException($quelle === 'ki' ? 'KI-Vorschlag enthält keinen gültigen §9-Zustand.' : 'Kein gültiger Zustand.');
        }
        $gp->update(['condition' => $norm, 'condition_source' => $quelle,
            'condition_ai_confidence' => $quelle === 'ki' ? $konfidenz : null, 'condition_ai_reasoning' => $quelle === 'ki' ? $begruendung : null]);

        return $gp->refresh();
    }

    /**
     * Tags mit Lineage. 'ki': nur die gelieferten Tags, Lineage ki + Zeitstempel (manuell gepflegt → abgewiesen).
     * 'manual': der volle Satz (null = offen); schreibt nur, wenn sich etwas ändert. null: alle Tags + Lineage leer.
     *
     * @param  array<string, bool|null>  $tags  Tag-Name (ohne Präfix) → Wert
     * @return bool ob geschrieben wurde
     */
    public function setzeTags(Team $team, int $gpId, ?string $quelle, array $tags = [], ?float $konfidenz = null, ?string $begruendung = null): bool
    {
        $gp = $this->eigenesGp($team, $gpId);
        if ($quelle === null) {
            $reset = [];
            foreach (FoodAlchemistGp::TAG_FIELDS as $tag) {
                $reset["tag_{$tag}"] = null;
            }
            $gp->update([...$reset, 'tag_source' => null, 'tag_ai_confidence' => null, 'tag_ai_reasoning' => null]);

            return true;
        }
        if ($quelle === 'ki' && $gp->tag_source === 'manual') {
            throw new \RuntimeException('Tags sind manuell gepflegt — erst Reset (clear), dann KI übernehmen.');
        }
        $update = [];
        $geaendert = false;
        foreach (FoodAlchemistGp::TAG_FIELDS as $tag) {
            if (! array_key_exists($tag, $tags)) {
                continue;
            }
            $neu = $tags[$tag] === null ? null : (bool) $tags[$tag];
            $update["tag_{$tag}"] = $neu;
            $alt = $gp->getAttribute("tag_{$tag}");
            $geaendert = $geaendert || ($alt === null ? $neu !== null : $neu !== (bool) $alt);
        }
        if ($quelle === 'ki') {
            if ($update === []) {
                throw new \RuntimeException('KI-Vorschlag enthält keine bekannten Tags.');
            }
            $gp->update([...$update, 'tag_source' => 'ki', 'tag_ai_confidence' => $konfidenz, 'tag_ai_reasoning' => $begruendung, 'tag_aggregated_at' => now()]);

            return true;
        }
        if (! $geaendert) {
            return false;
        }
        $gp->update([...$update, 'tag_source' => $quelle, 'tag_ai_confidence' => null, 'tag_ai_reasoning' => null]);

        return true;
    }

    /** Kalkulations-Defaults (Garverlust, Putzverlust, Stückgewicht). Leer ⇒ NULL, negativ ⇒ 0. */
    public function setzeKalkDefaults(Team $team, int $gpId, array $in): FoodAlchemistGp
    {
        $gp = $this->eigenesGp($team, $gpId);
        $num = fn ($v) => trim((string) $v) === '' ? null : max(0, (float) str_replace(',', '.', (string) $v));
        $gp->update([
            'cooking_loss_default_pct' => $num($in['cooking_loss_default_pct'] ?? ''),
            'trimming_loss_default_pct' => $num($in['trimming_loss_default_pct'] ?? ''),
            'piece_default_g' => $num($in['piece_default_g'] ?? ''),
        ]);

        return $gp->refresh();
    }
}
