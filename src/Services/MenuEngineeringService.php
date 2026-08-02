<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 32 · C3 — Menu-Engineering: Popularität × Deckungsbeitrag je Gericht.
 *
 * Die klassische Vier-Felder-Matrix (Kasavana/Smith): jedes Gericht wird gegen den
 * DURCHSCHNITT des betrachteten Portfolios gestellt, nicht gegen eine absolute Schwelle.
 *
 *   Star     — beliebt und ertragreich   → halten, sichtbar platzieren
 *   Renner   — beliebt, wenig Ertrag     → Kosten senken oder Preis prüfen
 *   Schläfer — unbeliebt, ertragreich    → bewerben, besser platzieren
 *   Penner   — unbeliebt und ertragsarm  → überarbeiten oder streichen
 *
 * **Die DB-Achse ist bewusst die Wareneinsatz-Achse**, nicht die Vollkosten-Achse:
 * `ek_portion` gegen `sales_net`, beide an der Standard-Darreichung — dieselbe Rechnung wie
 * in {@see MenuCandidatePoolService} und derselben Zahl folgt die W%-Ampel. Eine Matrix, die
 * ein anderes DB zeigt als die Ampel daneben, ist nicht erklärbar.
 *
 * **Zwei Popularitäts-Quellen, klar getrennt:**
 *  - `sales` — echtes Verkaufs-Ist aus {@see FoodAlchemistSalesFact} (die belastbare Achse).
 *  - `feedback` — Praxis-Bewertungen ({@see FeedbackService}) als v0, solange kein Ist da ist.
 *    Das ist Akzeptanz, nicht Absatz — die Matrix sagt das ausdrücklich, statt es zu verwischen.
 *
 * **Nicht über `MenuCandidatePoolService`**: dessen Pool-Aufbau zieht die Pairing-Anker je
 * Gericht (offener N+1, V-045). Hier wird nur das Zahlenpaar gebraucht, und das kommt aus
 * einem Join.
 */
class MenuEngineeringService
{
    /**
     * Matrix für einen Zeitraum.
     *
     * @return array{quelle:string,von:?string,bis:?string,n:int,avg_db:?float,avg_pop:?float,
     *               umsatz:float,zeilen:list<array<string,mixed>>,quadranten:array<string,int>}
     */
    public function matrix(Team $team, ?string $von = null, ?string $bis = null): array
    {
        $pop = $this->popularitaet($team, $von, $bis);

        $zeilen = [];
        foreach ($this->wirtschaft($team) as $r) {
            $p = $pop['werte'][$r['recipe_id']] ?? null;
            // Ohne Popularitäts-Signal ist die Achse unbekannt — solche Gerichte gehören nicht
            // in die Mittelwert-Bildung, sonst zieht ein nie verkauftes Gericht den Schnitt.
            if ($p === null || $r['db_eur'] === null) {
                continue;
            }
            $zeilen[] = $r + ['popularitaet' => $p['wert'], 'umsatz' => $p['umsatz'] ?? null];
        }

        if ($zeilen === []) {
            return ['quelle' => $pop['quelle'], 'von' => $von, 'bis' => $bis, 'n' => 0,
                'avg_db' => null, 'avg_pop' => null, 'umsatz' => $pop['umsatz'],
                'zeilen' => [], 'quadranten' => ['star' => 0, 'renner' => 0, 'schlaefer' => 0, 'penner' => 0]];
        }

        $avgDb = array_sum(array_column($zeilen, 'db_eur')) / count($zeilen);
        $avgPop = array_sum(array_column($zeilen, 'popularitaet')) / count($zeilen);

        $quadranten = ['star' => 0, 'renner' => 0, 'schlaefer' => 0, 'penner' => 0];
        foreach ($zeilen as $i => $z) {
            $beliebt = $z['popularitaet'] >= $avgPop;
            $ertrag = $z['db_eur'] >= $avgDb;
            $q = match (true) {
                $beliebt && $ertrag => 'star',
                $beliebt => 'renner',
                $ertrag => 'schlaefer',
                default => 'penner',
            };
            $zeilen[$i]['quadrant'] = $q;
            $quadranten[$q]++;
        }

        usort($zeilen, fn ($a, $b) => $b['popularitaet'] <=> $a['popularitaet']);

        return [
            'quelle' => $pop['quelle'],
            'von' => $von, 'bis' => $bis,
            'n' => count($zeilen),
            'avg_db' => round($avgDb, 2),
            'avg_pop' => round($avgPop, 2),
            'umsatz' => $pop['umsatz'],
            'zeilen' => $zeilen,
            'quadranten' => $quadranten,
        ];
    }

    /**
     * Popularität je Gericht. Verkaufs-Ist gewinnt; ohne Ist wird auf Praxis-Feedback
     * zurückgefallen — und das wird als Quelle ausgewiesen, nicht kaschiert.
     *
     * @return array{quelle:string,umsatz:float,werte:array<int,array{wert:float,umsatz:?float}>}
     */
    private function popularitaet(Team $team, ?string $von, ?string $bis): array
    {
        $q = DB::table('foodalchemist_sales_facts')
            ->where('team_id', $team->id)->whereNull('deleted_at')->whereNotNull('recipe_id')
            ->when($von !== null, fn ($qq) => $qq->whereDate('sold_at', '>=', $von))
            ->when($bis !== null, fn ($qq) => $qq->whereDate('sold_at', '<=', $bis))
            ->selectRaw('recipe_id, SUM(qty_sold) AS menge, SUM(revenue_net) AS umsatz')
            ->groupBy('recipe_id')
            ->get();

        if ($q->isNotEmpty()) {
            $werte = [];
            $umsatzGesamt = 0.0;
            foreach ($q as $r) {
                // Ohne Mengenspalte im Export ist der Umsatz die zweitbeste Popularitäts-Achse:
                // besser als nichts, und die Quelle steht daneben.
                $menge = $r->menge !== null ? (float) $r->menge : (float) ($r->umsatz ?? 0);
                $werte[(int) $r->recipe_id] = ['wert' => $menge, 'umsatz' => (float) ($r->umsatz ?? 0)];
                $umsatzGesamt += (float) ($r->umsatz ?? 0);
            }

            return ['quelle' => 'sales', 'umsatz' => round($umsatzGesamt, 2), 'werte' => $werte];
        }

        // v0: menschliche Akzeptanz statt Absatz. Ausdrücklich eine andere Aussage.
        $fb = DB::table('foodalchemist_recipe_feedback as f')
            ->join('foodalchemist_recipes as r', 'r.id', '=', 'f.recipe_id')
            ->where('r.team_id', $team->id)->where('r.is_sales_recipe', true)
            ->whereNull('f.deleted_at')->whereNotNull('f.score')
            ->selectRaw('f.recipe_id, AVG(f.score) AS score')
            ->groupBy('f.recipe_id')
            ->get();

        $werte = [];
        foreach ($fb as $r) {
            $werte[(int) $r->recipe_id] = ['wert' => (float) $r->score, 'umsatz' => null];
        }

        return ['quelle' => 'feedback', 'umsatz' => 0.0, 'werte' => $werte];
    }

    /**
     * Deckungsbeitrag je Portion — Standard-Darreichung, mit Legacy-Fallback (dieselbe Leiter
     * wie `MenuCandidatePoolService::wirtschaft` und `recipeHk`).
     *
     * @return list<array{recipe_id:int,name:string,sales_net:?float,ek_portion:?float,db_eur:?float,db_pct:?float,wareneinsatz_pct:?float,quelle:string}>
     */
    private function wirtschaft(Team $team): array
    {
        $rezepte = FoodAlchemistRecipe::visibleToTeam($team)
            ->where('is_sales_recipe', true)
            ->with(['standardPresentation:id,recipe_id,sales_net,ek_portion,is_standard'])
            ->get(['foodalchemist_recipes.id', 'name', 'sales_net', 'ek_total_eur', 'sales_unit_count']);

        $out = [];
        foreach ($rezepte as $r) {
            $std = $r->standardPresentation;
            $anzahl = max(1, (int) ($r->sales_unit_count ?? 1));

            $vk = $std?->sales_net !== null ? (float) $std->sales_net
                : ($r->sales_net !== null ? (float) $r->sales_net : null);
            $ek = $std?->ek_portion !== null ? (float) $std->ek_portion
                : ($r->ek_total_eur !== null ? (float) $r->ek_total_eur / $anzahl : null);

            $quelle = match (true) {
                $std?->sales_net !== null && $std?->ek_portion !== null => 'darreichung',
                $std?->sales_net !== null || $std?->ek_portion !== null => 'gemischt',
                default => 'legacy',
            };

            $vollstaendig = $vk !== null && $vk > 0 && $ek !== null;
            $out[] = [
                'recipe_id' => (int) $r->id,
                'name' => (string) $r->name,
                'sales_net' => $vk !== null ? round($vk, 2) : null,
                'ek_portion' => $ek !== null ? round($ek, 4) : null,
                'db_eur' => $vollstaendig ? round($vk - $ek, 2) : null,
                'db_pct' => $vollstaendig ? round(($vk - $ek) / $vk * 100, 1) : null,
                'wareneinsatz_pct' => $vollstaendig ? round($ek / $vk * 100, 1) : null,
                'quelle' => $quelle,
            ];
        }

        return $out;
    }
}
