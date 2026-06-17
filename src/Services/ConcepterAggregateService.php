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
 * Mengen-Modell (Dominique-Entscheid 2026-06-15, einheit-abhängig): pro Position wird
 * ein Portions-Äquivalent gebildet — Einheit Portion/Stück (oder keine) → `menge` direkt
 * (Default 1.0); Gramm-Einheit → `menge`×g/Einheit ÷ Portionsgramm. EK = ek_total_eur ÷
 * Portionszahl × Portions-Äquivalent. EINE Stelle (`portionsAequivalent()`), genutzt von
 * ConceptService::preisCockpit und PaketService::recomputePrice (Konsistenz-Garant).
 * Ehrlich: Gramm-Position ohne Rezept-Portionsgewicht trägt nicht bei (statt Phantasie-Zahl).
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
            'id', 'name', 'vk_netto', 'ek_total_eur', 'arbeitszeit_min', 'vk_anzahl_einheiten', 'vk_menge_pro_einheit_g',
            'ist_verkaufsrezept',                            // Basis vs. VK — Paket-Posten-Badge + g/P-EK-Zweig
            'yield_kg', 'ertrag_stueck',                     // Stück-Modus (kg↔Stück): Teiler/Gramm aus Ertrag+Yield
            'nutri_kcal_per_100g', 'nutri_protein_g_per_100g', 'nutri_fat_g_per_100g',
            'nutri_carbs_g_per_100g', 'nutri_salt_g_per_100g', 'nutri_konfidenz',
            'spec_is_vegan', 'spec_is_vegetarian', 'spec_is_halal', 'spec_is_gluten_free',
            'spec_is_lactose_free', 'spec_contains_pork', 'spec_contains_beef', 'allergene_konfidenz',
        ];
    }

    /** Einheiten-Spalten für die Mengen-Umrechnung (ein Select, kein N+1). */
    private function einheitCols(): array
    {
        return ['id', 'slug', 'dimension', 'default_in_g'];
    }

    /**
     * Portions-Äquivalent einer Position: wie viele Rezept-Portionen pro Person die Menge
     * entspricht. KANONISCHE Mengen-Umrechnung — von ConceptService::preisCockpit und
     * PaketService::recomputePrice mitgenutzt, damit alle EK-Stellen identisch rechnen.
     *
     * - Portion/Stück oder KEINE Einheit → `menge` direkt (Default 1.0).
     * - Gramm-Einheit (dimension=mass, default_in_g>0) → `menge`×g/Einheit ÷ Portionsgramm.
     * - Gramm gewählt, aber Portionsgewicht (vk_menge_pro_einheit_g) fehlt → null
     *   (Position trägt ehrlich NICHT bei, statt eine erfundene Zahl).
     */
    public static function portionsAequivalent(?float $menge, ?object $einheit, ?object $gericht): ?float
    {
        $istGramm = $einheit !== null
            && $einheit->dimension === 'mass'
            && $einheit->default_in_g !== null
            && (float) $einheit->default_in_g > 0;

        if (! $istGramm) {
            return $menge !== null ? $menge : 1.0;
        }

        if ($menge === null) {
            return null;
        }
        $portionG = $gericht?->vk_menge_pro_einheit_g;
        if ($portionG === null || (float) $portionG <= 0) {
            return null;
        }

        return ($menge * (float) $einheit->default_in_g) / (float) $portionG;
    }

    /**
     * Stück-Modus (kg↔Stück): greift, wenn die Position eine Zähl-Einheit (Portion/Stück) trägt
     * UND das Rezept einen Ertrag in Stück (`ertrag_stueck`) hat. Dann ist 1 verrechnete Einheit
     * = 1 Stück → EK/Stück = ek_total_eur / ertrag_stueck, Gramm/Stück = yield_g / ertrag_stueck.
     * Rückwärtskompatibel: ohne `ertrag_stueck` (alle Bestandsdaten) nie aktiv.
     */
    public static function stueckModus(?object $einheit, ?object $gericht): bool
    {
        return $einheit !== null
            && ($einheit->dimension ?? null) === 'count'
            && $gericht !== null
            && $gericht->ertrag_stueck !== null
            && (float) $gericht->ertrag_stueck > 0;
    }

    // ── Paket-Aggregat ──────────────────────────────────────────────────────

    /**
     * @return array{n_gerichte:int, naehrwerte:array, allergene:array,
     *               ek_pro_person:float, vk_summe:float, arbeitszeit_min:int}
     */
    public function paketAggregat(FoodAlchemistPaket $paket): array
    {
        // load() statt loadMissing(): erzwingt den vollen Spalten-Satz, auch wenn der
        // Aufrufer die Gerichte schon mit reduzierten Spalten geladen hat (z. B. detail()).
        $paket->load([
            'gerichte' => fn ($q) => $q->orderBy('position'),
            'gerichte.einheit' => fn ($q) => $q->select($this->einheitCols()),
            'gerichte.gericht' => fn ($q) => $q->select($this->recipeCols()),
        ]);

        $mitMenge = $paket->gerichte
            ->map(fn ($pg) => ['gericht' => $pg->gericht, 'menge' => $pg->menge, 'einheit' => $pg->einheit])
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
        // load() statt loadMissing(): erzwingt den vollen Recipe-Spalten-Satz, auch wenn
        // der Aufrufer Slots/Gerichte schon mit reduzierten Spalten geladen hat (detail()).
        $concept->load([
            'slots' => fn ($q) => $q->orderBy('position'),
            'slots.einheit' => fn ($q) => $q->select($this->einheitCols()),
            'slots.paket.gerichte' => fn ($q) => $q->orderBy('position'),
            'slots.paket.gerichte.einheit' => fn ($q) => $q->select($this->einheitCols()),
            'slots.paket.gerichte.gericht' => fn ($q) => $q->select($this->recipeCols()),
            'slots.gericht' => fn ($q) => $q->select($this->recipeCols()),
        ]);

        $mitMenge = collect();
        foreach ($concept->slots as $slot) {
            if ($slot->paket) {
                foreach ($slot->paket->gerichte as $pg) {
                    if ($pg->gericht) {
                        $mitMenge->push(['gericht' => $pg->gericht, 'menge' => $pg->menge, 'einheit' => $pg->einheit]);
                    }
                }
            } elseif ($slot->gericht) {
                $mitMenge->push(['gericht' => $slot->gericht, 'menge' => $slot->menge, 'einheit' => $slot->einheit]);
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
        $zeitProPortion = 0.0;
        $ekPositionen = 0;
        $ekBeitragend = 0;
        $gewicht = 0.0;                 // Σ Effektiv-Gramm/Person
        $gewichtVollstaendig = true;    // false, sobald eine Position keine belastbare Gramm-Angabe hat
        foreach ($mitMenge as $r) {
            $zeit += (int) ($r['gericht']->arbeitszeit_min ?? 0);   // Σ roh (Planungsproxy), mengenunabhängig
            $ekPositionen++;

            $pae = self::portionsAequivalent(
                $r['menge'] !== null ? (float) $r['menge'] : null,
                $r['einheit'] ?? null,
                $r['gericht'],
            );
            if ($pae === null) {
                $gewichtVollstaendig = false; // unbekannte Menge → auch Gewicht unvollständig
                continue; // Gramm-Position ohne Portionsgewicht → trägt ehrlich nicht bei
            }
            $ekBeitragend++;
            $stueck = self::stueckModus($r['einheit'] ?? null, $r['gericht']);
            // Teiler von ek_total: Stück-Modus → ertrag_stueck, sonst Portionszahl (Batch→Portion).
            $anzahl = $stueck ? (float) $r['gericht']->ertrag_stueck : max(1, (int) ($r['gericht']->vk_anzahl_einheiten ?? 1));
            $ek += (float) ($r['gericht']->ek_total_eur ?? 0) / $anzahl * $pae;
            $vk += (float) ($r['gericht']->vk_netto ?? 0) * $pae;
            // M-K2: Arbeitszeit/Person = Rezept-Arbeitszeit ÷ Teiler × Portions-Äquivalent.
            $zeitProPortion += (float) ($r['gericht']->arbeitszeit_min ?? 0) / $anzahl * $pae;
            // Gewicht/Person = Portions-Äquivalent × Gramm je Einheit. Stück-Modus: yield_g / ertrag_stueck;
            // sonst Portionsgramm. Fehlt die Basis → Gewicht unvollständig (ehrlich).
            if ($stueck) {
                $yieldKg = $r['gericht']->yield_kg;
                if ($yieldKg !== null && (float) $yieldKg > 0) {
                    $gewicht += $pae * ((float) $yieldKg * 1000 / (float) $r['gericht']->ertrag_stueck);
                } else {
                    $gewichtVollstaendig = false;
                }
            } else {
                $portionG = $r['gericht']->vk_menge_pro_einheit_g;
                if ($portionG !== null && (float) $portionG > 0) {
                    $gewicht += $pae * (float) $portionG;
                } else {
                    $gewichtVollstaendig = false;
                }
            }
        }

        // Allergene: je Gericht EINMAL (Eigenschaft, nicht Portion) → dedupe.
        $distinkt = $mitMenge->pluck('gericht')->filter()->unique('id')->values();

        return [
            'n_gerichte' => $distinkt->count(),
            'naehrwerte' => $this->naehrwertAggregat($mitMenge),
            'allergene' => $this->allergenRollupFromGerichte($distinkt),
            'ek_pro_person' => round($ek, 4),
            'ek_n_positionen' => $ekPositionen,               // kostentragende Positionen gesamt
            'ek_n_beitragend' => $ekBeitragend,               // davon mit belastbarem EK (Lücke = ehrlich aus)
            'vk_summe' => round($vk, 2),
            'gewicht_pro_person_g' => round($gewicht),        // Σ Effektiv-Gramm/Person
            'gewicht_vollstaendig' => $gewichtVollstaendig,   // false → ≥1 Position ohne Portionsgewicht (Gewicht unvollständig)
            'arbeitszeit_min' => $zeit,                       // Σ roher Rezept-Arbeitszeit (Planungsproxy)
            'arbeitszeit_min_pro_portion' => round($zeitProPortion, 2), // Σ je Person (M-K2 Lohn-Block)
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
            $pae = self::portionsAequivalent(
                $row['menge'] !== null ? (float) $row['menge'] : null,
                $row['einheit'] ?? null,
                $g,
            );
            if ($portionG === null || $portionG <= 0 || ! $hatNutri || $pae === null) {
                continue; // unvollständig — trägt nicht bei, deckelt später die Konfidenz
            }
            // Effektive Gramm/Person = Portions-Äquivalent × Portionsgramm.
            $faktor = $pae * ($portionG / 100.0);
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
