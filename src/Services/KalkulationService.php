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
 * der bestehende hk2_zuschlag_pct lebt als Default-Wert des Gemeinkosten-Blocks weiter.
 */
class KalkulationService
{
    public function __construct(
        private TeamSettingsService $settings,
        private ConceptService $concepts,
        private ConcepterAggregateService $aggregat,
    ) {
    }

    /**
     * Kern: Block-Wasserfall WE → +Blöcke → HK2 → VK-Vorschlag.
     *
     * @return array{bloecke: list<array{key:string,label:string,typ:string,betrag:float}>,
     *               hk2: float, marge_pct: float, vk_vorschlag: float}
     */
    public function berechne(Team $team, float $we, float $arbeitszeitMin = 0.0, float $nebenkosten = 0.0): array
    {
        $stundensatz = $this->settings->stundensatz($team);
        $running = max(0.0, $we);
        $bloecke = [['key' => 'we', 'label' => 'Wareneinsatz', 'typ' => 'basis', 'betrag' => round($we, 4)]];

        foreach ($this->settings->kalkulationSchema($team) as $b) {
            if (! $b['aktiv']) {
                continue;
            }
            $betrag = match ($b['typ']) {
                'pct_we' => $we * ($b['wert'] / 100),
                'pct_hk' => $running * ($b['wert'] / 100),
                'eur_pro_portion' => $b['wert'],
                'arbeitszeit' => $arbeitszeitMin / 60 * ($b['wert'] > 0 ? $b['wert'] : $stundensatz),
                default => 0.0,
            };
            $running += $betrag;
            $bloecke[] = ['key' => $b['key'], 'label' => $b['label'], 'typ' => $b['typ'], 'betrag' => round($betrag, 4)];
        }

        // Rezept-spezifische Nebenkosten zuletzt (Gemeinkosten-% greift NICHT darauf — M12-Verhalten).
        if (abs($nebenkosten) > 1e-9) {
            $running += $nebenkosten;
            $bloecke[] = ['key' => 'nebenkosten', 'label' => 'Nebenkosten (Rezept)', 'typ' => 'eur_pro_portion', 'betrag' => round($nebenkosten, 4)];
        }

        $hk2 = round($running, 4);
        $marge = $this->settings->margePct($team);

        return [
            'bloecke' => $bloecke,
            'hk2' => $hk2,
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
     *               zuschlag_pct: float, nebenkosten: float, anzahl_portionen: int, vk_netto: ?float,
     *               db_eur: ?float, db_pct: ?float, wareneinsatz_pct: ?float,
     *               bloecke: list<array>, marge_pct: float, vk_vorschlag: float}
     */
    public function recipeHk(Team $team, FoodAlchemistRecipe $recipe): array
    {
        $anzahl = max(1, (int) ($recipe->vk_anzahl_einheiten ?? 1));
        $hk1Total = (float) ($recipe->ek_total_eur ?? 0);
        $nebenTotal = (float) ($recipe->nebenkosten_eur ?? 0);
        $azTotal = (float) ($recipe->arbeitszeit_min ?? 0);

        // Pro Portion rechnen (Wasserfall), dann auf Total skalieren.
        $r = $this->berechne($team, $hk1Total / $anzahl, $azTotal / $anzahl, $nebenTotal / $anzahl);
        $hk1Pp = round($hk1Total / $anzahl, 4);
        $hk2Pp = $r['hk2'];
        $vk = $recipe->vk_netto !== null ? (float) $recipe->vk_netto : null;

        return [
            'hk1_total' => round($hk1Total, 4),
            'hk2_total' => round($hk2Pp * $anzahl, 4),
            'hk1_pro_portion' => $hk1Pp,
            'hk2_pro_portion' => $hk2Pp,
            'zuschlag_pct' => $this->settings->hk2Zuschlag($team),
            'nebenkosten' => round($nebenTotal, 4),
            'anzahl_portionen' => $anzahl,
            'vk_netto' => $vk,
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
        $hk1 = (float) $cockpit['ek_pro_person'];
        $az = (float) ($this->aggregat->conceptAggregat($concept)['arbeitszeit_min_pro_portion'] ?? 0);
        $r = $this->berechne($team, $hk1, $az, 0.0);
        $vk = (float) $cockpit['preis_pro_person'];

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
        $hk1 = $paket->ek_pro_person !== null ? (float) $paket->ek_pro_person : (float) $agg['ek_pro_person'];
        $az = (float) ($agg['arbeitszeit_min_pro_portion'] ?? 0);
        $r = $this->berechne($team, $hk1, $az, 0.0);
        $vk = $paket->preis_pro_person !== null ? (float) $paket->preis_pro_person : null;

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
