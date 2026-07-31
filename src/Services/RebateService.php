<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierRebateConfig;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierRebateTier;

/**
 * Einkauf E1 — Rückvergütung (Volumen-Rabatt-Staffeln) je (Team, Lieferant).
 *
 * Löst das flache suppliers.rebate_pct ab: statt EINEM Prozentwert am globalen
 * Lieferanten führt jedes Team seine eigene Staffel (Schwelle ab € → %) als Overlay.
 * Der effektive Prozentsatz ergibt sich aus:
 *   - manuell gewählter Stufe (config.selected_tier_id), ODER
 *   - höchster aus dem (angenommenen) Jahresumsatz erreichter Stufe (Auto), ODER
 *   - 0, wenn nichts greift.
 * Warengruppen ohne Bonus werden ausgenommen (config.excluded_commodity_groups).
 *
 * Spiegelt getEffectivePercent/getScenarioTierPercent des Vergleichs-Tools.
 * BEWUSST team-scoped, KEINE Kunden-Achse (eigene Session). `$revenueOverride` erlaubt
 * Was-wäre-wenn („bei Umsatz X wäre der Satz Y") ohne die gespeicherte Wahl zu ändern.
 *
 * Schreiben (saveTiers/saveConfig) nur auf einem team-sichtbaren Lieferanten; das
 * Overlay selbst gehört immer dem aktuellen Team (strikt team_id, keine Vererbung —
 * Konditionen sind pro Betrieb verhandelt).
 */
class RebateService
{
    /** Staffelstufen eines Teams für einen Lieferanten, aufsteigend nach Schwelle. */
    public function tiersFor(Team $team, int $supplierId): Collection
    {
        return FoodAlchemistSupplierRebateTier::query()
            ->where('team_id', $team->id)
            ->where('supplier_id', $supplierId)
            ->orderBy('threshold_eur')
            ->orderBy('sort')
            ->get();
    }

    /** 1:1-Konfiguration eines Teams für einen Lieferanten (oder null). */
    public function configFor(Team $team, int $supplierId): ?FoodAlchemistSupplierRebateConfig
    {
        return FoodAlchemistSupplierRebateConfig::query()
            ->where('team_id', $team->id)
            ->where('supplier_id', $supplierId)
            ->first();
    }

    /**
     * Effektiver Rückvergütungs-Prozentsatz für (Team, Lieferant) — 0..100.
     *
     * @param  string|null  $commodityGroup   Warengruppen-Code des Artikels; ausgenommene → 0
     * @param  float|null   $revenueOverride  Was-wäre-wenn-Umsatz; überstimmt die gespeicherte Wahl
     * @param  bool         $flatFallback     ohne Team-Config auf Legacy suppliers.rebate_pct zurückfallen
     */
    public function effektiverProzent(
        Team $team,
        int $supplierId,
        ?string $commodityGroup = null,
        ?float $revenueOverride = null,
        bool $flatFallback = true,
    ): float {
        $config = $this->configFor($team, $supplierId);

        if ($config === null || ! $config->active) {
            return $flatFallback ? $this->flatFallbackProzent($supplierId) : 0.0;
        }

        if ($commodityGroup !== null && ! $this->istImUmfang($config, $commodityGroup)) {
            return 0.0;
        }

        $tiers = $this->tiersFor($team, $supplierId);
        if ($tiers->isEmpty()) {
            return $flatFallback ? $this->flatFallbackProzent($supplierId) : 0.0;
        }

        // Simulation überstimmt die gespeicherte Wahl.
        if ($revenueOverride !== null) {
            return $this->erreichterProzent($tiers, $revenueOverride);
        }
        // Manuell gewählte Stufe.
        if ($config->selected_tier_id !== null) {
            $sel = $tiers->firstWhere('id', $config->selected_tier_id);

            return $sel !== null ? (float) $sel->percent : 0.0;
        }
        // Auto aus angenommenem Jahresumsatz.
        if ($config->assumed_annual_revenue !== null) {
            return $this->erreichterProzent($tiers, (float) $config->assumed_annual_revenue);
        }

        return 0.0;
    }

    /** Nettopreis nach Abzug der Rückvergütung (kaufmännisch auf 4 NK gerundet). */
    public function preisNachRabatt(float $preis, float $prozent): float
    {
        if ($prozent <= 0.0) {
            return $preis;
        }

        return round($preis * (1 - $prozent / 100), 4);
    }

    /**
     * Aufschlüsselung für UI/MCP: welche Stufe greift, aus welcher Quelle, mit welchem %.
     *
     * @return array{aktiv:bool,prozent:float,quelle:string,selected_tier_id:?int,revenue:?float,tiers:list<array>}
     */
    public function stufenInfo(Team $team, int $supplierId, ?float $revenueOverride = null): array
    {
        $config = $this->configFor($team, $supplierId);
        $tiers = $this->tiersFor($team, $supplierId);
        $tiersOut = $tiers->map(fn ($t) => [
            'id' => (int) $t->id,
            'threshold_eur' => (float) $t->threshold_eur,
            'percent' => (float) $t->percent,
        ])->all();

        if ($config === null) {
            return ['aktiv' => false, 'prozent' => $this->flatFallbackProzent($supplierId),
                'quelle' => 'flat_legacy', 'selected_tier_id' => null, 'revenue' => null,
                'applies_to_all' => true, 'commodity_groups' => [], 'tiers' => $tiersOut];
        }

        $revenue = $revenueOverride ?? ($config->assumed_annual_revenue !== null ? (float) $config->assumed_annual_revenue : null);
        $quelle = 'keine';
        if (! $config->active) {
            $quelle = 'inaktiv';
        } elseif ($revenueOverride !== null) {
            $quelle = 'simulation';
        } elseif ($config->selected_tier_id !== null) {
            $quelle = 'manuell';
        } elseif ($config->assumed_annual_revenue !== null) {
            $quelle = 'auto_umsatz';
        }

        return [
            'aktiv' => (bool) $config->active,
            'prozent' => $this->effektiverProzent($team, $supplierId, null, $revenueOverride, false),
            'quelle' => $quelle,
            'selected_tier_id' => $config->selected_tier_id !== null ? (int) $config->selected_tier_id : null,
            'revenue' => $revenue,
            'applies_to_all' => (bool) $config->applies_to_all,
            'commodity_groups' => is_array($config->commodity_groups) ? $config->commodity_groups : [],
            'tiers' => $tiersOut,
        ];
    }

    /**
     * Staffel eines Teams für einen Lieferanten ersetzen (Replace-Set). Sortiert nach
     * Schwelle, vergibt sort neu. Eine zuvor manuell gewählte Stufe wird — sofern die
     * gleiche Schwelle erhalten bleibt — auf die neue Stufe umgehängt, sonst genullt.
     *
     * @param  list<array{threshold_eur:float|int|string,percent:float|int|string}>  $tiers
     */
    public function saveTiers(Team $team, int $supplierId, array $tiers): Collection
    {
        $this->assertSichtbar($team, $supplierId);

        // Nur plausible Stufen, aufsteigend nach Schwelle.
        $rows = collect($tiers)
            ->map(fn ($t) => [
                'threshold_eur' => round((float) ($t['threshold_eur'] ?? 0), 2),
                'percent' => round((float) ($t['percent'] ?? 0), 2),
            ])
            ->filter(fn ($t) => $t['percent'] > 0)
            ->unique('threshold_eur')
            ->sortBy('threshold_eur')
            ->values();

        return DB::transaction(function () use ($team, $supplierId, $rows) {
            $config = $this->configFor($team, $supplierId);
            $prevThreshold = null;
            if ($config?->selected_tier_id !== null && $config !== null) {
                $prevThreshold = optional(FoodAlchemistSupplierRebateTier::withTrashed()
                    ->find($config->selected_tier_id))->threshold_eur;
                $prevThreshold = $prevThreshold !== null ? round((float) $prevThreshold, 2) : null;
            }

            // Hart entfernen, damit der selected_tier_id-FK (nullOnDelete) sauber greift.
            FoodAlchemistSupplierRebateTier::withTrashed()
                ->where('team_id', $team->id)->where('supplier_id', $supplierId)
                ->forceDelete();

            $created = collect();
            foreach ($rows as $i => $row) {
                $created->push(FoodAlchemistSupplierRebateTier::create([
                    'team_id' => $team->id,
                    'supplier_id' => $supplierId,
                    'threshold_eur' => $row['threshold_eur'],
                    'percent' => $row['percent'],
                    'sort' => $i,
                ]));
            }

            // Manuelle Wahl auf gleiche Schwelle umhängen, falls vorhanden.
            if ($config !== null && $prevThreshold !== null) {
                $match = $created->first(fn ($t) => round((float) $t->threshold_eur, 2) === $prevThreshold);
                $config->update(['selected_tier_id' => $match?->id]);
            }

            return $created;
        });
    }

    /**
     * Konfiguration upserten (nur übergebene Keys): active, selected_tier_id,
     * assumed_annual_revenue, excluded_commodity_groups.
     */
    public function saveConfig(Team $team, int $supplierId, array $input): FoodAlchemistSupplierRebateConfig
    {
        $this->assertSichtbar($team, $supplierId);

        $config = FoodAlchemistSupplierRebateConfig::firstOrNew([
            'team_id' => $team->id,
            'supplier_id' => $supplierId,
        ]);

        if (array_key_exists('active', $input)) {
            $config->active = (bool) $input['active'];
        }
        if (array_key_exists('assumed_annual_revenue', $input)) {
            $rev = $input['assumed_annual_revenue'];
            $config->assumed_annual_revenue = ($rev === '' || $rev === null) ? null : round((float) $rev, 2);
        }
        if (array_key_exists('applies_to_all', $input)) {
            $config->applies_to_all = (bool) $input['applies_to_all'];
        }
        if (array_key_exists('commodity_groups', $input)) {
            $wg = $input['commodity_groups'];
            $config->commodity_groups = is_array($wg) ? array_values(array_filter(array_map('strval', $wg))) : null;
        }
        if (array_key_exists('selected_tier_id', $input)) {
            $tid = $input['selected_tier_id'];
            if ($tid === '' || $tid === null) {
                $config->selected_tier_id = null;
            } else {
                // Nur eine Stufe dieses (Team, Lieferant) darf gewählt werden.
                $valid = FoodAlchemistSupplierRebateTier::where('team_id', $team->id)
                    ->where('supplier_id', $supplierId)->whereKey((int) $tid)->exists();
                $config->selected_tier_id = $valid ? (int) $tid : null;
            }
        }

        $config->save();

        return $config;
    }

    /**
     * Overlay über eine LeadLaService::rangliste-Collection: setzt je Zeile
     * `rabatt_prozent` + `vergleichspreis_mit_rabatt_wert`. NON-INVASIV — ändert weder
     * die rangliste selbst noch deren Sortierung; die Vergleichs-/Optimierungs-Sicht
     * (Cockpit) entscheidet, ob sie nach dem effektiven Preis re-sortiert.
     *
     * Rückvergütung ist ein rückwirkender Jahresbonus, KEIN Zeilen-Rabatt — daher hier
     * als „effektiver Netto-Preis" fürs Vergleichen, nicht als gebuchter Bestellpreis.
     *
     * @param  Collection<int, \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem>  $rangliste
     * @return Collection<int, \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem>
     */
    public function enrichRangliste(Team $team, Collection $rangliste, ?string $commodityGroup = null, ?float $revenueOverride = null): Collection
    {
        $memo = [];
        foreach ($rangliste as $la) {
            $sid = (int) $la->supplier_id;
            if (! array_key_exists($sid, $memo)) {
                $memo[$sid] = $this->effektiverProzent($team, $sid, $commodityGroup, $revenueOverride);
            }
            $pct = $memo[$sid];
            $preis = $la->vergleichspreis_wert ?? null;
            $la->setAttribute('rabatt_prozent', $pct);
            $la->setAttribute('vergleichspreis_mit_rabatt_wert',
                $preis === null ? null : $this->preisNachRabatt((float) $preis, $pct));
        }

        return $rangliste;
    }

    /** Höchster Prozentsatz, dessen Schwelle vom Umsatz erreicht ist (0, wenn keine). */
    private function erreichterProzent(Collection $tiers, float $revenue): float
    {
        $pct = 0.0;
        foreach ($tiers as $t) {
            if ((float) $t->threshold_eur <= $revenue) {
                $pct = (float) $t->percent;
            }
        }

        return $pct;
    }

    /** Greift die Rückvergütung für diese Warengruppe? Vollsortiment ODER explizit gewählt. */
    private function istImUmfang(FoodAlchemistSupplierRebateConfig $config, string $commodityGroup): bool
    {
        if ($config->applies_to_all) {
            return true;
        }
        $wg = $config->commodity_groups;

        return is_array($wg) && in_array($commodityGroup, $wg, true);
    }

    /** Legacy: flacher rebate_pct am globalen Lieferanten (Bestandsschutz). */
    private function flatFallbackProzent(int $supplierId): float
    {
        $val = FoodAlchemistSupplier::whereKey($supplierId)->value('rebate_pct');

        return $val !== null ? (float) $val : 0.0;
    }

    /** Schreiben nur auf einem team-sichtbaren Lieferanten (D1). */
    private function assertSichtbar(Team $team, int $supplierId): void
    {
        if (! FoodAlchemistSupplier::visibleToTeam($team)->whereKey($supplierId)->exists()) {
            throw new \RuntimeException('Lieferant nicht im Zugriff (D1).');
        }
    }
}
