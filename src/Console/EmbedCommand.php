<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * E1 (#507): Backfill der Embedding-Pools. Ein Einstieg für alle Korpora —
 * Wissen (bestehend), GPs + Rezepte (neu). Idempotent über Cores source_hash:
 * unveränderte Einträge werden übersprungen (kein API-Call), Re-Run ist sicher.
 *
 * NACH foodalchemist:import-master laufen lassen (der Import berührt keine
 * core_embeddings, aber neue/geänderte Entitäten müssen nach-embeddet werden).
 */
class EmbedCommand extends Command
{
    protected $signature = 'foodalchemist:embed
        {--pool=all : gps|recipes|knowledge|all}
        {--team= : nur diese reale team_id (Default: alle Partitionen)}';

    protected $description = 'Backfill der Embedding-Pools (GPs, Rezepte, Wissen) für die semantische Recall-Schicht (#507)';

    public function handle(PoolEmbeddingService $pools, KnowledgeEmbeddingService $knowledge): int
    {
        $pool = (string) $this->option('pool');
        $teamOpt = $this->option('team');
        $team = ($teamOpt === null || $teamOpt === '') ? null : (int) $teamOpt;

        if (! in_array($pool, ['gps', 'recipes', 'knowledge', 'all'], true)) {
            $this->error("Unbekannter --pool='{$pool}'. Erlaubt: gps|recipes|knowledge|all.");

            return self::INVALID;
        }

        if (! $pools->isProviderAvailable()) {
            $this->error('Kein Embedding-Provider verfügbar — OPENAI_API_KEY setzen '
                . '(oder EMBEDDING_GEMINI_ENABLED=true + GEMINI_API_KEY).');

            return self::FAILURE;
        }

        $this->info("Embedding-Backfill (pool={$pool}" . ($team !== null ? ", team={$team}" : '')
            . ') — idempotent, unveränderte Einträge werden übersprungen …');

        $rows = [];

        if ($pool === 'gps' || $pool === 'all') {
            $stats = $pools->embedGps($team);
            $rows[] = ['GP', $stats['candidates'], $this->fmtPartitions($stats['partitions'])];
        }

        if ($pool === 'recipes' || $pool === 'all') {
            $stats = $pools->embedRecipes($team);
            $rows[] = ['Rezept (Basis + VK)', $stats['candidates'], $this->fmtPartitions($stats['partitions'])];
        }

        if ($pool === 'knowledge' || $pool === 'all') {
            $stats = $knowledge->embedCorpus();
            $rows[] = ['Wissen (alle Kategorien)', $stats['candidates'], implode(', ', array_keys($stats['kategorien']))];
            $anker = $knowledge->embedAnkers();
            $rows[] = ['Anker (Vokabular)', $anker['candidates'], '—'];
        }

        $this->table(['Pool', 'Kandidaten', 'Partitionen / Details'], $rows);
        $this->line('Provider: ' . ($pools->providerName() ?? 'core-default')
            . ' · Sentinel-Team (global): ' . $pools->globalTeamId());
        $this->line('Hybrid-Recall aktivieren mit: FOODALCHEMIST_SEMANTIC_SEARCH=true');

        return self::SUCCESS;
    }

    /** @param array<int,int> $partitions */
    private function fmtPartitions(array $partitions): string
    {
        if ($partitions === []) {
            return '—';
        }
        $out = [];
        foreach ($partitions as $team => $count) {
            $out[] = "team {$team}: {$count}";
        }

        return implode(', ', $out);
    }
}
