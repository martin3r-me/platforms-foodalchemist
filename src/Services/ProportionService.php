<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * #513 Tier 1 / Punkt 1 — deterministischer Grammaturen-Rechner (Landeplatz A).
 *
 * Vier exakte Formeln, die BHG-Köche real nutzen: Bäckerprozent (Rezept auf X Pax
 * skalieren ohne Präzisionsverlust), Extraprozent (Hydrokolloid-Dosis stabil aufs
 * Gesamtgewicht), Brining (Lake ansetzen) und Gelatine-Bloom-Umrechnung (Marke
 * tauschen). Quelle: Modernist Cuisine Bd. 2 (Ratios/Scaling) + Bd. 3 (Brining, Gele).
 *
 * GRAMMATUREN-REGEL (Dominique, verbindlich): Das Datenmodell bleibt gramm-/yield-
 * basiert. Prozente sind eine ABGELEITETE SICHT (Bäckerprozent), kein Ersatz — dieser
 * Service RECHNET nur, er schreibt keine Prozente ins Rezept.
 *
 * Reine, stateless Kalkulatoren (keine Formel je im LLM-Prompt — GL: Formel = Code).
 * Die Rezept-Sicht spiegelt die Masse über RecipeRecomputeService::bruttoMasseG
 * (identische T1-Kaskade wie Yield/Kosten — eine Regel-Stelle, keine Drift).
 */
class ProportionService
{
    /**
     * Typische Sortenstärke deutscher Blattgelatine (Bloom) — DGF-Sortengrade
     * (Bronze/Silber/Gold/Platin), Mittelwert der publizierten Ranges. REFERENZ,
     * kein erfundener Messwert: die konkrete Herstellerangabe hat immer Vorrang.
     * Bronze ~125–155, Silber ~160, Gold ~190–220, Platin ~235–265.
     */
    public const BLOOM_BLATTGELATINE = [
        'bronze' => 140.0,
        'silber' => 160.0,
        'gold' => 200.0,
        'platin' => 250.0,
    ];

    // ── Bäckerprozent (Referenzzutat = 100 %) ──────────────────────────────

    /** pct_i = m_i / m_ref × 100. m_ref ≤ 0 → null (keine sinnvolle Basis). */
    public function bakerPercent(float $massG, float $refMassG): ?float
    {
        if ($refMassG <= 0.0) {
            return null;
        }

        return $massG / $refMassG * 100.0;
    }

    /** Rückweg: m_i = pct_i/100 × m_ref. */
    public function bakerMass(float $pct, float $refMassG): float
    {
        return $pct / 100.0 * $refMassG;
    }

    // ── Extraprozent (Hydrokolloide/Salz/Säure aufs Gesamtgewicht) ─────────

    /**
     * m_extra = pct_extra/100 × Σ(alle anderen g). Bezug aufs Gesamtgewicht der
     * übrigen Komponenten → bleibt stabil, wenn eine Komponente wegfällt (anders
     * als Bäckerprozent, das an EINER Referenz hängt). Für Hydrokolloide/Salz/Säure.
     */
    public function extraMass(float $pctExtra, float $sumOtherG): float
    {
        return $pctExtra / 100.0 * $sumOtherG;
    }

    /** Rückweg: pct_extra = m_extra / Σ(andere) × 100. Σ ≤ 0 → null. */
    public function extraPercent(float $massG, float $sumOtherG): ?float
    {
        if ($sumOtherG <= 0.0) {
            return null;
        }

        return $massG / $sumOtherG * 100.0;
    }

    // ── Brining (Equilibrium) ──────────────────────────────────────────────

    /**
     * Benötigte Lake-Masse, um Startgewicht M auf Ziel-Salinität d zu bringen:
     * brine = d · M / S. Kontrolle: brine × S = d · M → geliefertes Salz = d % von M.
     * d (Ziel-Salinität) und S (Salzgehalt der Lake) MÜSSEN dieselbe Einheit haben
     * (beide % oder beide Bruch). S ≤ 0 → null. Quelle: Modernist Cuisine Bd. 3.
     */
    public function briningBrineMassG(float $startMassG, float $targetSalinity, float $brineSalt): ?float
    {
        if ($brineSalt <= 0.0) {
            return null;
        }

        return $targetSalinity * $startMassG / $brineSalt;
    }

    /** Ziel-Gesamtgewicht T = M + Lake-Masse. Null, wenn die Lake-Masse null ist. */
    public function briningTotalG(float $startMassG, float $targetSalinity, float $brineSalt): ?float
    {
        $brine = $this->briningBrineMassG($startMassG, $targetSalinity, $brineSalt);

        return $brine === null ? null : $startMassG + $brine;
    }

    // ── Gelatine-Bloom-Umrechnung (Marke A → B) ────────────────────────────

    /**
     * Gleiche Gelfestigkeit bei Sortenwechsel: M_B = M_A · Bloom_A / Bloom_B
     * (Gelfestigkeit ∝ Masse × Bloom → konstant halten). Bloom_B ≤ 0 → null.
     * Quelle: Modernist Cuisine Bd. 3.
     */
    public function bloomConvert(float $massA_g, float $bloomA, float $bloomB): ?float
    {
        if ($bloomB <= 0.0) {
            return null;
        }

        return $massA_g * $bloomA / $bloomB;
    }

    /** Bloom-Umrechnung über die Sortengrade (bronze/silber/gold/platin). Unbekannt → null. */
    public function bloomConvertBySorte(float $massA_g, string $sorteA, string $sorteB): ?float
    {
        $a = self::BLOOM_BLATTGELATINE[mb_strtolower(trim($sorteA))] ?? null;
        $b = self::BLOOM_BLATTGELATINE[mb_strtolower(trim($sorteB))] ?? null;
        if ($a === null || $b === null) {
            return null;
        }

        return $this->bloomConvert($massA_g, $a, $b);
    }

    // ── Bäckerprozent-Sicht eines Rezepts (abgeleitet, Grammatur bleibt Master) ──

    /**
     * Bäckerprozent je Zutat eines Rezepts. Referenz = $refIngredientId, sonst die
     * massereichste Zutat (Standard-Konvention: schwerste = 100 %). Masse pro Zeile
     * über RecipeRecomputeService::bruttoMasseG (identische T1-Kaskade wie Yield/
     * Kosten). Team-gescoped (D1). Zeilen ohne Gramm-Umrechnung (Zähl-Einheit ohne
     * Default) → mass_g 0.0, baker_percent null (als Lücke erkennbar).
     *
     * @return array{ref_ingredient_id: ?int, ref_mass_g: float, lines: list<array{ingredient_id:int, name:string, mass_g:float, baker_percent:?float}>}
     */
    public function bakerPercentagesForRecipe(Team $team, int $recipeId, ?int $refIngredientId = null): array
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)
            ->with(['ingredients.unit', 'ingredients.gp', 'ingredients.referencedRecipe'])
            ->findOrFail($recipeId);

        $recompute = app(RecipeRecomputeService::class);
        $rows = [];
        foreach ($recipe->ingredients as $z) {
            $rows[] = [
                'ingredient' => $z,
                'ingredient_id' => (int) $z->id,
                'name' => (string) ($z->display_name ?: $z->raw_text ?: ($z->gp?->name ?? 'Zutat')),
                'mass_g' => round($recompute->bruttoMasseG($z), 3),
            ];
        }

        // Referenz bestimmen: explizit gewählt, sonst schwerste Zeile (>0 g).
        $ref = null;
        if ($refIngredientId !== null) {
            foreach ($rows as $r) {
                if ($r['ingredient_id'] === $refIngredientId) {
                    $ref = $r;
                    break;
                }
            }
        }
        if ($ref === null) {
            foreach ($rows as $r) {
                if ($ref === null || $r['mass_g'] > $ref['mass_g']) {
                    $ref = $r;
                }
            }
        }
        $refMassG = $ref !== null ? (float) $ref['mass_g'] : 0.0;

        $lines = [];
        foreach ($rows as $r) {
            $lines[] = [
                'ingredient_id' => $r['ingredient_id'],
                'name' => $r['name'],
                'mass_g' => $r['mass_g'],
                'baker_percent' => $this->bakerPercent((float) $r['mass_g'], $refMassG) !== null
                    ? round($this->bakerPercent((float) $r['mass_g'], $refMassG), 2)
                    : null,
            ];
        }

        return [
            'ref_ingredient_id' => $ref['ingredient_id'] ?? null,
            'ref_mass_g' => round($refMassG, 3),
            'lines' => $lines,
        ];
    }
}
