<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * R6.10 — Überschuss-zu-Gericht. Erster BIDIREKTIONALER Fall: „Lager meldet
 * Überschuss, FA schlägt Verwertung vor." Input ist ein Mock/Contract-Bestand
 * `[{gp_id, menge}]` — FA führt bewusst KEINE eigene Lagerhaltung (E4). Über den
 * Pairing-Anker-Graph werden Gerichte gefunden, die den GP geschmacklich *tragen*
 * (Anker-Relevanz), nicht bloß „enthalten". READ-ONLY: die Draft-Konzept-Anlage
 * bleibt ein expliziter Folgeschritt (ConceptService::create).
 *
 * Grenze (E4): FA rechnet/schlägt vor; Bestandsführung + Bestellung = Nachbar-Modul.
 * Produktiv über den Core-Contract; v1 mit Mock-Bestand baubar + testbar.
 */
class SurplusToDishService
{
    public function __construct(private PairingService $pairing)
    {
    }

    /**
     * @param  list<array{gp_id:int, menge?:float|int, einheit?:string}>  $surplus
     * @return array{surplus: list<array>, kandidaten: list<array>, nicht_verwertbar: list<array>}
     */
    public function suggest(Team $team, array $surplus, int $limit = 8): array
    {
        $gpAnker = [];       // gp_id => list<int> anchorIds
        $anchorToGps = [];   // anchor_id => list<int> gp_ids
        $surplusOut = [];
        $allAnchorIds = [];

        foreach ($surplus as $row) {
            $gpId = (int) ($row['gp_id'] ?? 0);
            $gp = $gpId > 0 ? FoodAlchemistGp::visibleToTeam($team)->find($gpId) : null;
            if ($gp === null) {
                continue;
            }
            $anker = [];
            foreach ($this->pairing->gpAnkers($gpId) as $a) {
                $aid = (int) $a->id;
                $gpAnker[$gpId][] = $aid;
                $anchorToGps[$aid][] = $gpId;
                $allAnchorIds[] = $aid;
                $anker[] = ['id' => $aid, 'name' => $a->display_de ?: $a->slug];
            }
            $surplusOut[] = [
                'gp_id' => $gpId,
                'name' => $gp->name,
                'menge' => isset($row['menge']) ? (float) $row['menge'] : null,
                'einheit' => $row['einheit'] ?? null,
                'anker' => $anker,
            ];
        }

        $allAnchorIds = array_values(array_unique($allAnchorIds));
        if ($allAnchorIds === []) {
            return ['surplus' => $surplusOut, 'kandidaten' => [], 'nicht_verwertbar' => $this->nichtVerwertbar($surplusOut, [])];
        }

        // Portfolio-Gerichte, die diese Anker TRAGEN (kern + prozess).
        $portfolioIds = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true)->pluck('id')->all();
        $treffer = [];       // recipe_id => set(anchor_id)
        $abgedeckt = [];     // anchor_id => true
        if ($portfolioIds !== []) {
            foreach (['foodalchemist_recipe_anchor_mappings', 'foodalchemist_recipe_process_anchors'] as $tabelle) {
                foreach (DB::table($tabelle)->whereIn('recipe_id', $portfolioIds)
                    ->whereIn('anchor_id', $allAnchorIds)->whereNull('deleted_at')
                    ->get(['recipe_id', 'anchor_id']) as $r) {
                    $treffer[(int) $r->recipe_id][(int) $r->anchor_id] = true;
                    $abgedeckt[(int) $r->anchor_id] = true;
                }
            }
        }

        $namen = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('foodalchemist_recipes.id', array_keys($treffer))->pluck('name', 'id');
        $ankerNamen = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', $allAnchorIds)->pluck('display_de', 'id');

        $kandidaten = [];
        foreach ($treffer as $rid => $set) {
            $matchedAnchors = array_keys($set);
            // Welche Überschuss-GPs trägt dieses Gericht (über ihre Anker)?
            $verwertet = [];
            foreach ($matchedAnchors as $aid) {
                foreach ($anchorToGps[$aid] ?? [] as $gid) {
                    $verwertet[$gid] = true;
                }
            }
            $verwertetGps = array_values(array_map(function ($gid) use ($surplusOut) {
                $s = collect($surplusOut)->firstWhere('gp_id', $gid);

                return ['gp_id' => $gid, 'name' => $s['name'] ?? (string) $gid, 'menge' => $s['menge'] ?? null, 'einheit' => $s['einheit'] ?? null];
            }, array_keys($verwertet)));
            $relevanz = array_map(fn ($aid) => $ankerNamen[$aid] ?? (string) $aid, $matchedAnchors);

            $kandidaten[] = [
                'recipe_id' => $rid,
                'name' => $namen[$rid] ?? (string) $rid,
                'shared_anker' => count($matchedAnchors),
                'von_gesamt' => count($allAnchorIds),
                'relevanz_anker' => $relevanz,
                'verwertet_gps' => $verwertetGps,
                'begruendung' => 'trägt ' . count($matchedAnchors) . ' von ' . count($allAnchorIds)
                    . ' Überschuss-Ankern (' . implode(', ', $relevanz) . ') → geschmacklich tragend, nicht nur enthalten',
            ];
        }
        usort($kandidaten, fn ($a, $b) => [$b['shared_anker'], $a['name']] <=> [$a['shared_anker'], $b['name']]);

        return [
            'surplus' => $surplusOut,
            'kandidaten' => array_slice($kandidaten, 0, max(1, $limit)),
            'nicht_verwertbar' => $this->nichtVerwertbar($surplusOut, $abgedeckt),
        ];
    }

    /** Überschuss-GPs, deren Anker KEIN Bestandsgericht trägt (oder die gar keine Anker haben). */
    private function nichtVerwertbar(array $surplusOut, array $abgedeckt): array
    {
        $out = [];
        foreach ($surplusOut as $s) {
            $ankerIds = array_map(fn ($a) => $a['id'], $s['anker']);
            $hatTreffer = false;
            foreach ($ankerIds as $aid) {
                if (isset($abgedeckt[$aid])) {
                    $hatTreffer = true;
                    break;
                }
            }
            if (! $hatTreffer) {
                $out[] = [
                    'gp_id' => $s['gp_id'],
                    'name' => $s['name'],
                    'grund' => $ankerIds === [] ? 'GP hat keine Aroma-Anker (nicht graph-erreichbar)' : 'kein Bestandsgericht trägt diese Aroma-Anker',
                ];
            }
        }

        return $out;
    }
}
