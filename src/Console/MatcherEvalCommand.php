<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\IngredientMatchService;

/**
 * #507 Weg-2: Kalibrierungs-Harness für die DETERMINISTISCHE Terminologie-Schicht
 * (S1 Alias + S2 Anti-Marker) — das Gegenstück zu {@see EmbedEvalCommand}, das nur
 * die reine Embedding-Schicht misst. Fährt dasselbe Golden-Set (E0) gegen die
 * VOLLE Shortlist ({@see IngredientMatchService::candidatesFor}, die die LLM-
 * Disambig speist) und misst Recall@K je Fallklasse + Anti-Marker-Leaks.
 *
 * Zwei Zeilen: „deterministisch" (Flag AUS = Lexik+S1+S2) und, mit --semantic,
 * „hybrid" (Flag AN = zusätzlich Embedding-Recall). So sieht man getrennt, ob die
 * Alias-Map/Negativliste allein trägt und ob die Semantik additiv hilft oder leakt.
 *
 * KEIN Floor-Sweep: S1/S2 sind schwellenlos. Der Embedding-Floor (falls --semantic)
 * kommt aus der Config wie in Produktion.
 */
class MatcherEvalCommand extends Command
{
    protected $signature = 'foodalchemist:matcher-eval
        {--team= : reale team_id (Master/Katalog), gegen deren Bestand candidatesFor läuft}
        {--k=15 : Shortlist-Cap (Recall@K)}
        {--fixture= : Pfad zum Golden-Set (Default: tests/Fixtures/SemanticGoldenSet.php)}
        {--semantic : zusätzlich die Hybrid-Zeile (Flag AN, braucht Provider+Backfill) messen}
        {--details : jeden Fall einzeln ausgeben}';

    protected $description = '#507 Weg-2: Golden-Set → Recall@K + Anti-Marker-Leaks der vollen Matcher-Shortlist (deterministisch S1/S2, opt. hybrid)';

    public function handle(IngredientMatchService $matcher): int
    {
        $teamId = $this->option('team');
        if ($teamId === null || $teamId === '') {
            $this->error('--team=<id> ist Pflicht (Bestand + Sichtbarkeit hängen daran).');

            return self::INVALID;
        }
        $team = Team::find((int) $teamId);
        if ($team === null) {
            $this->error("Team {$teamId} nicht gefunden.");

            return self::INVALID;
        }

        $fixture = (string) ($this->option('fixture') ?: dirname(__DIR__, 2) . '/tests/Fixtures/SemanticGoldenSet.php');
        if (! is_file($fixture)) {
            $this->error("Golden-Set nicht gefunden: {$fixture}");

            return self::INVALID;
        }
        /** @var list<array> $golden */
        $golden = require $fixture;
        $k = max(1, (int) $this->option('k'));

        $modes = [['label' => 'deterministisch (S1+S2)', 'semantic' => false]];
        if ($this->option('semantic')) {
            $modes[] = ['label' => 'hybrid (+Embedding)', 'semantic' => true];
        }

        $rows = [];
        $lastReport = null;
        $lastNames = [];
        foreach ($modes as $mode) {
            config(['foodalchemist.semantic_search.enabled' => $mode['semantic']]);
            app()->forgetInstance(IngredientMatchService::class);
            /** @var IngredientMatchService $m */
            $m = app(IngredientMatchService::class);

            $this->info("Matcher-Lauf: {$mode['label']} (Team {$team->id}) …");
            $namesByQuery = [];
            foreach ($golden as $case) {
                $q = (string) ($case['query'] ?? '');
                if ($q === '' || isset($namesByQuery[$q])) {
                    continue;
                }
                $cands = $m->candidatesFor($team, $q, null, $k);
                $namesByQuery[$q] = array_map(static fn ($c) => (string) ($c['name'] ?? ''), $cands);
            }

            $report = $this->evaluate($golden, $namesByQuery);
            $lastReport = $report;
            $lastNames = $namesByQuery;
            $rows[] = [
                $mode['label'],
                $this->pct($report['recall_overall']),
                $this->pct($report['by_relation']['translation'] ?? null),
                $this->pct($report['by_relation']['synonym'] ?? null),
                $this->pct($report['by_relation']['regional'] ?? null),
                $this->pct($report['by_relation']['compound'] ?? null),
                $report['anti_leaks'] . '/' . $report['n_negative'],
            ];
        }

        $this->table(['Modus', 'Recall@' . $k, 'Übers.', 'Synonym', 'regional', 'Komposit', 'Anti-Leaks'], $rows);

        if ($lastReport !== null && $lastReport['anti_leaks'] === 0) {
            $this->info('✓ 0 Anti-Marker-Leaks — die Negativliste (S2) hält die Shortlist sauber, Flag kann scharf.');
        } elseif ($lastReport !== null) {
            $this->warn("⚠ {$lastReport['anti_leaks']} Anti-Marker-Leak(s) — S2-Regel fehlt für einen Fall, prüfen.");
        }

        if ($this->option('details') && $lastReport !== null) {
            $this->details($golden, $lastNames);
        }

        return self::SUCCESS;
    }

    /**
     * Reine Auswertung (testbar): Recall der Positiv-Fälle (gesamt + je Klasse) +
     * Anti-Marker-Leaks über die name-Shortlist je Query.
     *
     * @param  list<array>  $golden
     * @param  array<string, list<string>>  $namesByQuery
     * @return array{recall_overall: ?float, by_relation: array<string,?float>, anti_leaks:int, n_positive:int, n_negative:int}
     */
    public function evaluate(array $golden, array $namesByQuery): array
    {
        $positives = array_values(array_filter($golden, static fn ($c) => ($c['polarity'] ?? 'positive') === 'positive'));
        $negatives = array_values(array_filter($golden, static fn ($c) => ($c['polarity'] ?? '') === 'negative'));

        $posHit = 0;
        $byRelHit = [];
        $byRelTotal = [];
        foreach ($positives as $case) {
            $rel = (string) ($case['relation'] ?? 'unknown');
            $byRelTotal[$rel] = ($byRelTotal[$rel] ?? 0) + 1;
            if ($this->listHas($namesByQuery[(string) $case['query']] ?? [], (string) ($case['expect'] ?? ''))) {
                $posHit++;
                $byRelHit[$rel] = ($byRelHit[$rel] ?? 0) + 1;
            }
        }
        $leaks = 0;
        foreach ($negatives as $case) {
            if ($this->listHas($namesByQuery[(string) $case['query']] ?? [], (string) ($case['forbid'] ?? ''))) {
                $leaks++;
            }
        }
        $byRel = [];
        foreach ($byRelTotal as $rel => $total) {
            $byRel[$rel] = $total > 0 ? (float) (($byRelHit[$rel] ?? 0) / $total) : null;
        }

        return [
            'recall_overall' => count($positives) > 0 ? (float) ($posHit / count($positives)) : null,
            'by_relation' => $byRel,
            'anti_leaks' => $leaks,
            'n_positive' => count($positives),
            'n_negative' => count($negatives),
        ];
    }

    /** @param list<string> $names */
    private function listHas(array $names, string $target): bool
    {
        if ($target === '') {
            return false;
        }
        foreach ($names as $name) {
            if ($this->nameMatches($target, $name)) {
                return true;
            }
        }

        return false;
    }

    /** Token-Subset (identisch zu EmbedEval): alle Tokens von $needle ⊆ $haystack. */
    public function nameMatches(string $needle, string $haystack): bool
    {
        $nt = $this->tokens($needle);
        $ht = $this->tokens($haystack);
        if ($nt === [] || $ht === []) {
            return false;
        }
        $set = array_flip($ht);
        foreach ($nt as $t) {
            if (! isset($set[$t])) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function tokens(string $s): array
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);

        return array_values(array_map(
            [$this, 'stem'],
            array_filter(explode(' ', trim($s)), static fn ($t) => $t !== ''),
        ));
    }

    /**
     * Konservativer DE-Plural-Fold für den MESS-Vergleich (nicht für den Matcher):
     * „Tomaten"=„Tomate", „Garnelen"=„Garnele". Suffixe -en/-n/-e — bewusst NICHT
     * -s, damit „Bries" ≠ „Brie" bleibt (Anti-Marker-Schutz). Min-Stamm-Länge 3.
     */
    private function stem(string $t): string
    {
        foreach (['en', 'n', 'e'] as $suf) {
            if (str_ends_with($t, $suf) && mb_strlen($t) - mb_strlen($suf) >= 3) {
                return mb_substr($t, 0, mb_strlen($t) - mb_strlen($suf));
            }
        }

        return $t;
    }

    private function pct(?float $v): string
    {
        return $v === null ? '—' : round($v * 100) . '%';
    }

    /** @param list<array> $golden */
    private function details(array $golden, array $namesByQuery): void
    {
        $this->line('');
        $this->line('Einzelfälle (letzter Modus):');
        foreach ($golden as $case) {
            $q = (string) ($case['query'] ?? '');
            $ziel = (string) ($case['expect'] ?? $case['forbid'] ?? '');
            $neg = ($case['polarity'] ?? '') === 'negative';
            $treff = $this->listHas($namesByQuery[$q] ?? [], $ziel);
            $ok = $neg ? ! $treff : $treff;
            $this->line(sprintf('  %s  %-26s %s %s', $ok ? '✓' : '✗', $q, $neg ? '↛' : '→', $ziel));
        }
    }
}
