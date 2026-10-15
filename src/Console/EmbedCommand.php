<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\Ai\SupplierItemEmbedBackfill;

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
        {--pool=all : gps|recipes|knowledge|suppliers|concepts|foodbooks|lab_notes|speisekarten|angebote|pakete|formate|la|all}
        {--team= : nur diese reale team_id (Default: alle Partitionen)}
        {--purge : (nur pool=knowledge/all) verwaiste Vektoren entfernen — Probe-Delete über [1..maxId], off-peak (A2)}
        {--alt-partition= : (nur pool=knowledge/all) NACH dem Embedden die genannte Partition leerräumen — für Dossiers, die das Team gewechselt haben (Umzug auf global). purgeStale erreicht die nicht.}
        {--missing : (nur pool=la) nur Artikel OHNE Vektor — Bestand wird einmal je Partition gelesen (Qdrant-Scroll), kein Hash-Abruf je Artikel}
        {--ids= : (nur pool=la) nur diese Artikel-IDs, kommagetrennt}
        {--limit= : (nur pool=la) höchstens so viele Artikel in diesem Lauf}
        {--batch=500 : (nur pool=la) Artikel je Provider-Aufruf (max 1000)}
        {--sleep=0 : (nur pool=la) Pause in Sekunden zwischen zwei Stapeln}
        {--until= : (nur pool=la) Zeitfenster-Ende HH:MM (Serverzeit); danach bricht der Lauf sauber ab, Folgelauf macht weiter}
        {--dry-run : (nur pool=la) nur zählen, was fehlen würde — kein Provider-Aufruf}';

    protected $description = 'Backfill der Embedding-Pools (GPs, Rezepte, Wissen) für die semantische Recall-Schicht (#507)';

    public function handle(PoolEmbeddingService $pools, KnowledgeEmbeddingService $knowledge): int
    {
        $pool = (string) $this->option('pool');
        $teamOpt = $this->option('team');
        $team = ($teamOpt === null || $teamOpt === '') ? null : (int) $teamOpt;

        $allowed = ['gps', 'recipes', 'knowledge', 'suppliers', 'concepts', 'foodbooks', 'lab_notes',
            'speisekarten', 'angebote', 'pakete', 'formate', 'la', 'all'];
        if (! in_array($pool, $allowed, true)) {
            $this->error("Unbekannter --pool='{$pool}'. Erlaubt: " . implode('|', $allowed) . '.');

            return self::INVALID;
        }

        if (! $pools->isProviderAvailable()) {
            $this->error('Kein Embedding-Provider verfügbar — OPENAI_API_KEY setzen '
                . '(oder EMBEDDING_GEMINI_ENABLED=true + GEMINI_API_KEY).');

            return self::FAILURE;
        }

        $laOpt = ['missing', 'ids', 'limit', 'sleep', 'until', 'dry-run'];
        $laGesetzt = array_filter($laOpt, fn (string $o): bool => $o === 'missing' || $o === 'dry-run'
            ? (bool) $this->option($o)
            : ($this->option($o) !== null && $this->option($o) !== '' && ! ($o === 'sleep' && (float) $this->option($o) === 0.0)));
        if ($pool !== 'la' && $laGesetzt !== []) {
            $this->error('--'.implode(', --', $laGesetzt).' gilt nur mit --pool=la.');

            return self::INVALID;
        }
        $laOptionen = [];
        if ($pool === 'la') {
            $laOptionen = $this->laOptionen();
            if ($laOptionen === null) {
                return self::INVALID;
            }
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

        if ($pool === 'suppliers' || $pool === 'all') {
            $stats = $pools->embedSuppliers($team);
            $rows[] = ['Lieferant', $stats['candidates'], $this->fmtPartitions($stats['partitions'])];
        }

        if ($pool === 'concepts' || $pool === 'all') {
            $stats = $pools->embedConcepts($team);
            $rows[] = ['Konzept', $stats['candidates'], $this->fmtPartitions($stats['partitions'])];
        }

        if ($pool === 'foodbooks' || $pool === 'all') {
            $stats = $pools->embedFoodbooks($team);
            $rows[] = ['Foodbook', $stats['candidates'], $this->fmtPartitions($stats['partitions'])];
        }

        if ($pool === 'lab_notes' || $pool === 'all') {
            $stats = $pools->embedLabNotes($team);
            $rows[] = ['Lab-Note', $stats['candidates'], $this->fmtPartitions($stats['partitions'])];
        }

        if ($pool === 'speisekarten' || $pool === 'all') {
            $stats = $pools->embedSpeisekarten($team);
            $rows[] = ['Speisekarte', $stats['candidates'], $this->fmtPartitions($stats['partitions'])];
        }

        if ($pool === 'angebote' || $pool === 'all') {
            $stats = $pools->embedAngebote($team);
            $rows[] = ['Angebot', $stats['candidates'], $this->fmtPartitions($stats['partitions'])];
        }

        if ($pool === 'pakete' || $pool === 'all') {
            $stats = $pools->embedPakete($team);
            $rows[] = ['Paket', $stats['candidates'], $this->fmtPartitions($stats['partitions'])];
        }

        if ($pool === 'formate' || $pool === 'all') {
            $stats = $pools->embedFormate($team);
            $rows[] = ['Format', $stats['candidates'], $this->fmtPartitions($stats['partitions'])];
        }

        if ($pool === 'knowledge' || $pool === 'all') {
            $stats = $knowledge->embedCorpus(null, (bool) $this->option('purge'));
            $rows[] = ['Wissen (alle Kategorien)', $stats['candidates'], implode(', ', array_keys($stats['kategorien']))];
            if (isset($stats['purge'])) {
                $rows[] = ['Wissen — Purge (Waisen)', $stats['purge']['deleted'], 'probed: ' . $stats['purge']['probed']];
            }
            // Zweite Hälfte des Umzugs: die Vektoren der VERLASSENEN Partition. Erst embedden
            // (neue Partition gefüllt), dann die alte leeren — in dieser Reihenfolge gibt es
            // keinen Moment, in dem das Dossier gar nicht findbar ist.
            if (($alt = $this->option('alt-partition')) !== null && is_numeric($alt)) {
                $weg = $knowledge->purgePartition((int) $alt);
                $rows[] = ['Wissen — Alt-Partition '.(int) $alt, $weg['deleted'], 'Vektoren geräumt'];
            }
            $anker = $knowledge->embedAnkers();
            $rows[] = ['Anker (Vokabular)', $anker['candidates'], '—'];
        }

        // LA-Pool (~264k) bewusst NUR bei explizitem --pool=la — nicht Teil von 'all'
        // (Größe + off-peak, Spec 15 §5c). Braucht den gerouteten Qdrant-Store.
        if ($pool === 'la') {
            if ($laOptionen['gezielt']) {
                // Nur-fehlende / ID-Liste / Limit / Pause / Zeitfenster → eigener Lauf (kein Vollabgleich).
                $laRes = $this->laLauf($laOptionen, $team);
                $rows[] = [$laOptionen['dry_run'] ? 'LA — würden eingebettet' : 'LA — eingebettet', $laRes['embedded'], $this->fmtPartitions($laRes['partitions'])];
                $rows[] = ['LA — geprüft / ohne Vektor', $laRes['scanned'], 'ohne Vektor: '.$laRes['missing']];
                if ($laRes['failed_batches'] > 0) {
                    $rows[] = ['LA — Stapel mit Fehler', $laRes['failed_batches'], count($laRes['failed_ids']).' Artikel, IDs: '
                        .implode(',', array_slice($laRes['failed_ids'], 0, 20)).(count($laRes['failed_ids']) > 20 ? ' …' : '')];
                }
                $this->table(['Pool', 'Kandidaten', 'Partitionen / Details'], $rows);
                if ($laRes['stopped'] !== null) {
                    $this->warn('Lauf vorzeitig beendet: '.[
                        'limit' => '--limit erreicht', 'zeitfenster' => '--until erreicht',
                        'fehler' => 'zu viele Stapelfehler in Folge (Provider/Store prüfen) — Details in laravel.log',
                    ][$laRes['stopped']].'. Folgelauf mit --missing macht dort weiter.');
                }

                return $laRes['stopped'] === 'fehler' ? self::FAILURE : self::SUCCESS;
            }
            $stats = $pools->embedSupplierItems($team);
            $rows[] = ['Lieferantenartikel (LA)', $stats['candidates'], $this->fmtPartitions($stats['partitions'])];
        }

        $this->table(['Pool', 'Kandidaten', 'Partitionen / Details'], $rows);
        $this->line('Provider: ' . ($pools->providerName() ?? 'core-default')
            . ' · Sentinel-Team (global): ' . $pools->globalTeamId());
        $this->line('Hybrid-Recall aktivieren mit: FOODALCHEMIST_SEMANTIC_SEARCH=true');

        return self::SUCCESS;
    }

    /**
     * Liest und prüft die --pool=la-Optionen. null = Fehler (schon ausgegeben).
     *
     * @return array{gezielt:bool, missing:bool, ids:list<int>, limit:?int, batch:int, sleep:float, until:?\Carbon\Carbon, dry_run:bool}|null
     */
    private function laOptionen(): ?array
    {
        $ids = [];
        if (($raw = $this->option('ids')) !== null && $raw !== '') {
            foreach (explode(',', (string) $raw) as $x) {
                if (! ctype_digit(trim($x))) {
                    $this->error("--ids: '{$x}' ist keine Artikel-ID.");

                    return null;
                }
                $ids[] = (int) trim($x);
            }
        }
        foreach (['limit' => 1, 'batch' => 1] as $name => $min) {
            $v = $this->option($name);
            if ($v !== null && $v !== '' && (! ctype_digit((string) $v) || (int) $v < $min)) {
                $this->error("--{$name} muss eine ganze Zahl >= {$min} sein.");

                return null;
            }
        }
        $sleep = $this->option('sleep');
        if ($sleep !== null && $sleep !== '' && (! is_numeric($sleep) || (float) $sleep < 0)) {
            $this->error('--sleep muss eine Zahl >= 0 sein (Sekunden).');

            return null;
        }
        $until = null;
        if (($u = $this->option('until')) !== null && $u !== '') {
            if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', (string) $u, $m)) {
                $this->error('--until erwartet HH:MM (z. B. 05:30).');

                return null;
            }
            $until = now()->setTime((int) $m[1], (int) $m[2], 0);
            if ($until->lessThanOrEqualTo(now())) {
                $until = $until->addDay();   // Fenster über Mitternacht: „bis 05:30" meint den nächsten Morgen
            }
        }
        $limit = $this->option('limit');
        $gezielt = (bool) $this->option('missing') || $ids !== [] || ($limit !== null && $limit !== '')
            || ($sleep !== null && $sleep !== '' && (float) $sleep > 0) || $until !== null || (bool) $this->option('dry-run');

        return [
            'gezielt' => $gezielt,
            'missing' => (bool) $this->option('missing'),
            'ids' => $ids,
            'limit' => ($limit !== null && $limit !== '') ? (int) $limit : null,
            'batch' => (int) ($this->option('batch') ?: 500),
            'sleep' => (float) ($sleep ?: 0),
            'until' => $until,
            'dry_run' => (bool) $this->option('dry-run'),
        ];
    }

    /** @return array<string,mixed> */
    private function laLauf(array $o, ?int $team): array
    {
        if ($o['missing']) {
            $this->line('Nur Artikel ohne Vektor (Bestand je Partition einmal gelesen) …');
        }
        if ($o['until'] !== null) {
            $this->line('Zeitfenster bis '.$o['until']->format('d.m. H:i').' (Serverzeit).');
        }

        return app(SupplierItemEmbedBackfill::class)->run([
            'missing' => $o['missing'], 'ids' => $o['ids'], 'limit' => $o['limit'], 'batch' => $o['batch'],
            'sleep' => $o['sleep'], 'until' => $o['until'], 'team' => $team, 'dry_run' => $o['dry_run'],
        ]);
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
