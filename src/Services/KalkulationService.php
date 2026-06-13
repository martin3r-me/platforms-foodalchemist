<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * M12 / Doc 15 §M12 (D-HK-1, entschieden): Herstellkosten als Zuschlagskalkulation.
 *
 *   HK1 = Wareneinsatz, verlustkorrigiert (recipes.ek_total_eur — GL-02 rechnet
 *         Garverlust/Putzverlust pro Position bereits ein).
 *   HK2 = HK1 × (1 + Gemeinkosten-Zuschlag%) + Energie-/Nebenkosten.
 *
 * Liefert die Food-seitige VOLLKOSTEN-Marge (VK netto gegen HK2) — die ehrliche
 * Kosten-Sicht, getrennt von der reinen Wareneinsatz-Marge (MargeService/GL-02).
 * Zuschlagssatz = Team-Setting (anfangs pauschal; später differenziert → M15).
 */
class KalkulationService
{
    public function __construct(
        private TeamSettingsService $settings,
        private ConceptService $concepts,
    ) {
    }

    /** HK2 aus HK1 + Zuschlag + Nebenkosten. */
    public function hk2(Team $team, float $hk1, float $nebenkosten = 0.0): float
    {
        return round($hk1 * (1 + $this->settings->hk2Zuschlag($team) / 100) + $nebenkosten, 4);
    }

    /**
     * Gericht-Kalkulation pro Portion (HK1/HK2 + Vollkosten-Marge gegen VK netto).
     *
     * @return array{hk1_total: float, hk2_total: float, hk1_pro_portion: float, hk2_pro_portion: float,
     *               zuschlag_pct: float, nebenkosten: float, anzahl_portionen: int,
     *               vk_netto: ?float, db_eur: ?float, db_pct: ?float, wareneinsatz_pct: ?float}
     */
    public function recipeHk(Team $team, FoodAlchemistRecipe $recipe): array
    {
        $anzahl = max(1, (int) ($recipe->vk_anzahl_einheiten ?? 1));
        $hk1Total = (float) ($recipe->ek_total_eur ?? 0);
        $neben = (float) ($recipe->nebenkosten_eur ?? 0);
        $hk2Total = $this->hk2($team, $hk1Total, $neben);
        $hk1Pp = round($hk1Total / $anzahl, 4);
        $hk2Pp = round($hk2Total / $anzahl, 4);
        $vk = $recipe->vk_netto !== null ? (float) $recipe->vk_netto : null;

        return [
            'hk1_total' => round($hk1Total, 4),
            'hk2_total' => $hk2Total,
            'hk1_pro_portion' => $hk1Pp,
            'hk2_pro_portion' => $hk2Pp,
            'zuschlag_pct' => $this->settings->hk2Zuschlag($team),
            'nebenkosten' => round($neben, 4),
            'anzahl_portionen' => $anzahl,
            'vk_netto' => $vk,
            'db_eur' => $vk !== null ? round($vk - $hk2Pp, 2) : null,                 // Deckungsbeitrag gegen Vollkosten
            'db_pct' => $vk !== null && $vk > 0 ? round(($vk - $hk2Pp) / $vk * 100, 1) : null,
            'wareneinsatz_pct' => $vk !== null && $vk > 0 ? round($hk1Pp / $vk * 100, 1) : null,
        ];
    }

    /**
     * Concept-Kalkulation pro Person: HK1 = Σ Wareneinsatz/Person, HK2 mit Zuschlag,
     * Vollkosten-Marge gegen den Concept-€/Person (Output-Preis).
     *
     * @return array{hk1_pro_person: float, hk2_pro_person: float, vk_pro_person: float, db_eur: ?float, db_pct: ?float}
     */
    public function conceptHk(Team $team, FoodAlchemistConcept $concept): array
    {
        $cockpit = $this->concepts->preisCockpit($concept);
        $hk1 = (float) $cockpit['ek_pro_person'];
        $hk2 = $this->hk2($team, $hk1);
        $vk = (float) $cockpit['preis_pro_person'];

        return [
            'hk1_pro_person' => round($hk1, 4),
            'hk2_pro_person' => $hk2,
            'vk_pro_person' => $vk,
            'db_eur' => $vk > 0 ? round($vk - $hk2, 2) : null,
            'db_pct' => $vk > 0 ? round(($vk - $hk2) / $vk * 100, 1) : null,
        ];
    }
}
