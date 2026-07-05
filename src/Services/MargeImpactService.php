<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Illuminate\Support\Facades\DB;

/**
 * R2.1/R2.2 — DIE eine Impact-Rechnung: „was macht eine relative GP-Preisänderung mit
 * der Marge betroffener Gerichte?". Transitiv (GP → Basisrezepte → Eltern-Gerichte),
 * exakte GP-Exposure rekursiv/memoisiert, Marge-Delta via Preis-Ratio (aktuelle
 * GP-Zeilenkosten skaliert auf den Gegen-Preis). Read-only — kein DB-Write.
 *
 * Konsumenten: SignalDetektorService::preisSprungMargeImpact (R2.1, reagiert auf reale
 * Sprünge) UND SimulationService (R2.2, hypothetische Szenarien).
 */
class MargeImpactService
{
    public function __construct(
        private RecipeRecomputeService $recompute,
        private MargeService $marge,
    ) {
    }

    /**
     * Betroffener Rezept-Baum: Direkt-Nutzer der GPs + alle transitiven Eltern
     * (BFS aufwärts über referenced_recipe_id). VK-Gerichte nutzen GPs fast nie
     * direkt, nur über Basisrezepte — daher zwingend transitiv.
     *
     * @param  list<int>  $gpIds
     * @return list<int>  betroffene recipe_ids (direkt + Eltern)
     */
    public function betroffeneRezepte(array $gpIds, int $maxTiefe = 6): array
    {
        if ($gpIds === []) {
            return [];
        }
        $direkt = DB::table('foodalchemist_recipe_ingredients')
            ->whereIn('gp_id', $gpIds)->whereNull('deleted_at')
            ->distinct()->pluck('recipe_id')->map(fn ($v) => (int) $v)->all();
        if ($direkt === []) {
            return [];
        }
        $alle = array_fill_keys($direkt, true);
        $frontier = $direkt;
        for ($d = 0; $d < $maxTiefe && $frontier !== []; $d++) {
            $eltern = DB::table('foodalchemist_recipe_ingredients')
                ->whereIn('referenced_recipe_id', $frontier)->whereNull('deleted_at')
                ->distinct()->pluck('recipe_id')->map(fn ($v) => (int) $v)->all();
            $neu = [];
            foreach ($eltern as $e) {
                if (! isset($alle[$e])) {
                    $alle[$e] = true;
                    $neu[] = $e;
                }
            }
            $frontier = $neu;
        }

        return array_keys($alle);
    }

    /**
     * Exakte €-Exposure eines GP-SETS innerhalb eines Rezept-Baums (rekursiv, memoisiert):
     * direkte Zeilen mit gp_id ∈ Set + anteilig referenzierte Sub-Rezepte. EIN Baum-Walk
     * je Gericht — unabhängig von der Set-Größe (wichtig für WG-Szenarien mit 1000+ GPs).
     * Setzt totalCache[recipeId] (= Σ Zeilenkosten) als Seiteneffekt.
     *
     * @param  array<int,bool>  $gpSet  Set der GP-ids (id => true)
     */
    public function gpSetExposure(int $recipeId, array $gpSet, array &$recCache, array &$lineCache, array &$totalCache, array &$expCache, int $tiefe = 0): float
    {
        if (isset($expCache[$recipeId])) {
            return $expCache[$recipeId];
        }
        if ($tiefe > 5) {
            return 0.0;
        }
        $rec = $recCache[$recipeId] ??= FoodAlchemistRecipe::with('ingredients')->find($recipeId);
        if ($rec === null) {
            $totalCache[$recipeId] = 0.0;

            return $expCache[$recipeId] = 0.0;
        }
        $lines = $lineCache[$recipeId] ??= $this->recompute->zeilenKostenUndMassen($rec);
        $total = 0.0;
        foreach ($lines as $l) {
            if ($l['kosten'] !== null) {
                $total += (float) $l['kosten'];
            }
        }
        $totalCache[$recipeId] = $total;

        $exp = 0.0;
        foreach ($rec->ingredients as $ing) {
            $lk = isset($lines[$ing->id]) && $lines[$ing->id]['kosten'] !== null ? (float) $lines[$ing->id]['kosten'] : 0.0;
            if ($lk <= 0.0) {
                continue;
            }
            if ($ing->gp_id !== null && isset($gpSet[(int) $ing->gp_id])) {
                $exp += $lk;
            } elseif ($ing->referenced_recipe_id !== null) {
                $subId = (int) $ing->referenced_recipe_id;
                $subExp = $this->gpSetExposure($subId, $gpSet, $recCache, $lineCache, $totalCache, $expCache, $tiefe + 1);
                $subTotal = $totalCache[$subId] ?? 0.0;
                if ($subExp > 0.0 && $subTotal > 0.0) {
                    $exp += $lk * ($subExp / $subTotal);
                }
            }
        }

        return $expCache[$recipeId] = $exp;
    }

    /**
     * Impact einer relativen Preisänderung eines GP-SETS übers (Team-)Portfolio.
     * $ratio = neu/alt (1.2 = +20 %, 0.9 = −10 %). Read-only (Szenario, vorwärts:
     * IST-EK vs. hypothetischer EK). Ein Aufruf deckt Einzel-GP (1-elementiges Set),
     * Artikel (GPs des LA) und Warengruppe (alle GPs der WG) ab.
     *
     * @param  list<int>  $gpIds
     * @return array{n_gps:int,n_recipes:int,n_gerichte:int,n_concepts:int,marge_delta_eur:float,top:list<array>}
     */
    public function impactFuerGps(Team $team, array $gpIds, float $ratio, int $maxGerichte = 2000): array
    {
        $gpIds = array_values(array_unique(array_map('intval', $gpIds)));
        $leer = ['n_gps' => count($gpIds), 'n_recipes' => 0, 'n_gerichte' => 0, 'n_concepts' => 0, 'marge_delta_eur' => 0.0, 'top' => []];
        if ($gpIds === [] || $ratio <= 0) {
            return $leer;
        }
        $gpSet = array_fill_keys($gpIds, true);

        $affected = $this->betroffeneRezepte($gpIds);
        if ($affected === []) {
            return $leer;
        }
        $recipes = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('id', $affected)->get();
        $gerichte = $recipes->filter(fn ($r) => $r->is_sales_recipe && $r->sales_net !== null && (float) $r->sales_net > 0)->values();

        $sumMargeDelta = 0.0;
        $rows = [];
        $recCache = $lineCache = $totalCache = $expCache = [];
        $zahl = 0;
        foreach ($gerichte as $rec) {
            if ($zahl >= $maxGerichte) {
                break;
            }
            $zahl++;
            $exposure = $this->gpSetExposure((int) $rec->id, $gpSet, $recCache, $lineCache, $totalCache, $expCache);
            $istEk = $totalCache[(int) $rec->id] ?? 0.0;
            if ($exposure <= 0 || $istEk <= 0) {
                continue;
            }
            $hypoEk = $istEk + $exposure * ($ratio - 1);
            $vk = (float) $rec->sales_net;
            $mIst = $this->marge->marge($vk, $istEk);
            $mHypo = $this->marge->marge($vk, $hypoEk);
            if ($mIst === null || $mHypo === null) {
                continue;
            }
            $mdelta = round($mHypo['marge_eur'] - $mIst['marge_eur'], 2);
            // exposure>0 ⇒ Gericht IST betroffen (EK verschiebt sich), auch wenn die Marge
            // (bei billigem GP) auf 0 rundet — zählt mit, landet nur nicht oben im Top-Ranking.
            $sumMargeDelta += $mdelta;
            $rows[] = [
                'recipe_id' => (int) $rec->id, 'name' => $rec->name,
                'marge_pct_ist' => $mIst['marge_pct'], 'marge_pct_hypo' => $mHypo['marge_pct'],
                'marge_delta_eur' => $mdelta, 'wareneinsatz_pct_hypo' => $mHypo['wareneinsatz_pct'],
            ];
        }

        usort($rows, fn ($a, $b) => abs($b['marge_delta_eur']) <=> abs($a['marge_delta_eur']));
        $gerichtIds = array_column($rows, 'recipe_id');
        $nConcepts = $gerichtIds === [] ? 0 : DB::table('foodalchemist_concept_slots')
            ->whereIn('sales_recipe_id', $gerichtIds)->whereNull('deleted_at')
            ->whereNotNull('concept_id')->distinct()->count('concept_id');

        return [
            'n_gps' => count($gpIds),
            'n_recipes' => $recipes->count(),
            'n_gerichte' => count($rows),
            'n_concepts' => $nConcepts,
            'marge_delta_eur' => round($sumMargeDelta, 2),
            'top' => array_slice($rows, 0, 20),
        ];
    }
}
