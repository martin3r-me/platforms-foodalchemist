<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * M12 + M-K1/Doc 16: Herstellkosten als STRUKTURIERTE Zuschlagskalkulation.
 *
 *   HK1 = Wareneinsatz (ek_total_eur, GL-02-verlustkorrigiert).
 *   HK2 = HK1 + Σ benannte Kostenblöcke (Lohn · Verpackung · Schwund · Lager ·
 *         Gemeinkosten — Team-Schema, Doc 16) + ggf. Nebenkosten (Rezept).
 *   VK-Vorschlag = HK2 × (1 + Marge).
 *   Deckungsbeitrag = gesetzter VK − HK2 (Vollkosten-Sicht).
 *
 * Block-Typen: pct_we (% auf WE) · pct_hk (% auf laufende HK) · eur_pro_portion
 * (Fixbetrag) · arbeitszeit (min/60 × Stundensatz). Ersetzt den einen M12-Regler;
 * der bestehende hk2_surcharge_pct lebt als Default-Wert des Gemeinkosten-Blocks weiter.
 */
class KalkulationService
{
    public function __construct(
        private TeamSettingsService $settings,
        private ConceptService $concepts,
        private ConcepterAggregateService $aggregat,
        private FixkostenService $fixkosten,
    ) {
    }

    /**
     * Kern: MEHRSTUFIGE Zuschlagskalkulation (D-K8, produzierendes Gewerbe).
     *
     *   MEK = Wareneinsatz · FEK = Σ Lohn (arbeitszeit) · direkt = Σ eur_pro_portion + Nebenkosten
     *   MGK = Σ pct_mek × MEK · FGK = Σ pct_fek × FEK
     *   HK (Herstellkosten) = MEK + FEK + direkt + MGK + FGK
     *   HKGK = Σ pct_hk × HK  (Verwaltung/Vertrieb, Logistik)
     *   HK2 (Selbstkosten) = HK + HKGK · VK-Vorschlag = HK2 × (1 + Marge)
     *
     * @return array{bloecke: list<array{key:string,label:string,typ:string,betrag:float}>,
     *               hk2: float, hk: float, mek: float, fek: float, marge_pct: float, vk_vorschlag: float}
     */
    public function berechne(Team $team, float $we, float $arbeitszeitMin = 0.0, float $nebenkosten = 0.0): array
    {
        $stundensatz = $this->settings->stundensatz($team);
        // M-K6: Schema mit aus Fixkosten abgeleiteten %-Sätzen (abgeleitete Blöcke).
        $schema = $this->fixkosten->aufgeloestesSchema($team);
        $aktiv = array_values(array_filter($schema, fn ($b) => $b['active']));

        // #379+: Lohnnebenkosten-Zuschlag (AG-Anteil) → effektiver Lohnsatz statt Brutto.
        $lnkFaktor = 1 + $this->settings->lohnnebenkostenPct($team) / 100;
        $rate = fn (array $b) => ($b['value'] > 0 ? $b['value'] : $stundensatz) * $lnkFaktor;

        // ── Stufe A: Basisgrößen (reihenfolge-unabhängig) ───────────────────
        $mek = max(0.0, $we);
        $fek = 0.0;
        $direkt = abs($nebenkosten) > 1e-9 ? $nebenkosten : 0.0;
        foreach ($aktiv as $b) {
            if ($b['type'] === 'arbeitszeit') {
                $fek += $arbeitszeitMin / 60 * $rate($b);
            } elseif ($b['type'] === 'eur_pro_portion') {
                $direkt += (float) $b['value'];
            }
        }
        $mgkTotal = 0.0;
        $fgkTotal = 0.0;
        foreach ($aktiv as $b) {
            if ($b['type'] === 'pct_mek') {
                $mgkTotal += $mek * ($b['value'] / 100);
            } elseif ($b['type'] === 'pct_fek') {
                $fgkTotal += $fek * ($b['value'] / 100);
            }
        }
        $hk = $mek + $fek + $direkt + $mgkTotal + $fgkTotal;   // Herstellkosten

        // ── Stufe B: Wasserfall-Blöcke in Sort-Reihenfolge (Anzeige) ────────
        $bloecke = [['key' => 'we', 'label' => 'Wareneinsatz (MEK)', 'type' => 'basis', 'betrag' => round($mek, 4)]];
        $hkgkTotal = 0.0;
        foreach ($aktiv as $b) {
            $betrag = match ($b['type']) {
                'arbeitszeit' => $arbeitszeitMin / 60 * $rate($b),
                'eur_pro_portion' => (float) $b['value'],
                'pct_mek' => $mek * ($b['value'] / 100),
                'pct_fek' => $fek * ($b['value'] / 100),
                'pct_hk' => $hk * ($b['value'] / 100),
                default => 0.0,
            };
            if ($b['type'] === 'pct_hk') {
                $hkgkTotal += $betrag;
            }
            $bloecke[] = ['key' => $b['key'], 'label' => $b['label'], 'type' => $b['type'], 'betrag' => round($betrag, 4)];
        }
        if (abs($nebenkosten) > 1e-9) {
            $bloecke[] = ['key' => 'nebenkosten', 'label' => 'Nebenkosten (Rezept)', 'type' => 'eur_pro_portion', 'betrag' => round($nebenkosten, 4)];
        }

        $hk2 = round($hk + $hkgkTotal, 4);   // Selbstkosten
        $marge = $this->settings->margePct($team);

        return [
            'bloecke' => $bloecke,
            'hk2' => $hk2,
            'hk' => round($hk, 4),
            'mek' => round($mek, 4),
            'fek' => round($fek, 4),
            'marge_pct' => $marge,
            'vk_vorschlag' => round($hk2 * (1 + $marge / 100), 2),
        ];
    }

    /** Kompatibilitäts-Helfer (M12): HK2 aus HK1 (+ optionale Nebenkosten), via Schema. */
    public function hk2(Team $team, float $hk1, float $nebenkosten = 0.0): float
    {
        return $this->berechne($team, $hk1, 0.0, $nebenkosten)['hk2'];
    }

    /**
     * Gericht-Kalkulation pro Portion (Block-Wasserfall + Vollkosten-Marge gegen VK netto).
     *
     * @return array{hk1_total: float, hk2_total: float, hk1_pro_portion: float, hk2_pro_portion: float,
     *               zuschlag_pct: float, nebenkosten: float, anzahl_portionen: int, sales_net: ?float,
     *               db_eur: ?float, db_pct: ?float, wareneinsatz_pct: ?float,
     *               bloecke: list<array>, marge_pct: float, vk_vorschlag: float}
     */
    public function recipeHk(Team $team, FoodAlchemistRecipe $recipe): array
    {
        $anzahl = max(1, (int) ($recipe->sales_unit_count ?? 1));
        $hk1Total = (float) ($recipe->ek_total_eur ?? 0);
        $nebenTotal = (float) ($recipe->additional_costs_eur ?? 0);
        $azTotal = (float) ($recipe->work_time_min ?? 0);

        // Pro Portion rechnen (Wasserfall), dann auf Total skalieren.
        $r = $this->berechne($team, $hk1Total / $anzahl, $azTotal / $anzahl, $nebenTotal / $anzahl);
        $hk1Pp = round($hk1Total / $anzahl, 4);
        $hk2Pp = $r['hk2'];
        $vk = $recipe->sales_net !== null ? (float) $recipe->sales_net : null;

        return [
            'hk1_total' => round($hk1Total, 4),
            'hk2_total' => round($hk2Pp * $anzahl, 4),
            'hk1_pro_portion' => $hk1Pp,
            'hk2_pro_portion' => $hk2Pp,
            'zuschlag_pct' => $this->settings->hk2Zuschlag($team),
            'nebenkosten' => round($nebenTotal, 4),
            'anzahl_portionen' => $anzahl,
            'sales_net' => $vk,
            'db_eur' => $vk !== null ? round($vk - $hk2Pp, 2) : null,
            'db_pct' => $vk !== null && $vk > 0 ? round(($vk - $hk2Pp) / $vk * 100, 1) : null,
            'wareneinsatz_pct' => $vk !== null && $vk > 0 ? round($hk1Pp / $vk * 100, 1) : null,
            'bloecke' => $r['bloecke'],
            'marge_pct' => $r['marge_pct'],
            'vk_vorschlag' => $r['vk_vorschlag'],
        ];
    }

    /**
     * Concept-Kalkulation pro Person: HK1 = Σ Wareneinsatz/Person (preisCockpit), Lohn
     * aus dem Arbeitszeit-Rollup/Person (M-K2), Vollkosten-Marge gegen den Concept-€/Person.
     *
     * @return array{hk1_pro_person: float, hk2_pro_person: float, vk_pro_person: float,
     *               db_eur: ?float, db_pct: ?float, bloecke: list<array>, marge_pct: float, vk_vorschlag: float}
     */
    public function conceptHk(Team $team, FoodAlchemistConcept $concept): array
    {
        $cockpit = $this->concepts->preisCockpit($concept);
        $hk1 = (float) $cockpit['ek_per_person'];
        $az = (float) ($this->aggregat->conceptAggregat($concept)['arbeitszeit_min_pro_portion'] ?? 0);
        $r = $this->berechne($team, $hk1, $az, 0.0);
        $vk = (float) $cockpit['price_per_person'];

        return [
            'hk1_pro_person' => round($hk1, 4),
            'hk2_pro_person' => $r['hk2'],
            'vk_pro_person' => $vk,
            'db_eur' => $vk > 0 ? round($vk - $r['hk2'], 2) : null,
            'db_pct' => $vk > 0 ? round(($vk - $r['hk2']) / $vk * 100, 1) : null,
            'bloecke' => $r['bloecke'],
            'marge_pct' => $r['marge_pct'],
            'vk_vorschlag' => $r['vk_vorschlag'],
        ];
    }

    /**
     * Paket-Kalkulation pro Person: HK1 = gespeicherter EK/Person (Buffet) bzw. aus den
     * Gerichten; Lohn aus dem Arbeitszeit-Rollup; Marge gegen den Paket-€/Person.
     *
     * @return array{hk1_pro_person: float, hk2_pro_person: float, vk_pro_person: ?float,
     *               db_eur: ?float, db_pct: ?float, bloecke: list<array>, marge_pct: float, vk_vorschlag: float}
     */
    public function paketHk(Team $team, FoodAlchemistPaket $paket): array
    {
        $agg = $this->aggregat->paketAggregat($paket);
        $hk1 = $paket->ek_per_person !== null ? (float) $paket->ek_per_person : (float) $agg['ek_per_person'];
        $az = (float) ($agg['arbeitszeit_min_pro_portion'] ?? 0);
        $r = $this->berechne($team, $hk1, $az, 0.0);
        $vk = $paket->price_per_person !== null ? (float) $paket->price_per_person : null;

        return [
            'hk1_pro_person' => round($hk1, 4),
            'hk2_pro_person' => $r['hk2'],
            'vk_pro_person' => $vk,
            'db_eur' => $vk !== null && $vk > 0 ? round($vk - $r['hk2'], 2) : null,
            'db_pct' => $vk !== null && $vk > 0 ? round(($vk - $r['hk2']) / $vk * 100, 1) : null,
            'bloecke' => $r['bloecke'],
            'marge_pct' => $r['marge_pct'],
            'vk_vorschlag' => $r['vk_vorschlag'],
        ];
    }
}
