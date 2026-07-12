<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;

/**
 * Station 2 — Pairing-Projektion: hängt abgeleitete Molekül-Kanten
 * (foodalchemist_pairing_computed, aus FooDB/Ahn/Buch) als Graph-Kanten in
 * foodalchemist_pairing_anchor_edges.
 *
 * Prinzip (ROADMAP w0rox0ps0, Inv. 3+5): **computed füllt nur Löcher** — ein
 * Anker-Paar bekommt nur dann eine computed-Kante, wenn es KEINE kuratierte Kante
 * hat (in irgendeiner Richtung/Typ). Kuratierte Kanten werden nie berührt.
 *  - Provenienz + Batch-Löschbarkeit: source_slug = 'computed'.
 *  - Gradiertes Gewicht: weight = round(weightFactor × Molekül-Confidence, 3),
 *    stets < kuratiert → hebt Coverage, ohne den Kohäsions-Score zu fluten.
 *  - Typ: geteiltes Aromamolekül (harmony/synergie) → 'aroma'; sonst 'kontrast'.
 *  - Anker-Auflösung: computed.label_a/b (FooDB-Label) → anchor_ingredient_map.label_en
 *    (1:viele erlaubt). Nicht alle 341k computed sind anker-zu-anker.
 *
 * Idempotent + resumefähig: nach dem Lauf sind die Löcher echte Kanten → ein
 * erneuter Lauf sieht sie als „vorhanden" und überspringt sie. MySQL-only
 * (UUID()/TEMPORARY/GROUP_CONCAT/LEAST-GREATEST) — dediziertes Wartungs-Kommando.
 */
class PairingProjectionService
{
    /** @return array<string,mixed> Statistik (immer); schreibt nur bei $apply. */
    public function project(bool $apply, float $minConfidence, int $teamId, float $weightFactor): array
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql') {
            throw new \RuntimeException("PairingProjectionService braucht MySQL (Treiber: {$driver}).");
        }

        $this->buildTempTables();

        $holeBase = DB::table('tmp_proj_pairs AS p')
            ->leftJoin('tmp_proj_existing AS e', fn ($j) => $j->on('p.a', '=', 'e.a')->on('p.b', '=', 'e.b'))
            ->whereNull('e.a')
            ->where('p.best_conf', '>=', $minConfidence);

        $computedPairs = (int) DB::table('tmp_proj_pairs')->count();
        $collisions = (int) DB::table('tmp_proj_pairs AS p')
            ->join('tmp_proj_existing AS e', fn ($j) => $j->on('p.a', '=', 'e.a')->on('p.b', '=', 'e.b'))->count();
        $holes = (int) (clone $holeBase)->count();
        $holesAroma = (int) (clone $holeBase)
            ->where(fn ($q) => $q->where('p.best_harm', '>', 0)->orWhere('p.any_synergie', 1))->count();
        $existingComputed = (int) DB::table('foodalchemist_pairing_anchor_edges')
            ->where('source_slug', 'computed')->count();

        $inserted = 0;
        if ($apply) {
            // Ein atomarer INSERT..SELECT: schnell, und interrupt-sicher (InnoDB rollt
            // die Anweisung ganz zurück statt teilweise). Re-Run überspringt via holes-only.
            $inserted = DB::affectingStatement(
                'INSERT INTO foodalchemist_pairing_anchor_edges '
                .'(uuid, team_id, anchor_a_id, anchor_b_id, type, weight, evidence, source_slug, created_at, updated_at) '
                .'SELECT UUID(), ?, p.a, p.b, '
                ."CASE WHEN (p.best_harm > 0 OR p.any_synergie = 1) THEN 'aroma' ELSE 'kontrast' END, "
                .'ROUND(? * p.best_conf, 3), '
                ."CONCAT('computed (conf=', ROUND(p.best_conf, 3), '): ', COALESCE(p.evidence, '')), "
                ."'computed', NOW(), NOW() "
                .'FROM tmp_proj_pairs p '
                .'LEFT JOIN tmp_proj_existing e ON p.a = e.a AND p.b = e.b '
                .'WHERE e.a IS NULL AND p.best_conf >= ?',
                [$teamId, $weightFactor, $minConfidence],
            );
        }

        return [
            'computed_pairs' => $computedPairs,
            'collisions_kept_curated' => $collisions,
            'holes' => $holes,
            'holes_aroma' => $holesAroma,
            'holes_kontrast' => $holes - $holesAroma,
            'existing_computed_before' => $existingComputed,
            'inserted' => $inserted,
            'applied' => $apply,
            'min_confidence' => $minConfidence,
            'weight_factor' => $weightFactor,
            'team_id' => $teamId,
        ];
    }

    /** Löscht alle projizierten computed-Kanten wieder (Batch-Rollback). */
    public function purgeComputed(): int
    {
        return DB::table('foodalchemist_pairing_anchor_edges')->where('source_slug', 'computed')->delete();
    }

    /**
     * Baut die zwei Session-Temp-Tabellen:
     *  tmp_proj_pairs    — computed → ungeordnete Anker-Paare mit Best-Metriken
     *  tmp_proj_existing — normalisierte (LEAST/GREATEST) vorhandene Kanten-Paare
     */
    private function buildTempTables(): void
    {
        DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_proj_pairs');
        DB::statement(
            'CREATE TEMPORARY TABLE tmp_proj_pairs '
            .'(a BIGINT NOT NULL, b BIGINT NOT NULL, best_conf DOUBLE, best_harm DOUBLE, any_synergie INT, '
            .'evidence VARCHAR(255), PRIMARY KEY (a, b)) '
            .'SELECT LEAST(ma.anchor_id, mb.anchor_id) AS a, GREATEST(ma.anchor_id, mb.anchor_id) AS b, '
            .'MAX(c.confidence) AS best_conf, MAX(c.harmony) AS best_harm, '
            ."MAX(c.relation LIKE '%synergie%') AS any_synergie, "
            ."SUBSTRING_INDEX(GROUP_CONCAT(c.evidence_auto ORDER BY c.confidence DESC SEPARATOR '||'), '||', 1) AS evidence "
            .'FROM foodalchemist_pairing_computed c '
            .'JOIN foodalchemist_anchor_ingredient_map ma ON LOWER(ma.label_en) = LOWER(c.label_a) '
            .'JOIN foodalchemist_anchor_ingredient_map mb ON LOWER(mb.label_en) = LOWER(c.label_b) '
            .'WHERE ma.anchor_id <> mb.anchor_id AND ma.anchor_id IS NOT NULL AND mb.anchor_id IS NOT NULL '
            .'GROUP BY LEAST(ma.anchor_id, mb.anchor_id), GREATEST(ma.anchor_id, mb.anchor_id)',
        );

        DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_proj_existing');
        DB::statement(
            'CREATE TEMPORARY TABLE tmp_proj_existing (a BIGINT NOT NULL, b BIGINT NOT NULL, PRIMARY KEY (a, b)) '
            .'SELECT DISTINCT LEAST(anchor_a_id, anchor_b_id) AS a, GREATEST(anchor_a_id, anchor_b_id) AS b '
            .'FROM foodalchemist_pairing_anchor_edges',
        );
    }
}
