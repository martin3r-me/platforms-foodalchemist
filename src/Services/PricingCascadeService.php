<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;

/** Eine Recompute-Reihenfolge für alle mengenunabhängigen Live-Preise. */
class PricingCascadeService
{
    public function __construct(
        private PaketService $packages,
        private ConceptService $concepts,
        private AngebotService $offers,
    ) {
    }

    /** @return array{presentations:int,packages:int,concepts:int,offers:int} */
    public function recomputeTeam(Team $team, int $chunk = 200): array
    {
        $stats = ['presentations' => 0, 'packages' => 0, 'concepts' => 0, 'offers' => 0];
        $presentations = app(DarreichungService::class);
        FoodAlchemistRecipeDarreichung::where('team_id', $team->id)->chunkById($chunk, function ($rows) use (&$stats, $presentations) {
            foreach ($rows as $row) {
                $presentations->recomputePreise($row);
                $stats['presentations']++;
            }
        });
        FoodAlchemistPaket::where('team_id', $team->id)->where('price_mode', 'auto')->chunkById($chunk, function ($rows) use (&$stats) {
            foreach ($rows as $row) {
                $this->packages->recomputePrice($row);
                $stats['packages']++;
            }
        });
        // Eingebettete Concepts können auf andere Concept-Caches zeigen: zwei idempotente Pässe.
        for ($pass = 0; $pass < 2; $pass++) {
            FoodAlchemistConcept::where('team_id', $team->id)->chunkById($chunk, function ($rows) use (&$stats, $pass) {
                foreach ($rows as $row) {
                    $this->concepts->recomputeCache($row);
                    if ($pass === 0) {
                        $stats['concepts']++;
                    }
                }
            });
        }
        FoodAlchemistAngebot::where('team_id', $team->id)
            ->whereIn('status', ['anfrage', 'in_arbeit', 'angebot'])->where('price_mode', 'auto')
            ->chunkById($chunk, function ($rows) use (&$stats, $team) {
                foreach ($rows as $row) {
                    $this->offers->aktualisiereAutoPreis($team, $row);
                    $stats['offers']++;
                }
            });

        return $stats;
    }

    /**
     * Gezielte Live-Kaskade nach einem Rezept-Recompute. Die Reihenfolge folgt
     * dem Preisgraphen und vermeidet einen Vollscan über fremde Katalogbereiche.
     *
     * @param  array<int|string>  $recipeIds
     * @return array{packages:int,concepts:int,offers:int}
     */
    public function recomputeRecipes(array $recipeIds): array
    {
        $recipeIds = collect($recipeIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $stats = ['packages' => 0, 'concepts' => 0, 'offers' => 0];
        if ($recipeIds->isEmpty()) {
            return $stats;
        }

        $teamIds = DB::table('foodalchemist_recipes')->whereIn('id', $recipeIds)->pluck('team_id')->filter()->unique();
        foreach ($teamIds as $teamId) {
            $team = Team::find($teamId);
            if ($team === null) {
                continue;
            }

            $packageIds = DB::table('foodalchemist_package_dishes')
                ->whereIn('sales_recipe_id', $recipeIds)->whereNull('deleted_at')
                ->distinct()->pluck('package_id');
            FoodAlchemistPaket::where('team_id', $team->id)->whereIn('id', $packageIds)
                ->where('price_mode', 'auto')->get()->each(function ($package) use (&$stats) {
                    $this->packages->recomputePrice($package);
                    $stats['packages']++;
                });

            $conceptIds = DB::table('foodalchemist_concept_slots')
                ->whereNull('deleted_at')
                ->where(function ($query) use ($recipeIds, $packageIds) {
                    $query->whereIn('sales_recipe_id', $recipeIds);
                    if ($packageIds->isNotEmpty()) {
                        $query->orWhereIn('package_id', $packageIds);
                    }
                })->distinct()->pluck('concept_id')->map(fn ($id) => (int) $id)->values()->all();

            // Eingebettete Paket-/Concept-Kaskade von unten nach oben auflösen.
            $frontier = $conceptIds;
            $seen = array_fill_keys($conceptIds, true);
            while ($frontier !== []) {
                $parents = DB::table('foodalchemist_concept_slots')
                    ->whereNull('deleted_at')->whereIn('embedded_concept_id', $frontier)
                    ->distinct()->pluck('concept_id')->map(fn ($id) => (int) $id)
                    ->reject(fn ($id) => isset($seen[$id]))->values()->all();
                foreach ($parents as $parentId) {
                    $seen[$parentId] = true;
                    $conceptIds[] = $parentId;
                }
                $frontier = $parents;
            }

            $affectedConcepts = FoodAlchemistConcept::where('team_id', $team->id)
                ->whereIn('id', $conceptIds)->get()->keyBy('id');
            foreach ($conceptIds as $conceptId) {
                if (($concept = $affectedConcepts->get($conceptId)) !== null) {
                    $this->concepts->recomputeCache($concept);
                    $stats['concepts']++;
                }
            }

            $offerIds = $affectedConcepts->pluck('offer_id')->filter();
            $offerIds = $offerIds->merge(DB::table('foodalchemist_offer_concept')
                ->whereIn('concept_id', $affectedConcepts->keys())->pluck('offer_id'))->unique();
            FoodAlchemistAngebot::where('team_id', $team->id)->whereIn('id', $offerIds)
                ->whereIn('status', ['anfrage', 'in_arbeit', 'angebot'])->where('price_mode', 'auto')
                ->get()->each(function ($offer) use (&$stats, $team) {
                    $this->offers->aktualisiereAutoPreis($team, $offer);
                    $stats['offers']++;
                });
        }

        return $stats;
    }
}
