<?php

namespace Platform\FoodAlchemist\Services\Rezept;

use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;

/**
 * Sub-Rezept-Graph (§3.5): Verknüpfungs-Guards parent→sub und Tiefen. Aus RecipeRecomputeService ausgelagert (27.10.2026, Dateigrößen-Sperrklinke),
 * Verhalten unverändert; RecipeRecomputeService::pruefeVerknuepfung delegiert hierher.
 */
class RezeptGraphService
{
    /**
     * §3.5: Verknüpfungs-Guards parent→sub — Selbstreferenz und Zyklus blocken; die Tiefe ist seit A-5 (03.08.) nur informativ.
     *
     * @return array{erlaubt: bool, grund: ?string, projizierte_tiefe: int}
     */
    public function pruefeVerknuepfung(int $parentId, int $subId): array
    {
        if ($parentId === $subId) {
            return ['erlaubt' => false, 'grund' => 'Selbstreferenz', 'projizierte_tiefe' => 0];
        }
        // BFS von sub abwärts: wird parent erreicht ⇒ Zyklus
        $ebene = [$subId];
        $besucht = [$subId => true];
        while ($ebene !== []) {
            $kinder = FoodAlchemistRecipeIngredient::whereIn('recipe_id', $ebene)
                ->whereNotNull('referenced_recipe_id')->whereNull('deleted_at')
                ->distinct()->pluck('referenced_recipe_id')->reject(fn ($id) => isset($besucht[$id]))->values()->all();
            if (in_array($parentId, $kinder, true)) {
                return ['erlaubt' => false, 'grund' => 'Zyklus', 'projizierte_tiefe' => 0];
            }
            foreach ($kinder as $k) {
                $besucht[$k] = true;
            }
            $ebene = $kinder;
        }

        // Projizierte Tiefe bleibt informativ (Ahnen des Parents + Sub-Baum), aber sie
        // BLOCKT nicht mehr (A-5 2026-08-03 gelockert): tiefe Menü-/Komposit-Ketten sind
        // legitim. Verworfen wird nur bei Selbstreferenz/Zyklus (oben geprüft).
        $tiefe = max(
            $this->ahnenHoehe($parentId) + $this->subtreeTiefe($subId),
            $this->subtreeTiefe($parentId),
        );

        return ['erlaubt' => true, 'grund' => null, 'projizierte_tiefe' => $tiefe];
    }

    /** Längster Aufwärts-Pfad bis zur Wurzel, inkl. Start-Rezept (A→B→C: C ⇒ 3). */
    private function ahnenHoehe(int $recipeId): int
    {
        $hoehe = 1;
        $ebene = [$recipeId];
        $besucht = [$recipeId => true];
        while ($ebene !== []) {                                    // Visited-Set garantiert Terminierung (kein Tiefen-Cap)
            $eltern = FoodAlchemistRecipeIngredient::whereIn('referenced_recipe_id', $ebene)
                ->whereNull('deleted_at')->distinct()->pluck('recipe_id')
                ->reject(fn ($id) => isset($besucht[$id]))->values()->all();
            if ($eltern === []) {
                break;
            }
            foreach ($eltern as $e) {
                $besucht[$e] = true;
            }
            $ebene = $eltern;
            $hoehe++;
        }

        return $hoehe;
    }

    /** Tiefe des Sub-Baums ab Rezept (Blatt = 1). */
    public function subtreeTiefe(int $recipeId): int
    {
        $tiefe = 1;
        $ebene = [$recipeId];
        $besucht = [$recipeId => true];
        while ($ebene !== []) {                                    // Visited-Set garantiert Terminierung (kein Tiefen-Cap)
            $kinder = FoodAlchemistRecipeIngredient::whereIn('recipe_id', $ebene)
                ->whereNotNull('referenced_recipe_id')->whereNull('deleted_at')
                ->distinct()->pluck('referenced_recipe_id')->reject(fn ($id) => isset($besucht[$id]))->values()->all();
            if ($kinder === []) {
                break;
            }
            foreach ($kinder as $k) {
                $besucht[$k] = true;
            }
            $ebene = $kinder;
            $tiefe++;
        }

        return $tiefe;
    }
}
