<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;

/**
 * R2.7 — Portfolio-Benchmark (BHG-intern). Aggregiert Portfolio-Kennzahlen je Team
 * und vergleicht das eigene Team gegen den anonymisierten Median der Peer-Teams
 * DERSELBEN Root-Team-Kette (Netzwerk-Effekt, mit jedem Caterer stärker).
 *
 * Datenschutz-Grenze (hart): nur Aggregat-Kennzahlen, KEINE Fremd-Gericht-Details,
 * KEINE Peer-Namen im Vergleich; nur innerhalb einer Root-Kette (kein Cross-Kunde).
 * Extern-Benchmark (fremde Caterer) bewusst NICHT enthalten (offene rechtl. Frage).
 */
class BenchmarkService
{
    /** Die Kennzahl-Schlüssel + Anzeige-Meta (Reihenfolge = Anzeige). */
    public const KENNZAHLEN = [
        'n_dishes' => ['label' => 'Gerichte im Portfolio', 'unit' => '', 'besser' => 'hoch'],
        'ek_coverage_pct' => ['label' => 'EK-Abdeckung', 'unit' => '%', 'besser' => 'hoch'],
        'allergen_high_pct' => ['label' => 'Allergen-Konfidenz „hoch"', 'unit' => '%', 'besser' => 'hoch'],
        'forms_complete_pct' => ['label' => 'Formen-Vollständigkeit', 'unit' => '%', 'besser' => 'hoch'],
        'avg_w_pct' => ['label' => 'Ø Wareneinsatz', 'unit' => '%', 'besser' => 'niedrig'],
        'rating_avg' => ['label' => 'Ø Bewertung', 'unit' => '/5', 'besser' => 'hoch'],
    ];

    /**
     * Portfolio-Kennzahlen EINES Teams (nur team-eigene Verkaufsrezepte).
     *
     * @return array{n_dishes:int,ek_coverage_pct:?float,allergen_high_pct:?float,forms_complete_pct:?float,avg_w_pct:?float,rating_avg:?float}
     */
    public function kpisFuerTeam(int $teamId): array
    {
        $base = DB::table('foodalchemist_recipes')
            ->where('team_id', $teamId)->where('is_sales_recipe', true)->whereNull('deleted_at');

        $n = (clone $base)->count();
        if ($n === 0) {
            return ['n_dishes' => 0, 'ek_coverage_pct' => null, 'allergen_high_pct' => null,
                'forms_complete_pct' => null, 'avg_w_pct' => null, 'rating_avg' => null];
        }

        $mitEk = (clone $base)->where('ek_total_eur', '>', 0)->count();
        $allergenHoch = (clone $base)->where('allergens_confidence', 'high')->count();

        // Ø Wareneinsatz % = Ø(EK/VK) über bepreiste Gerichte (VK>0 UND EK>0).
        // PHP-seitig (engine-agnostisch, 07 §7): decimal-Casts sind auf SQLite TEXT →
        // raw-SQL-Division verhält sich dialektabhängig, darum in PHP rechnen.
        $preiszeilen = (clone $base)->where('sales_net', '>', 0)->where('ek_total_eur', '>', 0)
            ->get(['ek_total_eur', 'sales_net']);
        $wPct = null;
        if ($preiszeilen->isNotEmpty()) {
            $summe = 0.0;
            foreach ($preiszeilen as $z) {
                $summe += (float) $z->ek_total_eur / (float) $z->sales_net * 100;
            }
            $wPct = $summe / $preiszeilen->count();
        }

        // Formen-Vollständigkeit: Gerichte mit ≥1 Darreichung
        $mitForm = (clone $base)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_recipe_presentations as p')
                ->whereColumn('p.recipe_id', 'foodalchemist_recipes.id')->whereNull('p.deleted_at'))
            ->count();

        // Ø Bewertung: Feedback-Scores auf den team-eigenen Gerichten
        $ratingAvg = DB::table('foodalchemist_recipe_feedback as f')
            ->join('foodalchemist_recipes as r', 'r.id', '=', 'f.recipe_id')
            ->where('r.team_id', $teamId)->where('r.is_sales_recipe', true)
            ->whereNull('f.deleted_at')->whereNotNull('f.score')
            ->avg('f.score');

        return [
            'n_dishes' => $n,
            'ek_coverage_pct' => round($mitEk / $n * 100, 1),
            'allergen_high_pct' => round($allergenHoch / $n * 100, 1),
            'forms_complete_pct' => round($mitForm / $n * 100, 1),
            'avg_w_pct' => $wPct !== null ? round((float) $wPct, 1) : null,
            'rating_avg' => $ratingAvg !== null ? round((float) $ratingAvg, 1) : null,
        ];
    }

    /**
     * Benchmark des Teams gegen den Peer-Median seiner Root-Kette.
     * Peers = alle anderen Teams derselben Root-Kette MIT Portfolio (n_dishes>0).
     *
     * @return array{team_kpis:array,peer_median:array,n_peers:int,kennzahlen:array}
     */
    public function benchmark(Team $team): array
    {
        $teamKpis = $this->kpisFuerTeam((int) $team->id);

        $peerIds = array_values(array_diff($this->netzTeamIds($team), [(int) $team->id]));
        $peerKpis = [];
        foreach ($peerIds as $pid) {
            $k = $this->kpisFuerTeam($pid);
            if ($k['n_dishes'] > 0) {
                $peerKpis[] = $k; // nur Teams mit Portfolio zählen als Peer (anonym, kein Name/Detail)
            }
        }

        $median = [];
        foreach (array_keys(self::KENNZAHLEN) as $key) {
            $werte = array_values(array_filter(array_map(fn ($k) => $k[$key], $peerKpis), fn ($v) => $v !== null));
            $median[$key] = $werte === [] ? null : $this->median($werte);
        }

        return [
            'team_kpis' => $teamKpis,
            'peer_median' => $median,
            'n_peers' => count($peerKpis),
            'kennzahlen' => self::KENNZAHLEN,
        ];
    }

    /**
     * Alle Team-ids der Root-Kette (Root = oberster Vorfahre, dann BFS abwärts).
     * Definiert das „BHG-interne" Netz; keine Teams außerhalb dieser Wurzel.
     *
     * @return list<int>
     */
    public function netzTeamIds(Team $team): array
    {
        $root = $team;
        $guard = 0;
        while ($root->parent_team_id !== null && $guard < 32) {
            $eltern = Team::find($root->parent_team_id);
            if ($eltern === null) {
                break;
            }
            $root = $eltern;
            $guard++;
        }

        $ids = [(int) $root->id];
        $frontier = [(int) $root->id];
        $guard = 0;
        while ($frontier !== [] && $guard < 64) {
            $kinder = Team::query()->whereIn('parent_team_id', $frontier)->pluck('id')->map(fn ($v) => (int) $v)->all();
            $neu = array_values(array_diff($kinder, $ids));
            if ($neu === []) {
                break;
            }
            $ids = array_merge($ids, $neu);
            $frontier = $neu;
            $guard++;
        }

        return array_values(array_unique($ids));
    }

    /** @param list<float|int> $werte */
    private function median(array $werte): float
    {
        sort($werte);
        $n = count($werte);
        $mid = intdiv($n, 2);
        $m = $n % 2 === 1 ? (float) $werte[$mid] : ((float) $werte[$mid - 1] + (float) $werte[$mid]) / 2;

        return round($m, 1);
    }
}
