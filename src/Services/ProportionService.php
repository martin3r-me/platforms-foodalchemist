<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
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

    // ── %→Gramm-Rückschreiben (Grammatur bleibt Master, % ist der Editier-Hebel) ──
    // Zwei bewusst getrennte Absichten (Entscheid Dominique 2026-07-18):
    //  A rescaleRecipe/rescaleToReferenceMass — Batch-Skalierung: ALLE Mengen × Faktor,
    //    %-Verhältnisse bleiben (Rezept auf 100 Pax). Einheiten-neutral (reine Multiplikation).
    //  B setIngredientBakerPercent — Einzel-Zutat übers %: g = pct/100 × Referenzmasse,
    //    zurück in die Zutat-Einheit. NUR Masse-Einheiten (Stück/Liter read-only, weil %
    //    massebasiert ist). Beides schreibt NUR Gramm/Menge, nie ein Prozent → Recompute.

    /** D1: Schreiben nur durchs Besitzer-Team (geerbte Katalog-Rezepte sind read-only). */
    private function assertOwner(Team $team, FoodAlchemistRecipe $recipe): void
    {
        if ((int) $recipe->team_id !== (int) $team->id) {
            throw new \RuntimeException('Geerbtes Rezept — Mengen-Änderung nur durchs Besitzer-Team (D1).');
        }
    }

    /**
     * Modus A — Batch-Skalierung: jede Zutat-Menge × Faktor (auch quantity_max).
     * Einheiten-neutral (Stück/Liter/g gleichermaßen), %-Verhältnisse bleiben erhalten.
     * Faktor ≤ 0 verboten. Danach Recompute (Yield/Kosten/Allergene skalieren mit).
     *
     * @return array{recipe: FoodAlchemistRecipe, factor: float, changes: list<array{ingredient_id:int, name:string, old_quantity:float, new_quantity:float}>}
     */
    public function rescaleRecipe(Team $team, int $recipeId, float $factor): array
    {
        if ($factor <= 0.0) {
            throw new \RuntimeException('Skalierungs-Faktor muss > 0 sein.');
        }
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->with('ingredients')->findOrFail($recipeId);
        $this->assertOwner($team, $recipe);

        $changes = [];
        DB::transaction(function () use ($recipe, $factor, &$changes) {
            foreach ($recipe->ingredients as $z) {
                $alt = (float) $z->quantity;
                $neu = round($alt * $factor, 4);
                $z->update([
                    'quantity' => $neu,
                    'quantity_max' => $z->quantity_max !== null ? round((float) $z->quantity_max * $factor, 4) : null,
                ]);
                $changes[] = [
                    'ingredient_id' => (int) $z->id,
                    'name' => (string) ($z->display_name ?: $z->raw_text ?: 'Zutat'),
                    'old_quantity' => $alt,
                    'new_quantity' => $neu,
                ];
            }
        });
        app(RecipeRecomputeService::class)->recomputeAndPropagate($recipe->id);

        return ['recipe' => $recipe->refresh(), 'factor' => $factor, 'changes' => $changes];
    }

    /**
     * Modus A bequem: „setze die Referenzzutat auf X g" → Faktor = X / aktuelle Ref-Masse,
     * dann rescaleRecipe. Ref = $refIngredientId, sonst schwerste Zutat.
     */
    public function rescaleToReferenceMass(Team $team, int $recipeId, float $newRefMassG, ?int $refIngredientId = null): array
    {
        if ($newRefMassG <= 0.0) {
            throw new \RuntimeException('Neue Referenzmasse muss > 0 g sein.');
        }
        $sicht = $this->bakerPercentagesForRecipe($team, $recipeId, $refIngredientId);
        $aktuell = (float) $sicht['ref_mass_g'];
        if ($aktuell <= 0.0) {
            throw new \RuntimeException('Referenzzutat hat keine Gramm-Masse (Zähl-/Volumen-Einheit ohne Umrechnung).');
        }

        return $this->rescaleRecipe($team, $recipeId, $newRefMassG / $aktuell);
    }

    /**
     * Modus B — eine Zutat übers Bäckerprozent justieren: Zielmasse = pct/100 × Ref-Masse,
     * zurück in die Zutat-Einheit (quantity = g / default_in_g). Einheiten-Guard: NUR
     * Masse-Dimension; Stück/Volumen → RuntimeException (% ist massebasiert, kein sauberer
     * Rückweg). Setzt eine EXAKTE Menge → quantity_max wird geleert. Danach Recompute.
     *
     * @return array{recipe: FoodAlchemistRecipe, ingredient_id: int, baker_percent: float, ref_mass_g: float, new_mass_g: float, new_quantity: float}
     */
    public function setIngredientBakerPercent(Team $team, int $recipeId, int $ingredientId, float $pct, ?int $refIngredientId = null): array
    {
        if ($pct < 0.0) {
            throw new \RuntimeException('Bäckerprozent darf nicht negativ sein.');
        }
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)
            ->with(['ingredients.unit', 'ingredients.gp', 'ingredients.referencedRecipe'])
            ->findOrFail($recipeId);
        $this->assertOwner($team, $recipe);

        $recompute = app(RecipeRecomputeService::class);

        // Referenzmasse bestimmen (explizit oder schwerste Zutat) — dieselbe Logik wie die Sicht.
        $refMassG = 0.0;
        $refPick = null;
        foreach ($recipe->ingredients as $z) {
            $g = $recompute->bruttoMasseG($z);
            if ($refIngredientId !== null ? ((int) $z->id === $refIngredientId) : ($refPick === null || $g > $refMassG)) {
                $refMassG = $g;
                $refPick = $z;
            }
        }
        if ($refMassG <= 0.0) {
            throw new \RuntimeException('Keine gültige Referenzmasse in Gramm — %-Edit nicht möglich.');
        }

        $target = $recipe->ingredients->firstWhere('id', $ingredientId);
        if ($target === null) {
            throw new \RuntimeException('Zutat gehört nicht zu diesem Rezept.');
        }
        $unit = $target->unit;
        if ($unit?->dimension !== 'mass' || $unit->default_in_g === null || (float) $unit->default_in_g <= 0.0) {
            throw new \RuntimeException(
                'Einheit »' . ($unit?->slug ?? '—') . '« ist nicht massebasiert — %-Edit nur für g/kg. '
                . 'Stück/Liter bleiben read-only (Prozent ist massebasiert).'
            );
        }

        $zielG = $pct / 100.0 * $refMassG;
        $neuQty = round($zielG / (float) $unit->default_in_g, 4);
        DB::transaction(fn () => $target->update(['quantity' => $neuQty, 'quantity_max' => null]));
        $recompute->recomputeAndPropagate($recipe->id);

        return [
            'recipe' => $recipe->refresh(),
            'ingredient_id' => $ingredientId,
            'baker_percent' => $pct,
            'ref_mass_g' => round($refMassG, 3),
            'new_mass_g' => round($zielG, 3),
            'new_quantity' => $neuQty,
        ];
    }
}
