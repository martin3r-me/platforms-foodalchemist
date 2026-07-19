<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\Ai\SemanticRetrievalService;

/**
 * E5 (#507): Kalibrierungs-Harness für den semantischen Retrieval-Layer.
 *
 * Fährt das Golden-Set (E0, tests/Fixtures/SemanticGoldenSet.php) gegen den
 * ECHTEN Embedder + die embeddeten Pools und misst je Kandidaten-Floor:
 *  - Recall@K der Positiv-Fälle (gesamt + je Fallklasse), und
 *  - Anti-Marker-Verletzungen (Brie↛Bries etc. dürfen NICHT in die Shortlist).
 * Empfiehlt am Ende den Floor, der Recall maximiert bei 0 Anti-Marker-Verletzungen.
 *
 * VORAUSSETZUNG: Provider verfügbar (OpenAI/Gemini) UND Pools gebackfillt
 * (foodalchemist:embed --pool=all). Ohne Vektoren im Index → Recall 0 (Warnung).
 * Das ist das Pflicht-Gate VOR FOODALCHEMIST_SEMANTIC_SEARCH=true:
 * der Startwert pool_sem_floor 0.55 ist Gemini-768d-geeicht, für OpenAI ungeprüft.
 */
class EmbedEvalCommand extends Command
{
    protected $signature = 'foodalchemist:embed-eval
        {--team= : reale team_id, deren Partitionen durchsucht werden (z.B. Master/Katalog)}
        {--k=15 : Shortlist-Cap (Recall@K, Default 15 = V-04-Cap)}
        {--floors=0.30,0.35,0.40,0.45,0.50,0.55,0.60,0.65,0.70 : zu prüfende Kandidaten-Floors}
        {--fixture= : Pfad zum Golden-Set (Default: tests/Fixtures/SemanticGoldenSet.php)}
        {--details : jeden Fall einzeln ausgeben (Treffer/Fehltreffer)}';

    protected $description = 'E5-Kalibrierung: Golden-Set → Recall@K je Floor + Anti-Marker-Gegenprobe + Floor-Vorschlag (#507)';

    public function handle(SemanticRetrievalService $sem): int
    {
        $teamId = $this->option('team');
        if ($teamId === null || $teamId === '') {
            $this->error('--team=<id> ist Pflicht (die Partitionen — eigene Ahnenkette ∪ Global — hängen daran).');

            return self::INVALID;
        }
        $team = Team::find((int) $teamId);
        if ($team === null) {
            $this->error("Team {$teamId} nicht gefunden.");

            return self::INVALID;
        }

        // Eval-Modus: Flag-unabhängig messen (wir kalibrieren, wir bedienen nicht).
        config(['foodalchemist.semantic_search.enabled' => true]);
        if (! $sem->enabled()) {
            $this->error('Kein Embedding-Provider verfügbar — OPENAI_API_KEY (Core) bzw. EMBEDDING_GEMINI_ENABLED setzen.');

            return self::FAILURE;
        }

        $fixture = (string) ($this->option('fixture') ?: dirname(__DIR__, 2) . '/tests/Fixtures/SemanticGoldenSet.php');
        if (! is_file($fixture)) {
            $this->error("Golden-Set nicht gefunden: {$fixture}");

            return self::INVALID;
        }
        /** @var list<array> $golden */
        $golden = require $fixture;
        $k = max(1, (int) $this->option('k'));
        $floors = array_values(array_filter(array_map(
            static fn ($f) => (float) trim($f),
            explode(',', (string) $this->option('floors')),
        )));

        // 1) Rohe Treffer je Query holen (Floor 0 → alles; Sweep passiert in-memory).
        $this->info("Suche läuft (Provider: {$sem->poolSemFloor()}-Config, Team {$team->id}) …");
        $hitsByQuery = [];
        $leer = 0;
        foreach ($golden as $case) {
            $q = (string) ($case['query'] ?? '');
            if ($q === '' || isset($hitsByQuery[$q])) {
                continue;
            }
            $raw = $sem->candidates($team, $q, [
                PoolEmbeddingService::ENTITY_TYPE_GP, PoolEmbeddingService::ENTITY_TYPE_RECIPE,
            ], 50, 0.0);
            $hitsByQuery[$q] = $this->resolveNames($team, $raw);
            if ($hitsByQuery[$q] === []) {
                $leer++;
            }
        }
        if ($leer === count($hitsByQuery)) {
            $this->warn('⚠ ALLE Queries lieferten 0 Treffer — sind die Pools gebackfillt? (foodalchemist:embed --pool=all)');
        }

        // 2) Floor-Sweep auswerten.
        $report = $this->evaluate($golden, $hitsByQuery, $floors, $k);

        // 3) Ausgabe.
        $rows = [];
        foreach ($report['per_floor'] as $r) {
            $rows[] = [
                number_format($r['floor'], 2),
                $this->pct($r['recall_overall']),
                $this->pct($r['recall_by_relation']['translation'] ?? null),
                $this->pct($r['recall_by_relation']['synonym'] ?? null),
                $this->pct($r['recall_by_relation']['regional'] ?? null),
                $this->pct($r['recall_by_relation']['compound'] ?? null),
                $r['anti_violations'] . '/' . $report['n_negative'],
            ];
        }
        $this->table(['Floor', 'Recall@' . $k, 'Übers.', 'Synonym', 'regional', 'Komposit', 'Anti-Verl.'], $rows);

        if ($report['suggested_floor'] !== null) {
            $s = $report['suggested']; // Zeile
            $this->info(sprintf(
                '→ Vorschlag pool_sem_floor = %.2f  (Recall@%d %s, Anti-Marker-Verletzungen %d/%d)',
                $report['suggested_floor'], $k, $this->pct($s['recall_overall']), $s['anti_violations'], $report['n_negative'],
            ));
            $this->line('  Setzen via env FOODALCHEMIST_SEMANTIC_POOL_FLOOR (config je Modell, kein Hardcode).');
        } else {
            $this->warn('Kein Floor ohne Anti-Marker-Verletzung gefunden — Golden-Set/Embed-Text prüfen, NICHT blind scharfstellen.');
        }

        if ($this->option('details')) {
            $this->details($golden, $hitsByQuery, $report['suggested_floor'] ?? $sem->poolSemFloor(), $k);
        }

        return self::SUCCESS;
    }

    /**
     * Reine Auswertung (testbar ohne Provider): Floor-Sweep über die schon
     * name-aufgelösten Treffer je Query.
     *
     * @param  list<array>  $golden
     * @param  array<string, list<array{name:string,score:float}>>  $hitsByQuery  Score-absteigend sortiert
     * @param  list<float>  $floors
     * @return array{per_floor: list<array>, suggested_floor: ?float, suggested: ?array, n_positive:int, n_negative:int}
     */
    public function evaluate(array $golden, array $hitsByQuery, array $floors, int $k): array
    {
        $positives = array_values(array_filter($golden, static fn ($c) => ($c['polarity'] ?? 'positive') === 'positive'));
        $negatives = array_values(array_filter($golden, static fn ($c) => ($c['polarity'] ?? '') === 'negative'));

        $perFloor = [];
        foreach ($floors as $floor) {
            $posHit = 0;
            $byRelHit = [];
            $byRelTotal = [];
            foreach ($positives as $case) {
                $rel = (string) ($case['relation'] ?? 'unknown');
                $byRelTotal[$rel] = ($byRelTotal[$rel] ?? 0) + 1;
                if ($this->trifft($hitsByQuery[(string) $case['query']] ?? [], (string) ($case['expect'] ?? ''), $floor, $k)) {
                    $posHit++;
                    $byRelHit[$rel] = ($byRelHit[$rel] ?? 0) + 1;
                }
            }
            $antiViol = 0;
            foreach ($negatives as $case) {
                if ($this->trifft($hitsByQuery[(string) $case['query']] ?? [], (string) ($case['forbid'] ?? ''), $floor, $k)) {
                    $antiViol++;
                }
            }
            $recallByRel = [];
            foreach ($byRelTotal as $rel => $total) {
                $recallByRel[$rel] = $total > 0 ? (float) (($byRelHit[$rel] ?? 0) / $total) : null;
            }
            $perFloor[] = [
                'floor' => $floor,
                'recall_overall' => count($positives) > 0 ? (float) ($posHit / count($positives)) : null,
                'recall_by_relation' => $recallByRel,
                'anti_violations' => $antiViol,
            ];
        }

        // Vorschlag: 0 Anti-Verletzungen, dann max Recall, bei Gleichstand niedrigster Floor.
        $sauber = array_values(array_filter($perFloor, static fn ($r) => $r['anti_violations'] === 0));
        $kandidaten = $sauber !== [] ? $sauber : $perFloor;   // Fallback: keiner sauber → bester unter allen
        usort($kandidaten, static function ($a, $b) {
            return [$a['anti_violations'], -($b['recall_overall'] ?? 0), $a['floor']]
                <=> [$b['anti_violations'], -($a['recall_overall'] ?? 0), $b['floor']];
        });
        $best = $kandidaten[0] ?? null;

        return [
            'per_floor' => $perFloor,
            'suggested_floor' => ($sauber !== [] && $best !== null) ? $best['floor'] : null,
            'suggested' => $best,
            'n_positive' => count($positives),
            'n_negative' => count($negatives),
        ];
    }

    /** Trifft ein erwarteter/verbotener Name in die Top-K-über-Floor-Shortlist? */
    private function trifft(array $hits, string $target, float $floor, int $k): bool
    {
        if ($target === '') {
            return false;
        }
        $top = [];
        foreach ($hits as $h) {                          // bereits Score-absteigend
            if ((float) $h['score'] < $floor) {
                continue;
            }
            $top[] = $h;
            if (count($top) >= $k) {
                break;
            }
        }
        foreach ($top as $h) {
            if ($this->nameMatches($target, (string) $h['name'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Token-Subset-Match (testbar): alle Tokens von $needle sind Tokens von
     * $haystack. Verhindert das Anti-Marker-Falschpositiv „Brie" ⊄ „Bries"
     * (Substring wäre falsch — Token-Gleichheit ist der Schutz).
     */
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
     * Konservativer DE-Plural-Fold für den MESS-Vergleich: „Tomaten"=„Tomate".
     * Suffixe -en/-n/-e — NICHT -s (schützt „Bries" ≠ „Brie"). Min-Stamm-Länge 3.
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

    /**
     * entity_id-Treffer → [name, score], Namen team-sichtbar aufgelöst (GP + Rezept).
     *
     * @param  list<array{entity_type:string,entity_id:int,score:float}>  $raw
     * @return list<array{name:string,score:float}>
     */
    private function resolveNames(Team $team, array $raw): array
    {
        $gpIds = $recIds = [];
        foreach ($raw as $h) {
            if ($h['entity_type'] === PoolEmbeddingService::ENTITY_TYPE_GP) {
                $gpIds[] = (int) $h['entity_id'];
            } else {
                $recIds[] = (int) $h['entity_id'];
            }
        }
        $gpNames = $gpIds === [] ? collect() : FoodAlchemistGp::visibleToTeam($team)
            ->whereIn('id', $gpIds)->pluck('name', 'id');
        $recNames = $recIds === [] ? collect() : FoodAlchemistRecipe::visibleToTeam($team)
            ->whereIn('id', $recIds)->pluck('name', 'id');

        $out = [];
        foreach ($raw as $h) {
            $name = $h['entity_type'] === PoolEmbeddingService::ENTITY_TYPE_GP
                ? $gpNames->get((int) $h['entity_id'])
                : $recNames->get((int) $h['entity_id']);
            if ($name !== null) {
                $out[] = ['name' => (string) $name, 'score' => (float) $h['score']];
            }
        }

        return $out;   // candidates() liefert bereits Score-absteigend
    }

    private function pct(?float $v): string
    {
        return $v === null ? '—' : round($v * 100) . '%';
    }

    /** @param list<array> $golden */
    private function details(array $golden, array $hitsByQuery, float $floor, int $k): void
    {
        $this->line('');
        $this->line("Einzelfälle @ Floor " . number_format($floor, 2) . ':');
        foreach ($golden as $case) {
            $q = (string) ($case['query'] ?? '');
            $ziel = (string) ($case['expect'] ?? $case['forbid'] ?? '');
            $neg = ($case['polarity'] ?? '') === 'negative';
            $treff = $this->trifft($hitsByQuery[$q] ?? [], $ziel, $floor, $k);
            $ok = $neg ? ! $treff : $treff;
            $this->line(sprintf('  %s  %-26s %s %s', $ok ? '✓' : '✗', $q, $neg ? '↛' : '→', $ziel));
        }
    }
}
