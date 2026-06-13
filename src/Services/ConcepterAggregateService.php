<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;

/**
 * M10R-1 / Doc 15 §10.5 + §10.8: Voll-Aggregation Gericht → Paket → Concept.
 * Rollt aus den VK-Gerichten hoch: Nährwerte/Person · Allergene/Diät · Kosten
 * (EK = HK1-Proxy / VK) · Produktionszeit (`arbeitszeit_min`). Speist die
 * Kalkulation, die deterministische Menü-Bewertung (M10R-3) und gibt der KI
 * Kontext + Selbst-Check (§10.10).
 *
 * Ehrlichkeits-Prinzip (wie Allergen-Rollup): Nährwerte werden NUR aus Gerichten
 * gerechnet, die sowohl Nährwert-Daten (pro 100 g) ALS AUCH ein Portionsgewicht
 * (`vk_menge_pro_einheit_g`) haben — fehlt eins, trägt das Gericht nicht bei und
 * die Konfidenz sinkt (statt erfundener Zahlen). Konfidenz = schwächstes Glied.
 *
 * Mengen-Modell: ein `menge`-Faktor je Gericht (Erwartungsmenge/Person, B-06) wird
 * als Multiplikator genutzt (Default 1.0) — konsistent zu PaketService::recomputePrice
 * und ConceptService::mengenHochrechnung.
 *
 * Pure (keine Service-Abhängigkeiten) — wird von ConceptService/PaketService und den
 * Livewire-Panels genutzt, niemals direkt von Models (Plattform-Gebot).
 */
class ConcepterAggregateService
{
    private const KONF_RANG = ['unknown' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];

    /** Recipe-Spalten, die alle Rollups brauchen (ein Select, kein N+1). */
    private function recipeCols(): array
    {
        return [
            'id', 'name', 'vk_netto', 'ek_total_eur', 'arbeitszeit_min', 'vk_menge_pro_einheit_g',
            'nutri_kcal_per_100g', 'nutri_protein_g_per_100g', 'nutri_fat_g_per_100g',
            'nutri_carbs_g_per_100g', 'nutri_salt_g_per_100g', 'nutri_konfidenz',
            'spec_is_vegan', 'spec_is_vegetarian', 'spec_is_halal', 'spec_is_gluten_free',
            'spec_is_lactose_free', 'spec_contains_pork', 'spec_contains_beef', 'allergene_konfidenz',
        ];
    }

    // ── Paket-Aggregat ──────────────────────────────────────────────────────

    /**
     * @return array{n_gerichte:int, naehrwerte:array, allergene:array,
     *               ek_pro_person:float, vk_summe:float, arbeitszeit_min:int}
     */
    public function paketAggregat(FoodAlchemistPaket $paket): array
    {
        $paket->loadMissing([
            'gerichte' => fn ($q) => $q->orderBy('position'),
            'gerichte.gericht' => fn ($q) => $q->select($this->recipeCols()),
        ]);

        $mitMenge = $paket->gerichte
            ->map(fn ($pg) => ['gericht' => $pg->gericht, 'menge' => $pg->menge])
            ->filter(fn ($r) => $r['gericht'] !== null)->values();

        return $this->aggregat($mitMenge);
    }

    // ── Concept-Aggregat (über Pakete + feste Gerichte) ──────────────────────

    /**
     * @return array{n_gerichte:int, n_slots:int, naehrwerte:array, allergene:array,
     *               ek_pro_person:float, vk_summe:float, arbeitszeit_min:int}
     */
    public function conceptAggregat(FoodAlchemistConcept $concept): array
    {
        $concept->loadMissing([
            'slots' => fn ($q) => $q->orderBy('position'),
            'slots.paket.gerichte' => fn ($q) => $q->orderBy('position'),
            'slots.paket.gerichte.gericht' => fn ($q) => $q->select($this->recipeCols()),
            'slots.gericht' => fn ($q) => $q->select($this->recipeCols()),
        ]);

        $mitMenge = collect();
        foreach ($concept->slots as $slot) {
            if ($slot->paket) {
                foreach ($slot->paket->gerichte as $pg) {
                    if ($pg->gericht) {
                        $mitMenge->push(['gericht' => $pg->gericht, 'menge' => $pg->menge]);
                    }
                }
            } elseif ($slot->gericht) {
                $mitMenge->push(['gericht' => $slot->gericht, 'menge' => $slot->menge]);
            }
        }

        return ['n_slots' => $concept->slots->count()] + $this->aggregat($mitMenge);
    }

    // ── Kern: Aggregat über eine (gericht, menge)-Liste ─────────────────────

    /** @param Collection<int, array{gericht: object, menge: ?float}> $mitMenge */
    private function aggregat(Collection $mitMenge): array
    {
        $ek = 0.0;
        $vk = 0.0;
        $zeit = 0;
        foreach ($mitMenge as $r) {
            $faktor = $r['menge'] !== null ? (float) $r['menge'] : 1.0;
            $ek += (float) ($r['gericht']->ek_total_eur ?? 0) * $faktor;
            $vk += (float) ($r['gericht']->vk_netto ?? 0) * $faktor;
            $zeit += (int) ($r['gericht']->arbeitszeit_min ?? 0);
        }

        // Allergene: je Gericht EINMAL (Eigenschaft, nicht Portion) → dedupe.
        $distinkt = $mitMenge->pluck('gericht')->filter()->unique('id')->values();

        return [
            'n_gerichte' => $distinkt->count(),
            'naehrwerte' => $this->naehrwertAggregat($mitMenge),
            'allergene' => $this->allergenRollupFromGerichte($distinkt),
            'ek_pro_person' => round($ek, 4),
            'vk_summe' => round($vk, 2),
            'arbeitszeit_min' => $zeit,
        ];
    }

    /**
     * Nährwerte/Person = Σ (pro 100 g × Portionsgramm/100 × Menge-Faktor).
     *
     * @param  Collection<int, array{gericht: object, menge: ?float}>  $mitMenge
     * @return array{kcal:?float, protein_g:?float, fett_g:?float, kh_g:?float, salz_g:?float,
     *               n_gerichte:int, n_mit_naehrwerten:int, vollstaendig:bool, konfidenz:string}
     */
    public function naehrwertAggregat(Collection $mitMenge): array
    {
        $felder = [
            'kcal' => 'nutri_kcal_per_100g', 'protein' => 'nutri_protein_g_per_100g',
            'fett' => 'nutri_fat_g_per_100g', 'kh' => 'nutri_carbs_g_per_100g',
            'salz' => 'nutri_salt_g_per_100g',
        ];
        $summe = ['kcal' => 0.0, 'protein' => 0.0, 'fett' => 0.0, 'kh' => 0.0, 'salz' => 0.0];
        $nTotal = 0;
        $nOk = 0;
        $minKonf = null;

        foreach ($mitMenge as $row) {
            $g = $row['gericht'] ?? null;
            if ($g === null) {
                continue;
            }
            $nTotal++;
            $portionG = $g->vk_menge_pro_einheit_g !== null ? (float) $g->vk_menge_pro_einheit_g : null;
            $hatNutri = $g->nutri_kcal_per_100g !== null;
            if ($portionG === null || $portionG <= 0 || ! $hatNutri) {
                continue; // unvollständig — trägt nicht bei, deckelt später die Konfidenz
            }
            $faktor = ($row['menge'] !== null ? (float) $row['menge'] : 1.0) * ($portionG / 100.0);
            foreach ($felder as $key => $spalte) {
                if ($g->{$spalte} !== null) {
                    $summe[$key] += (float) $g->{$spalte} * $faktor;
                }
            }
            $nOk++;
            $k = self::KONF_RANG[$g->nutri_konfidenz] ?? 0;
            $minKonf = $minKonf === null ? $k : min($minKonf, $k);
        }

        $vollstaendig = $nTotal > 0 && $nOk === $nTotal;
        $konfRang = $minKonf ?? 0;
        if (! $vollstaendig) {
            $konfRang = min($konfRang, self::KONF_RANG['low']); // Lücken → max „low"
        }

        return [
            'kcal' => $nOk ? round($summe['kcal']) : null,
            'protein_g' => $nOk ? round($summe['protein'], 1) : null,
            'fett_g' => $nOk ? round($summe['fett'], 1) : null,
            'kh_g' => $nOk ? round($summe['kh'], 1) : null,
            'salz_g' => $nOk ? round($summe['salz'], 2) : null,
            'n_gerichte' => $nTotal,
            'n_mit_naehrwerten' => $nOk,
            'vollstaendig' => $vollstaendig,
            'konfidenz' => $nOk === 0 ? 'unknown' : (array_search($konfRang, self::KONF_RANG, true) ?: 'unknown'),
        ];
    }

    /**
     * Kanonische Allergen-/Diät-Rollup-Stelle (Doc 15 §10.5: „aus den Gerichten,
     * kein manuelles Gruppieren"). „all"-Flags (vegan/vegetarisch/halal/glutenfrei/
     * laktosefrei) nur wenn ALLE Gerichte sie erfüllen; „enthält"-Flags (Schwein/
     * Rind) bei MIND. EINEM. Konfidenz = schwächstes Glied.
     *
     * @param  Collection<int, object>  $gerichte  bereits deduplizierte Recipe-Sammlung
     * @return array{n_gerichte:int, is_vegan:bool, is_vegetarian:bool, is_halal:bool,
     *               is_gluten_free:bool, is_lactose_free:bool, contains_pork:bool,
     *               contains_beef:bool, konfidenz:string}
     */
    public function allergenRollupFromGerichte(Collection $gerichte): array
    {
        $gerichte = $gerichte->filter()->unique('id')->values();
        $alle = fn (string $feld) => $gerichte->isNotEmpty() && $gerichte->every(fn ($g) => (bool) $g->{$feld});
        $eines = fn (string $feld) => $gerichte->contains(fn ($g) => (bool) $g->{$feld});
        $minKonf = $gerichte->isEmpty() ? 0 : $gerichte->min(fn ($g) => self::KONF_RANG[$g->allergene_konfidenz] ?? 0);

        return [
            'n_gerichte' => $gerichte->count(),
            'is_vegan' => $alle('spec_is_vegan'),
            'is_vegetarian' => $alle('spec_is_vegetarian'),
            'is_halal' => $alle('spec_is_halal'),
            'is_gluten_free' => $alle('spec_is_gluten_free'),
            'is_lactose_free' => $alle('spec_is_lactose_free'),
            'contains_pork' => $eines('spec_contains_pork'),
            'contains_beef' => $eines('spec_contains_beef'),
            'konfidenz' => array_search($minKonf, self::KONF_RANG, true) ?: 'unknown',
        ];
    }

    // ── Cache-Persistenz ─────────────────────────────────────────────────────

    public function cachePaket(FoodAlchemistPaket $paket): FoodAlchemistPaket
    {
        $agg = $this->paketAggregat($paket);
        $paket->update([
            'naehrwerte_cache' => $agg['naehrwerte'],
            'arbeitszeit_min_cache' => $agg['arbeitszeit_min'],
        ]);

        return $paket->refresh();
    }

    public function cacheConcept(FoodAlchemistConcept $concept): FoodAlchemistConcept
    {
        $agg = $this->conceptAggregat($concept);
        $concept->update([
            'naehrwerte_cache' => $agg['naehrwerte'],
            'arbeitszeit_min_cache' => $agg['arbeitszeit_min'],
            'ek_pro_person_cache' => $agg['ek_pro_person'],
        ]);

        return $concept->refresh();
    }
}
