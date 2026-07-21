<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\AllergenValue;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Models\FoodAlchemistItemNutritional;

/**
 * M3-04: GP-Ebene der Aggregations-GLs — die rezept-unabhängigen Bausteine,
 * die M4 (RecomputeService) später je Zutat wiederverwendet.
 *
 * - Allergene (GL-01 §4.3): Override absolut > Derivat-LIVE von Mutter (SOLL ⚠A2,
 *   eine Ebene) > MAX über ALLE LAs (bewusst inkl. discontinued — konservativ, §6).
 * - GP-Konfidenz (GL-01 §4.5, SOLL ⚠A1): HIGH/MED/LOW/NONE + needs_allergen_review.
 * - Zusatzstoffe (GL-09): MAX über LA-declarations, Roh-Domäne {0,1,3,NULL}, 3=ja,
 *   KEIN Override-Layer, alle LAs (wie GL-01).
 * - Nährwerte (GL-08 GP-Pfad): je Nährstoff AVG über NICHT-discontinued LAs,
 *   NULL fällt aus dem AVG; Salz = sodium_mg × 0.0025.
 */
class GpAggregateService
{
    /** kategorial → decimal(4,3) (allergens_confidence). Single source für Command + SignalFix. */
    public const ALLERGEN_KONF_MAP = ['high' => 1.0, 'medium' => 0.66, 'low' => 0.33, 'none' => 0.0];

    /**
     * Effektive Allergen-Auflösung je GP:
     * [feld => ['value' => AllergenValue, 'source' => override|mutter|la|keine]].
     */
    public function allergene(FoodAlchemistGp $gp): array
    {
        $laMax = $this->laMaxWerte($gp->id);
        $mutterMax = null; // lazy — nur wenn ein Derivat-Feld ohne Override sie braucht

        $out = [];
        foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
            $override = AllergenValue::tryFrom((string) $gp->getAttribute("allergen_{$feld}"));
            if ($override !== null) {                              // Prio 1: Override absolut, wird NIE gemax-t
                $out[$feld] = ['value' => $override, 'source' => 'override'];

                continue;
            }

            if ($gp->is_derivat && $gp->derivat_von_gp_id !== null) { // Prio 2 (SOLL ⚠A2): LIVE von Mutter, eine Ebene
                if ($mutterMax === null) {
                    $mutter = FoodAlchemistGp::find($gp->derivat_von_gp_id);
                    $mutterMax = $mutter !== null ? $this->aufgeloesteWerte($mutter) : [];
                }
                $wert = $mutterMax[$feld] ?? AllergenValue::Unbekannt;
                $out[$feld] = ['value' => $wert, 'source' => 'mutter'];

                continue;
            }

            $wert = $laMax[$feld] ?? null;                         // Prio 3: MAX über alle LAs
            $out[$feld] = [
                'value' => $wert ?? AllergenValue::Unbekannt,       // leere Menge ⇒ unbekannt (§4.2)
                'source' => $wert !== null ? 'la' : 'keine',
            ];
        }

        return $out;
    }

    /**
     * GP-Konfidenz nach GL-01 §4.5 (SOLL ⚠A1 — im Ist nicht vorhanden):
     * ['confidence' => high|medium|low|none, 'needs_review' => bool, 'konflikt_felder' => string[]].
     */
    public function allergenKonfidenz(FoodAlchemistGp $gp): array
    {
        $profile = $this->laProfile($gp->id);
        if ($profile->isEmpty()) {
            return ['confidence' => 'none', 'needs_review' => false, 'konflikt_felder' => [], 'n_las_mit_daten' => 0];
        }

        $konflikt = [];   // enthalten ↔ nicht_enthalten ohne spuren-Mittelweg ⇒ LOW + Review
        $differenz = false; // konkrete Werte unterscheiden sich auf gleicher Stufe ⇒ MED
        foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
            $raenge = $profile
                ->map(fn ($la) => AllergenValue::tryFrom((string) $la->getAttribute("allergen_{$feld}")))
                ->filter(fn (?AllergenValue $v) => $v !== null && $v !== AllergenValue::Unbekannt) // konkreter gewinnt gegen unbekannt (§10)
                ->map(fn (AllergenValue $v) => $v->rank())
                ->unique()->values();

            if ($raenge->count() <= 1) {
                continue;
            }
            if ($raenge->contains(3) && $raenge->contains(1) && ! $raenge->contains(2)) {
                $konflikt[] = $feld;
            } else {
                $differenz = true;
            }
        }

        if ($konflikt !== []) {
            return ['confidence' => 'low', 'needs_review' => true, 'konflikt_felder' => $konflikt, 'n_las_mit_daten' => $profile->count()];
        }

        return ['confidence' => $differenz ? 'medium' : 'high', 'needs_review' => false, 'konflikt_felder' => [], 'n_las_mit_daten' => $profile->count()];
    }

    /**
     * P3-Backfill je GP: persistiert `allergens_confidence`/`allergens_source`/
     * `allergens_aggregated_at` aus der on-read-Aggregation. NUR Metadaten — die 14
     * `allergen_*`-WERT-Spalten (Override-Schicht) werden NIE geschrieben (sonst Derivat-
     * LIVE-Vererbung + „LA fixen → GP heilt"-Kaskade kaputt). Provenienz-Schutz:
     * `allergens_source IN (manual|ki)` bleibt unangetastet. Derivate erben LIVE von der
     * Mutter (source='derivat'). Single source für Command + SignalFixService.
     *
     * @return array{confidence:string,needs_review:bool,konflikt_felder:array<int,string>,source:string,written:bool,skipped:bool}
     */
    public function backfillAllergenKonfidenz(FoodAlchemistGp $gp, bool $apply = true): array
    {
        $skip = in_array($gp->allergens_source, ['manual', 'ki'], true);

        if ($gp->is_derivat && $gp->derivat_von_gp_id !== null) {
            $mutter = FoodAlchemistGp::find($gp->derivat_von_gp_id);
            $konf = $mutter !== null
                ? $this->allergenKonfidenz($mutter)
                : ['confidence' => 'none', 'needs_review' => false, 'konflikt_felder' => []];
            $source = 'derivat';
        } else {
            $konf = $this->allergenKonfidenz($gp);
            $source = 'la_union';
        }

        $written = false;
        if ($apply && ! $skip) {
            $gp->update([
                'allergens_confidence' => self::ALLERGEN_KONF_MAP[$konf['confidence']] ?? 0.0,
                'allergens_source' => $source,
                'allergens_aggregated_at' => now(),
            ]);
            $written = true;
        }

        return [
            'confidence' => $konf['confidence'],
            'needs_review' => (bool) ($konf['needs_review'] ?? false),
            'konflikt_felder' => $konf['konflikt_felder'] ?? [],
            'source' => $source,
            'written' => $written,
            'skipped' => $skip,
        ];
    }

    /**
     * Zusatzstoffe je GP (GL-09): [stoff => 3|1|0|null] — MAX über alle LA-declarations,
     * Roh-Domäne (3=ja, 1=nein, 0=k.A.), NULL = keine Daten (kein Beitrag).
     */
    public function zusatzstoffe(FoodAlchemistGp $gp): array
    {
        $stoffe = array_keys(FoodAlchemistItemDeclaration::STOFFE);

        $row = DB::table('foodalchemist_item_declarations AS d')
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'd.supplier_item_id')
            ->where('s.gp_id', $gp->id)
            ->whereNull('d.deleted_at')->whereNull('s.deleted_at')
            ->selectRaw(implode(', ', array_map(fn ($f) => "MAX(d.{$f}) AS {$f}", $stoffe)))
            ->first();

        $out = [];
        foreach ($stoffe as $stoff) {
            $wert = $row?->{$stoff};
            $out[$stoff] = $wert === null ? null : (int) $wert;
        }

        return $out;
    }

    /**
     * Nährwert-Ø je 100 g (GL-08 GP-Pfad): je Nährstoff AVG über aktive LAs
     * (is_discontinued=0), NULL-Werte fallen aus dem AVG (Invariante 5).
     * Rückgabe: [kernwert => ['avg' => float|null, 'n' => int]] + 'salt_g' (sodium×0.0025).
     */
    /**
     * $mitKiFallback (R10, NUR Panel-Anzeige): ohne LA-Daten die KI-/manuelle
     * Schätzschicht vom GP zurückgeben — die GL-08-Rezept-Aggregation ruft
     * bewusst OHNE Fallback (Schätzwerte verfälschen keine Rezept-Nährwerte).
     */
    public function naehrwerte(FoodAlchemistGp $gp, bool $mitKiFallback = false): array
    {
        $kerne = FoodAlchemistItemNutritional::KERNWERTE;

        $selects = [];
        foreach ($kerne as $k) {
            $selects[] = "AVG(n.{$k}) AS avg_{$k}";               // SQL-AVG ignoriert NULL
            $selects[] = "COUNT(n.{$k}) AS n_{$k}";               // COUNT(col) zählt nur NOT-NULL
        }

        $row = DB::table('foodalchemist_item_nutritionals AS n')
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'n.supplier_item_id')
            ->join('foodalchemist_supplier_items AS i', 'i.id', '=', 'n.supplier_item_id')
            ->where('s.gp_id', $gp->id)
            ->where('i.is_discontinued', false)
            ->whereNull('n.deleted_at')->whereNull('s.deleted_at')->whereNull('i.deleted_at')
            ->selectRaw(implode(', ', $selects))
            ->first();

        $out = [];
        foreach ($kerne as $k) {
            $avg = $row?->{"avg_{$k}"};
            $out[$k] = ['avg' => $avg !== null ? (float) $avg : null, 'n' => (int) ($row?->{"n_{$k}"} ?? 0)];
        }
        // Salz-Ableitung (GL-08 §4.2): Quelle sodium in mg/100g → g Salz/100g
        $out['salt_g'] = [
            'avg' => $out['sodium']['avg'] !== null ? $out['sodium']['avg'] * 0.0025 : null,
            'n' => $out['sodium']['n'],
        ];
        $out['source'] = $out['energy_kcal']['avg'] !== null ? 'la' : 'keine';

        // R10: Fallback-Schicht (ki|manual) — nur wenn KEINE LA-Daten und gewünscht
        if ($mitKiFallback && $out['energy_kcal']['avg'] === null && $gp->nutri_source !== null) {
            $map = [
                'energy_kcal' => 'nutri_kcal_per_100g', 'protein' => 'nutri_protein_g_per_100g',
                'fat' => 'nutri_fat_g_per_100g', 'carbs_absorbable' => 'nutri_carbs_g_per_100g',
                'salt_g' => 'nutri_salt_g_per_100g',
            ];
            foreach ($map as $key => $feld) {
                $out[$key] = ['avg' => $gp->{$feld} !== null ? (float) $gp->{$feld} : null, 'n' => 0];
            }
            $out['source'] = $gp->nutri_source;                   // 'ki' | 'manual'
        }

        return $out;
    }

    /**
     * M3-02-Badges in Bulk: LA-MAX-Rang je Allergen für viele GPs in EINER Query
     * (CASE-Rang-Mapping, da die Werte Strings sind — String-MAX würde falsch ranken).
     *
     * @return array<int, array<string, int>> [gp_id => [feld => rang 0–3]]
     */
    public function laMaxRaengeBulk(array $gpIds): array
    {
        if ($gpIds === []) {
            return [];
        }

        $selects = ['s.gp_id'];
        foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
            $selects[] = "MAX(CASE a.allergen_{$feld} WHEN 'enthalten' THEN 3 WHEN 'spuren' THEN 2 WHEN 'nicht_enthalten' THEN 1 ELSE 0 END) AS {$feld}";
        }

        return DB::table('foodalchemist_item_allergens AS a')
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'a.supplier_item_id')
            ->whereIn('s.gp_id', $gpIds)
            ->whereNull('a.deleted_at')->whereNull('s.deleted_at')
            ->groupBy('s.gp_id')
            ->selectRaw(implode(', ', $selects))
            ->get()
            ->keyBy('gp_id')
            ->map(fn ($row) => collect(FoodAlchemistGp::ALLERGEN_FIELDS)->mapWithKeys(fn ($f) => [$f => (int) $row->{$f}])->all())
            ->all();
    }

    // ── intern ───────────────────────────────────────────────────────────

    /**
     * Voll aufgelöster Wert je Feld (Override > LA-MAX) — für die Mutter-Auflösung
     * von Derivaten (GL-01 §4.3 Prio 2; Derivat-von-Derivat ist verboten, eine Ebene reicht).
     *
     * @return array<string, AllergenValue>
     */
    private function aufgeloesteWerte(FoodAlchemistGp $gp): array
    {
        $laMax = $this->laMaxWerte($gp->id);

        $out = [];
        foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
            $override = AllergenValue::tryFrom((string) $gp->getAttribute("allergen_{$feld}"));
            $out[$feld] = $override ?? $laMax[$feld] ?? AllergenValue::Unbekannt;
        }

        return $out;
    }

    /**
     * MAX-Rang je Allergen über ALLE LAs des GP (GL-01 §4.3 Prio 3 — bewusst ohne
     * is_discontinued-Filter: für Allergene ist „alle LAs" die konservative Wahl, §6).
     *
     * @return array<string, AllergenValue> nur Felder mit mindestens einem konkreten Beitrag
     */
    private function laMaxWerte(int $gpId): array
    {
        $out = [];
        foreach ($this->laProfile($gpId) as $la) {
            foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
                $wert = AllergenValue::tryFrom((string) $la->getAttribute("allergen_{$feld}"));
                if ($wert === null) {
                    continue;                                      // NULL = kein Beitrag (von MAX ignoriert)
                }
                if (! isset($out[$feld]) || $wert->rank() > $out[$feld]->rank()) {
                    $out[$feld] = $wert;
                }
            }
        }

        return $out;
    }

    /** Alle LA-Allergen-Zeilen des GP (über die Struktur-Brücke). */
    private function laProfile(int $gpId): \Illuminate\Support\Collection
    {
        return FoodAlchemistItemAllergen::query()
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'foodalchemist_item_allergens.supplier_item_id')
            ->where('s.gp_id', $gpId)
            ->whereNull('s.deleted_at')
            ->select('foodalchemist_item_allergens.*')
            ->get();
    }
}
