<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * R6.9 — Dish-Reverse-Engineering: fremdes Gericht (Text) → Aroma-Skelett → Nachbau
 * aus dem EIGENEN Bestand. Orchestriert Vorhandenes, baut keine neue Mathematik:
 *  - Zerlegung Text → GPs via IngredientMatchService (+ #507-Recall, wenn an).
 *  - Unmatched → LaFirstGpService::mintFromLa wenn eine LA existiert, sonst
 *    Beschaffungs-Wunsch (kein Raten — Doktrin »kein GP ohne LA«).
 *  - Aroma-Skelett aus dem Pairing-Graph (PairingService::gpAnkers/edgesFor).
 *  - Rekonstruktion gegen das eigene VK-Portfolio (Anker-Überlappung) + Lücken-Report.
 *
 * READ-ONLY-Semantik nach außen: die Analyse mintet höchstens LA-belegte GPs
 * (sanktionierte LA-First-Entstehung, tentative); die Draft-Anlage des Nachbaus
 * bleibt ein EXPLIZITER Folgeschritt (RecipeService::create), nicht Teil hiervon.
 * v1: Text-Input (E3); Foto = Ausbaustufe (Multimodal = Martin).
 */
class DishReverseService
{
    public function __construct(
        private IngredientMatchService $matcher,
        private PairingService $pairing,
        private LaFirstGpService $laFirst,
    ) {
    }

    /**
     * @return array{
     *   input: string,
     *   komponenten: array{erkannt: list<array>, beschaffungs_wuensche: list<array>},
     *   aroma_skelett: array{traeger_anker: list<array>, verbund_kanten: list<array>},
     *   rekonstruktion: array{kandidaten: list<array>, luecken: list<array>}
     * }
     */
    public function reverse(Team $team, string $text, int $limit = 8): array
    {
        $erkannt = [];
        $wuensche = [];
        $anchorIds = [];

        foreach ($this->splitIngredients($text) as $phrase) {
            $m = $this->matcher->matchIngredient($team, $phrase);

            if ($m['target'] === 'gp' && $m['gp_id'] !== null) {
                $erkannt[] = $this->gpKomponente($phrase, (int) $m['gp_id'], (string) $m['gp_name'], 'match', (float) $m['score'], $anchorIds);

                continue;
            }
            if ($m['target'] === 'sub_recipe' && $m['recipe_id'] !== null) {
                $erkannt[] = $this->subKomponente($phrase, (int) $m['recipe_id'], (string) $m['recipe_name'], (float) $m['score'], $anchorIds);

                continue;
            }

            // Kein Match → LA-First-Mint versuchen (nur wenn eine LA existiert), sonst Wunsch.
            $gp = $this->laFirst->mintFromLa($team, $phrase);
            if ($gp !== null) {
                $erkannt[] = $this->gpKomponente($phrase, (int) $gp->id, (string) $gp->name, 'la_first_mint', (float) $m['score'], $anchorIds);
            } else {
                $wuensche[] = ['phrase' => $phrase, 'grund' => 'kein GP/Sub-Treffer und keine LA → Beschaffungs-Wunsch (kein Raten)'];
            }
        }

        $anchorIds = array_values(array_unique($anchorIds));

        return [
            'input' => $text,
            'komponenten' => ['erkannt' => $erkannt, 'beschaffungs_wuensche' => $wuensche],
            'aroma_skelett' => $this->aromaSkelett($anchorIds),
            'rekonstruktion' => $this->reconstructFromPortfolio($team, $anchorIds, $limit),
        ];
    }

    /** Zerlegt Freitext/Karte in Zutat-Phrasen (Zeilen, Kommas, Semikola, »und«/»mit«/»auf«/»&«). */
    private function splitIngredients(string $text): array
    {
        $roh = preg_split('/[\n,;•·\-–]+|\bund\b|\bmit\b|\bauf\b|\bsowie\b|&/iu', $text) ?: [];
        $out = [];
        foreach ($roh as $t) {
            $t = trim(preg_replace('/\s+/', ' ', $t));
            if (mb_strlen($t) >= 2 && ! isset($out[mb_strtolower($t)])) {
                $out[mb_strtolower($t)] = $t;
            }
        }

        return array_slice(array_values($out), 0, 40);
    }

    /** @param list<int> $anchorIds (by-ref, sammelt kern-Anker ein) */
    private function gpKomponente(string $phrase, int $gpId, string $name, string $via, float $score, array &$anchorIds): array
    {
        $anker = [];
        foreach ($this->pairing->gpAnkers($gpId) as $a) {
            $anchorIds[] = (int) $a->id;
            $anker[] = ['id' => (int) $a->id, 'slug' => $a->slug, 'display_de' => $a->display_de];
        }

        return ['phrase' => $phrase, 'kind' => 'gp', 'id' => $gpId, 'name' => $name, 'via' => $via, 'score' => round($score, 4), 'anker' => $anker];
    }

    /** @param list<int> $anchorIds (by-ref) — Sub-Rezept trägt seinen kern-Anker bei. */
    private function subKomponente(string $phrase, int $recipeId, string $name, float $score, array &$anchorIds): array
    {
        $kern = DB::table('foodalchemist_recipe_anchor_mappings')
            ->where('recipe_id', $recipeId)->where('role', 'kern')->whereNull('deleted_at')
            ->orderByRaw('COALESCE(ai_confidence, 1.0) DESC')->orderBy('id')->value('anchor_id');
        $anker = [];
        if ($kern !== null) {
            $anchorIds[] = (int) $kern;
            $row = DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $kern)->first(['id', 'slug', 'display_de']);
            if ($row !== null) {
                $anker[] = ['id' => (int) $row->id, 'slug' => $row->slug, 'display_de' => $row->display_de];
            }
        }

        return ['phrase' => $phrase, 'kind' => 'sub', 'id' => $recipeId, 'name' => $name, 'via' => 'match', 'score' => round($score, 4), 'anker' => $anker];
    }

    /** Tragende Anker (Namen) + Verbund-Kanten (ungeordnete Paare, bester Wert/Typ). */
    private function aromaSkelett(array $anchorIds): array
    {
        if ($anchorIds === []) {
            return ['traeger_anker' => [], 'verbund_kanten' => []];
        }
        $namen = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', $anchorIds)
            ->pluck('display_de', 'id');
        $traeger = [];
        foreach ($anchorIds as $id) {
            $traeger[] = ['id' => $id, 'name' => $namen[$id] ?? (string) $id];
        }

        $kanten = [];
        $best = $this->pairing->edgesFor($anchorIds);
        foreach ($best as $a => $ziele) {
            foreach ($ziele as $b => [$w, $typ]) {
                if ($a < $b) {   // jedes Paar einmal
                    $kanten[] = [
                        'a' => $namen[$a] ?? (string) $a,
                        'b' => $namen[$b] ?? (string) $b,
                        'type' => $typ,
                        'weight' => round((float) $w, 3),
                    ];
                }
            }
        }

        return ['traeger_anker' => $traeger, 'verbund_kanten' => $kanten];
    }

    /** Eigene VK-Gerichte nach Anker-Überlappung + Lücken (Anker, die kein Gericht trägt). */
    private function reconstructFromPortfolio(Team $team, array $anchorIds, int $limit): array
    {
        if ($anchorIds === []) {
            return ['kandidaten' => [], 'luecken' => []];
        }
        $portfolioIds = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true)->pluck('id')->all();
        if ($portfolioIds === []) {
            return ['kandidaten' => [], 'luecken' => $this->ankerNamen($anchorIds)];
        }

        $treffer = [];       // recipe_id => [anchor_id => true]
        $abgedeckt = [];     // anchor_id => true (irgendwo im Bestand)
        foreach (['foodalchemist_recipe_anchor_mappings', 'foodalchemist_recipe_process_anchors'] as $tabelle) {
            foreach (DB::table($tabelle)->whereIn('recipe_id', $portfolioIds)
                ->whereIn('anchor_id', $anchorIds)->whereNull('deleted_at')
                ->get(['recipe_id', 'anchor_id']) as $r) {
                $treffer[(int) $r->recipe_id][(int) $r->anchor_id] = true;
                $abgedeckt[(int) $r->anchor_id] = true;
            }
        }

        $namen = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('foodalchemist_recipes.id', array_keys($treffer))
            ->pluck('name', 'id');
        $kandidaten = [];
        foreach ($treffer as $rid => $set) {
            $kandidaten[] = [
                'recipe_id' => $rid,
                'name' => $namen[$rid] ?? (string) $rid,
                'shared_anker' => count($set),
                'von_gesamt' => count($anchorIds),
            ];
        }
        usort($kandidaten, fn ($a, $b) => [$b['shared_anker'], $a['name']] <=> [$a['shared_anker'], $b['name']]);

        $luecken = $this->ankerNamen(array_values(array_filter($anchorIds, fn ($id) => ! isset($abgedeckt[$id]))));

        return ['kandidaten' => array_slice($kandidaten, 0, max(1, $limit)), 'luecken' => $luecken];
    }

    /** @param list<int> $anchorIds @return list<array{id:int, name:string}> */
    private function ankerNamen(array $anchorIds): array
    {
        if ($anchorIds === []) {
            return [];
        }
        $namen = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', $anchorIds)->pluck('display_de', 'id');

        return array_map(fn ($id) => ['id' => (int) $id, 'name' => $namen[$id] ?? (string) $id], $anchorIds);
    }
}
