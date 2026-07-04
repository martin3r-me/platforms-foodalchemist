<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Exceptions\FormelNichtDefiniertException;

/**
 * M6-02 / D-6 §3.2: Single Source of Truth für die VK-Mathematik — reine
 * Berechnungs-Klasse (kein DB-Write), normativ GL-02 §3.6 + Invariante I9
 * (Recompute schreibt vk_* NIEMALS; der Vorschlag ist abgeleitet, nicht
 * persistiert). Livewire bindet die Methoden als computed properties —
 * keine clientseitige Doppel-Implementierung (Alt-App-Drift-Risiko, §6).
 *
 * W-1-Disziplin (D-6 §6): formula_type='deckungsbeitrag' ist als Stammdatum
 * anlegbar, der Vorschlags-Pfad wirft aber eine typisierte Exception statt
 * still falsch zu rechnen — bis der D6-Entscheid steht (08_ENTSCHEIDUNGEN).
 */
class MargeService
{
    /**
     * VK-Vorschlag aus EK + Aufschlagsklasse (GL-02 §3.6, GT-8):
     *   ek_basis  = ek_per_kg_eur × sales_quantity_per_unit_g / 1000
     *   sales_net  = ek_basis × (1 + raw_markup_pct/100)        [aufschlag]
     *   sales_gross = ROUND(sales_net × (1 + mwst/100), 2)
     *
     * @param  object  $klasse  markup_class-Zeile (raw_markup_pct, vat_rate, formula_type)
     * @param  ?float  $mwstSatz  Rezept-MwSt schlägt Klassen-Default
     * @return ?array{ek_basis: float, sales_net: float, sales_gross: float, vat_rate: float, formel: string}
     */
    public function vkVorschlag(?float $ekPerKgEur, ?float $vkMengeProEinheitG, object $klasse, ?float $mwstSatz = null): ?array
    {
        if (($klasse->formula_type ?? 'aufschlag') === 'deckungsbeitrag') {
            throw new FormelNichtDefiniertException(
                "Aufschlagsklasse {$klasse->code}: formula_type 'deckungsbeitrag' ist nicht definiert (W-1, 08_ENTSCHEIDUNGEN D6) — Formel-Entscheid ausstehend."
            );
        }
        if ($ekPerKgEur === null || $vkMengeProEinheitG === null || $vkMengeProEinheitG <= 0) {
            return null;                                             // kein EK / keine Portionierung → leer, nie Fehler
        }

        $mwst = $mwstSatz ?? (float) ($klasse->vat_rate ?? 19);
        $ekBasis = $ekPerKgEur * $vkMengeProEinheitG / 1000;
        $vkNetto = $ekBasis * (1 + ((float) $klasse->raw_markup_pct) / 100);

        return [
            'ek_basis' => round($ekBasis, 4),
            'sales_net' => round($vkNetto, 2),
            'sales_gross' => round(round($vkNetto, 2) * (1 + $mwst / 100), 2),
            'vat_rate' => $mwst,
            'formel' => sprintf('VK = EK × (1 + %s%%) · brutto × (1 + %s%% MwSt)',
                rtrim(rtrim(number_format((float) $klasse->raw_markup_pct, 2, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format($mwst, 2, '.', ''), '0'), '.')),
        ];
    }

    /**
     * Marge auf Charge-Ebene (Gesamt-EK des Rezepts gegen VK netto):
     * Marge € / Marge % / Wareneinsatz % — margePct + wePct = 100 (gleiche Basis).
     *
     * @return ?array{marge_eur: float, marge_pct: float, wareneinsatz_pct: float}
     */
    public function marge(?float $vkNetto, ?float $ekTotalEur): ?array
    {
        if ($vkNetto === null || $vkNetto <= 0 || $ekTotalEur === null) {
            return null;
        }

        return [
            'marge_eur' => round($vkNetto - $ekTotalEur, 2),
            'marge_pct' => round(($vkNetto - $ekTotalEur) / $vkNetto * 100, 1),
            'wareneinsatz_pct' => round($ekTotalEur / $vkNetto * 100, 1),
        ];
    }

    /**
     * Zerlegung auf die Verkaufseinheit: netto/Anzahl, brutto je Einheit.
     *
     * @return ?array{vk_netto_pro_einheit: float, vk_brutto_pro_einheit: float}
     */
    public function proEinheit(?float $vkNetto, ?int $anzahlEinheiten, float $mwstSatz): ?array
    {
        if ($vkNetto === null || $anzahlEinheiten === null || $anzahlEinheiten <= 0) {
            return null;
        }
        $nettoProEinheit = $vkNetto / $anzahlEinheiten;

        return [
            'vk_netto_pro_einheit' => round($nettoProEinheit, 2),
            'vk_brutto_pro_einheit' => round($nettoProEinheit * (1 + $mwstSatz / 100), 2),
        ];
    }

    /**
     * Cockpit-Logik (Alt-Cockpit übernommen): manueller sales_net GEWINNT gegen
     * den Klassen-Vorschlag; Rückgabe markiert die Quelle.
     *
     * @return array{sales_net: ?float, source: string, vorschlag: ?array}
     */
    public function effektiverVk(?float $vkNettoManuell, ?float $ekPerKgEur, ?float $vkMengeProEinheitG, ?object $klasse, ?float $mwstSatz = null): array
    {
        $vorschlag = $klasse !== null ? $this->vkVorschlag($ekPerKgEur, $vkMengeProEinheitG, $klasse, $mwstSatz) : null;
        if ($vkNettoManuell !== null && $vkNettoManuell > 0) {
            return ['sales_net' => $vkNettoManuell, 'source' => 'manuell', 'vorschlag' => $vorschlag];
        }

        return ['sales_net' => $vorschlag['sales_net'] ?? null, 'source' => $vorschlag !== null ? 'class' : 'leer', 'vorschlag' => $vorschlag];
    }
}
