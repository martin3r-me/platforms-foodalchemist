<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistGpLaPreference;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * M3-06: Lead-LA-Wahl (GL-03) + V-27-Auflösung.
 *
 * Kaskade T1 als SORTIER-Kriterien (I3 — keine Filter; auch ein discontinued LA
 * ohne Preis wird Lead, wenn es keinen besseren gibt):
 *   0 Strategie-Stufe (M1-05: stamm_lieferant | guenstigster_preis | prioritaets_kette)
 *   1 Aktivität (aktiv vor discontinued)
 *   2 hat aktiven Preis (GL-11: standard_ek | aktion)
 *   3 Vergleichspreis — NULL ans ENDE (A-2 NULLS LAST, PHP-seitig ⇒ engine-agnostisch)
 *   4 Lieferantenname alphabetisch
 *   5 supplier_item_id ASC (I1 Determinismus)
 *
 * V-27: EIN globaler Default-Lead (gps.lead_la_supplier_item_id); Team-Sperren/Pins
 * leben im Overlay (gp_la_preferences). Effektiver Lead = Pin, sonst erster nicht
 * gesperrter Rang. Pins überleben Bulk-Neuwahl (Bulk schreibt nur die globale Spalte).
 * A-3 (Einheiten-Mix €/kg vs €/l vs €/Stk im Preisvergleich) bleibt Ist-konform —
 * dokumentierte Abweichung, Normalisierung ist GL-11-Folgearbeit.
 */
class LeadLaService
{
    public function __construct(
        private TeamSettingsService $settings,
        private StammLieferantService $stamm,
        private PriceService $preise,
    ) {
    }

    /**
     * Vollständige Rangliste (V-27-Kette): Rang 1 = Lead-Kandidat, Rest = Ausweich-LAs.
     * Jeder Eintrag trägt vergleichspreis/ist_stamm/gesperrt/gepinnt fürs Panel (M3-07).
     *
     * @return Collection<int, FoodAlchemistSupplierItem>
     */
    public function rangliste(FoodAlchemistGp $gp, Team $team): Collection
    {
        $kandidaten = $this->kandidaten($gp);
        if ($kandidaten->isEmpty()) {
            return $kandidaten;
        }

        $strategie = $this->settings->leadLaStrategie($team);
        $prioritaeten = array_map('intval', $this->settings->leadLaPrioritaeten($team));
        $stammIds = array_map('intval', $this->stamm->stammSupplierIdsFor($team, $gp->warengruppe_code));
        $overlay = FoodAlchemistGpLaPreference::where('team_id', $team->id)->where('gp_id', $gp->id)
            ->get()->keyBy('supplier_item_id');

        return $kandidaten
            ->each(function ($la) use ($overlay, $stammIds) {
                $pref = $overlay->get($la->id);
                $la->setAttribute('ist_stamm', in_array((int) $la->supplier_id, $stammIds, true));
                $la->setAttribute('gesperrt', (bool) ($pref?->gesperrt ?? false));
                $la->setAttribute('gepinnt', (bool) ($pref?->gepinnt ?? false));
            })
            ->sortBy(fn ($la) => [
                match ($strategie) {                                       // Stufe 0 (V-27-Strategie)
                    LeadLaStrategie::GuenstigsterPreis => 0,
                    LeadLaStrategie::StammLieferant => $la->ist_stamm ? 0 : 1,
                    LeadLaStrategie::PrioritaetsKette => ($pos = array_search((int) $la->supplier_id, $prioritaeten, true)) !== false ? $pos : PHP_INT_MAX,
                },
                $la->is_discontinued ? 1 : 0,                              // Stufe 1
                $la->hat_aktiven_preis ? 0 : 1,                            // Stufe 2
                $la->vergleichspreis_wert === null ? 1 : 0,                // Stufe 3: NULLS LAST (A-2!)
                $la->vergleichspreis_wert ?? PHP_FLOAT_MAX,
                mb_strtolower((string) $la->supplier_name),                // Stufe 4
                (int) $la->id,                                             // Stufe 5 (I1)
            ])
            ->values();
    }

    /** Heuristik-Sieger (Rang 1) — ohne Overlay; NULL bei Phantom-GP (GT-3). */
    public function pickLeadLa(FoodAlchemistGp $gp, Team $team): ?int
    {
        return $this->rangliste($gp, $team)->first()?->id;
    }

    /**
     * Schreibt den GLOBALEN Default-Lead (Arbeits-Annahme ⚠D1: EIN globaler Lead,
     * berechnet aus Sicht des kuratierenden Teams; Team-Abweichungen nur im Overlay).
     */
    public function applyLeadLa(FoodAlchemistGp $gp, Team $team): ?int
    {
        $lead = $this->pickLeadLa($gp, $team);
        $gp->update(['lead_la_supplier_item_id' => $lead]);

        return $lead;
    }

    /**
     * Effektiver Lead des Teams (V-27): Pin gewinnt (sofern verknüpft + nicht gesperrt),
     * sonst erster nicht gesperrter Rang der Kette.
     */
    public function effektiverLead(FoodAlchemistGp $gp, Team $team): ?FoodAlchemistSupplierItem
    {
        $kette = $this->rangliste($gp, $team);

        return $kette->first(fn ($la) => $la->gepinnt && ! $la->gesperrt)
            ?? $kette->first(fn ($la) => ! $la->gesperrt);
    }

    /** Manueller Override des globalen Leads (GT-6): LA muss zum GP gehören (I2), NULL erlaubt. */
    public function setLeadLa(Team $team, FoodAlchemistGp $gp, ?int $laId): void
    {
        if ($laId !== null && ! $this->gehoertZuGp($gp, $laId)) {
            throw new \RuntimeException("LA [{$laId}] ist nicht mit GP [{$gp->name}] verknüpft (GL-03 I2).");
        }
        $gp->update(['lead_la_supplier_item_id' => $laId]);
    }

    /** Team-Sperre (V-27): LA fällt aus der effektiven Kette dieses Teams. */
    public function sperren(Team $team, FoodAlchemistGp $gp, int $laId, bool $gesperrt = true): void
    {
        if (! $this->gehoertZuGp($gp, $laId)) {
            throw new \RuntimeException("LA [{$laId}] ist nicht mit GP [{$gp->name}] verknüpft (GL-03 I2).");
        }
        FoodAlchemistGpLaPreference::withTrashed()
            ->updateOrCreate(
                ['team_id' => $team->id, 'gp_id' => $gp->id, 'supplier_item_id' => $laId],
                ['gesperrt' => $gesperrt, 'deleted_at' => null],
            );
        $this->raeumeLeerePrefsAuf($team, $gp);
    }

    /** Team-Pin (V-27): fixiert den effektiven Lead, überlebt Bulk-Neuwahl. NULL = Pin lösen. */
    public function pinnen(Team $team, FoodAlchemistGp $gp, ?int $laId): void
    {
        if ($laId !== null && ! $this->gehoertZuGp($gp, $laId)) {
            throw new \RuntimeException("LA [{$laId}] ist nicht mit GP [{$gp->name}] verknüpft (GL-03 I2).");
        }

        DB::transaction(function () use ($team, $gp, $laId) {
            FoodAlchemistGpLaPreference::where('team_id', $team->id)->where('gp_id', $gp->id)
                ->where('gepinnt', true)->update(['gepinnt' => false]);     // max. ein Pin pro GP/Team
            if ($laId !== null) {
                FoodAlchemistGpLaPreference::withTrashed()->updateOrCreate(
                    ['team_id' => $team->id, 'gp_id' => $gp->id, 'supplier_item_id' => $laId],
                    ['gepinnt' => true, 'deleted_at' => null],
                );
            }
        });
        $this->raeumeLeerePrefsAuf($team, $gp);
    }

    /**
     * LA↔GP-Verknüpfung lösen (GT-5): Struktur-Mapping weg, n_las_total runter;
     * war der LA der globale Lead ⇒ sofortige Neuwahl (I4).
     */
    public function entknuepfen(Team $team, FoodAlchemistGp $gp, int $laId): void
    {
        $struktur = DB::table('foodalchemist_supplier_item_structures')
            ->where('gp_id', $gp->id)->where('supplier_item_id', $laId)->whereNull('deleted_at')->first();
        if ($struktur === null) {
            throw new \RuntimeException("LA [{$laId}] ist nicht mit GP [{$gp->name}] verknüpft.");
        }

        DB::transaction(function () use ($gp, $laId, $struktur, $team) {
            DB::table('foodalchemist_supplier_item_structures')->where('id', $struktur->id)
                ->update(['gp_id' => null, 'updated_at' => now()]);
            $gp->update(['n_las_total' => max(0, (int) $gp->n_las_total - 1)]);

            if ((int) $gp->lead_la_supplier_item_id === $laId) {
                $this->applyLeadLa($gp->refresh(), $team);                 // I4: sofortige Neuwahl
            }
        });
    }

    /** LA an GP verknüpfen (Gegenstück, M3-07-Aktion): Struktur-Update + Lead-Neuwahl-Trigger (T3). */
    public function verknuepfen(Team $team, FoodAlchemistGp $gp, int $laId): void
    {
        $struktur = DB::table('foodalchemist_supplier_item_structures')
            ->where('supplier_item_id', $laId)->whereNull('deleted_at')->first();

        DB::transaction(function () use ($gp, $laId, $struktur, $team) {
            if ($struktur === null) {
                throw new \RuntimeException("LA [{$laId}] hat keine Struktur-Zeile — Anlage fehlt (M2).");
            }
            if ($struktur->gp_id !== null && (int) $struktur->gp_id !== $gp->id) {
                throw new \RuntimeException('LA ist bereits einem anderen GP zugeordnet — erst dort lösen (GL-05).');
            }
            DB::table('foodalchemist_supplier_item_structures')->where('id', $struktur->id)
                ->update(['gp_id' => $gp->id, 'updated_at' => now()]);
            $gp->update(['n_las_total' => (int) $gp->n_las_total + 1]);
            $this->applyLeadLa($gp->refresh(), $team);                     // T3: Verknüpfen triggert Neuwahl
        });
    }

    /** M3-07-Picker: verknüpfbare LAs (Struktur-Zeile mit gp_id NULL), Suche über designation. */
    public function sucheVerknuepfbare(Team $team, string $suche, int $limit = 8): Collection
    {
        if (trim($suche) === '') {
            return collect();
        }

        return FoodAlchemistSupplierItem::query()
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'foodalchemist_supplier_items.id')
            ->leftJoin('foodalchemist_suppliers AS sup', 'sup.id', '=', 'foodalchemist_supplier_items.supplier_id')
            ->whereNull('s.gp_id')
            ->whereNull('s.deleted_at')
            ->whereRaw('LOWER(foodalchemist_supplier_items.designation) LIKE ?', ['%' . mb_strtolower(trim($suche)) . '%'])
            ->orderBy('foodalchemist_supplier_items.designation')
            ->limit($limit)
            ->select('foodalchemist_supplier_items.*', 'sup.name AS supplier_name')
            ->get();
    }

    // ── intern ───────────────────────────────────────────────────────────

    /** Kandidaten-Menge (I2): alle LAs mit struktur.gp_id == gp, inkl. Preis-Annotation. */
    private function kandidaten(FoodAlchemistGp $gp): Collection
    {
        $las = FoodAlchemistSupplierItem::query()
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'foodalchemist_supplier_items.id')
            ->leftJoin('foodalchemist_suppliers AS sup', 'sup.id', '=', 'foodalchemist_supplier_items.supplier_id')
            ->where('s.gp_id', $gp->id)
            ->whereNull('s.deleted_at')
            ->select('foodalchemist_supplier_items.*', 'sup.name AS supplier_name')
            ->selectSub($this->preise->activePriceSubquery()->toBase(), 'aktiver_preis')
            ->get();

        return $las->each(function ($la) {
            // scopeAktiv (GL-11 I3) liefert nur standard_ek/aktion ⇒ Subquery-Treffer == „hat aktiven Preis" (Stufe 2)
            $la->setAttribute('hat_aktiven_preis', $la->aktiver_preis !== null);
            $vp = $la->aktiver_preis !== null ? $this->preise->vergleichspreis($la, (float) $la->aktiver_preis) : null;
            $la->setAttribute('vergleichspreis', $vp);
            $la->setAttribute('vergleichspreis_wert', $vp['wert'] ?? null);   // qty NULL ⇒ NULL ⇒ ans Ende (A-2)
        });
    }

    private function gehoertZuGp(FoodAlchemistGp $gp, int $laId): bool
    {
        return DB::table('foodalchemist_supplier_item_structures')
            ->where('gp_id', $gp->id)->where('supplier_item_id', $laId)->whereNull('deleted_at')->exists();
    }

    /** Zeilen ohne Wirkung (weder Sperre noch Pin) wieder entfernen — Overlay bleibt klein. */
    private function raeumeLeerePrefsAuf(Team $team, FoodAlchemistGp $gp): void
    {
        FoodAlchemistGpLaPreference::where('team_id', $team->id)->where('gp_id', $gp->id)
            ->where('gesperrt', false)->where('gepinnt', false)->delete();
    }
}
