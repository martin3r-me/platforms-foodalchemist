<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

/**
 * Voll-Kopie des FA-Masters (database.sqlite, englisches Schema) in die Ziel-DB
 * (auf demo: MySQL). Anders als foodalchemist:import-slice ist das KEIN
 * WaWi→FA-Transform aus der eingefrorenen wawi_1494.sqlite, sondern ein
 * GLEICHSCHEMA-Copy: Quelle und Ziel tragen dasselbe `foodalchemist_*`-Schema
 * (beide aus denselben 94 Migrationen), daher Tabelle→Tabelle ohne Spalten-Mapping.
 *
 * Architektur:
 * - Quelle strikt read-only (PDO sqlite, PRAGMA query_only).
 * - Spalten dynamisch = Schnittmenge(Quelle, Ziel) → drift-resilient (neue Ziel-
 *   Spalten bleiben Default, in der Quelle entfernte werden ignoriert).
 * - PKs werden VERBATIM übernommen → alle FKs bleiben intern konsistent.
 *   Voraussetzung: Ziel-Tabellen sind leer (--fresh oder frisch migriert).
 * - team_id: team-eigene Zeilen werden auf --team umgeschrieben; globale Seed-Zeilen
 *   (team_id NULL, D1 — z. B. Pairing-Graph, globale Knowledge-Docs, GP-Seeds) BLEIBEN
 *   global. Tabellen ohne team_id-Spalte werden verbatim kopiert.
 * - FK-Checks während des Loads aus (MySQL: FOREIGN_KEY_CHECKS, SQLite: PRAGMA).
 * - Streaming-Read + Chunk-Insert → 264k-Artikel-Tabelle sprengt den Speicher nicht.
 * - Row-Count-Gate am Ende: Quelle == Ziel je Tabelle, sonst ❌ BLOCKER.
 */
class ImportMasterCommand extends Command
{
    protected $signature = 'foodalchemist:import-master
        {--source= : Pfad zur Quell-SQLite (FA-Master database.sqlite)}
        {--team= : Ziel-Team-ID auf dieser Umgebung (Katalog-Besitzer, z. B. das demo-Team)}
        {--fresh : Alle foodalchemist_*-Ziel-Tabellen vorher leeren (nötig bei Re-Import)}
        {--only= : Nur diese Tabellen kopieren (Komma-getrennt, ohne Prefix) — für Tests}
        {--dry-run : Nur zählen und berichten, nichts schreiben}';

    protected $description = 'Kopiert den kompletten FA-Master (database.sqlite) 1:1 ins Ziel-Schema (MySQL auf demo).';

    private const CHUNK = 1000;

    /** Ziel-Team: alle team-skopierten Zeilen bekommen diese ID. */
    private int $teamId;

    /** Treiber der Ziel-Connection ('mysql' | 'sqlite' | …). */
    private string $driver;

    public function handle(): int
    {
        // Die id→id-Maps entfallen (PKs verbatim), aber der Stream über 264k Zeilen
        // + Chunk-Puffer brauchen etwas Headroom.
        ini_set('memory_limit', '1024M');

        $source = (string) $this->option('source');
        if ($source === '' || ! is_readable($source)) {
            $this->error('Quell-SQLite nicht lesbar. --source=/pfad/zu/database.sqlite angeben.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->driver = DB::connection()->getDriverName();

        $teamOption = $this->option('team');
        if ($teamOption === null || ! ctype_digit((string) $teamOption)) {
            $this->error('Ziel-Team fehlt. --team=<id> angeben (Katalog-Besitzer auf dieser Umgebung).');

            return self::FAILURE;
        }
        $this->teamId = (int) $teamOption;
        if (! DB::table('teams')->where('id', $this->teamId)->exists()) {
            $this->error("Team {$this->teamId} existiert in der Ziel-DB nicht.");

            return self::FAILURE;
        }

        $pdo = new PDO('sqlite:'.$source, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA query_only = ON;'); // Quelle strikt read-only

        // Alle foodalchemist_*-Tabellen aus der Quelle in stabiler Reihenfolge.
        $tables = $this->sourceTables($pdo);
        if (($only = trim((string) $this->option('only'))) !== '') {
            $wanted = array_map(fn ($t) => str_starts_with($t, 'foodalchemist_') ? $t : 'foodalchemist_'.$t,
                array_map('trim', explode(',', $only)));
            $tables = array_values(array_intersect($tables, $wanted));
        }

        if ($tables === []) {
            $this->error('Keine foodalchemist_*-Tabellen in der Quelle gefunden.');

            return self::FAILURE;
        }

        $this->info(sprintf('Ziel-Connection: %s · Team %d · %d Tabellen%s',
            $this->driver, $this->teamId, count($tables), $dryRun ? ' · DRY-RUN' : ''));

        if ($this->option('fresh') && ! $dryRun) {
            $this->freshen($tables);
        } elseif (! $dryRun) {
            // Verbatim-PK-Copy verlangt leere Ziel-Tabellen — sonst PK-Kollisionen.
            $nonEmpty = collect($tables)->filter(fn ($t) => DB::table($t)->exists())->values();
            if ($nonEmpty->isNotEmpty()) {
                $this->error('Ziel-Tabellen nicht leer (z. B. '.$nonEmpty->take(3)->implode(', ')
                    .'). Mit --fresh leeren oder frisch migrieren.');

                return self::FAILURE;
            }
        }

        $stats = [];
        $this->withoutForeignKeyChecks(function () use ($pdo, $tables, $dryRun, &$stats) {
            foreach ($tables as $table) {
                $stats[$table] = $this->copyTable($pdo, $table, $dryRun);
            }
        }, $dryRun);

        $this->newLine();
        $this->table(
            ['Tabelle', 'Quelle', 'kopiert', 'übersprungen'],
            collect($stats)->map(fn ($s, $t) => [
                str_replace('foodalchemist_', '', $t), $s['source'], $s['imported'], $s['skipped'],
            ])->all()
        );

        // Row-Count-Gate: Quelle == Ziel — nur für Tabellen, die es im Ziel-Schema gibt.
        // Nicht-Migrations-Tabellen (Scratch: fb2027_*, legacy_id_map, altnamen_fix, bulk_*)
        // existieren auf demo bewusst nicht und werden nicht gegatet, sondern als übersprungen gemeldet.
        if (! $dryRun) {
            $this->newLine();
            $blocker = 0;
            $skippedTables = [];
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    $skippedTables[] = str_replace('foodalchemist_', '', $table);

                    continue;
                }
                $src = $stats[$table]['source'];
                $dst = DB::table($table)->count();
                $ok = $src === $dst;
                if (! $ok) {
                    $blocker++;
                }
                $this->line(($ok ? '✅' : '❌ BLOCKER').' '.str_replace('foodalchemist_', '', $table)
                    .": Quelle {$src} ↔ Ziel {$dst}");
            }
            if ($skippedTables !== []) {
                $this->newLine();
                $this->warn(count($skippedTables).' Tabelle(n) nicht im Ziel-Schema (übersprungen, kein Gate): '
                    .implode(', ', $skippedTables));
            }
            if ($blocker > 0) {
                $this->error("{$blocker} Tabelle(n) mit Row-Count-Abweichung — Import unvollständig.");

                return self::FAILURE;
            }
            $this->info('Alle vorhandenen Ziel-Tabellen: Row-Counts stimmen. Master vollständig kopiert.');
        }

        return self::SUCCESS;
    }

    /** foodalchemist_*-Tabellen der Quelle (alphabetisch; FK-Reihenfolge egal, da FK-Checks aus). */
    private function sourceTables(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'
            AND name LIKE 'foodalchemist_%' ORDER BY name");

        return array_map(fn ($r) => $r['name'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * D1-Team-Rewrite beim Import: globale Seed-Zeile (Quelle team_id NULL) bleibt global;
     * team-eigene Zeile bekommt das Ziel-Team. So überlebt der Global-Marker den Deploy.
     */
    public static function rewriteTeamId(mixed $srcTeamId, int $targetTeamId): ?int
    {
        return $srcTeamId === null ? null : $targetTeamId;
    }

    /** Eine Tabelle streamend kopieren: Schnittmengen-Spalten, team_id-Rewrite, Chunk-Insert. */
    private function copyTable(PDO $pdo, string $table, bool $dryRun): array
    {
        $srcCount = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();

        // Ziel muss die Tabelle kennen (aus den Migrationen). Sonst hart überspringen.
        if (! Schema::hasTable($table)) {
            $this->warn("· {$table}: existiert im Ziel nicht (Migration fehlt?) — übersprungen");

            return ['source' => $srcCount, 'imported' => 0, 'skipped' => $srcCount];
        }

        $srcCols = $this->sourceColumns($pdo, $table);
        $dstCols = Schema::getColumnListing($table);
        $shared = array_values(array_intersect($srcCols, $dstCols));
        $hasTeam = in_array('team_id', $dstCols, true) && in_array('team_id', $srcCols, true);

        if ($shared === []) {
            $this->warn("· {$table}: keine gemeinsamen Spalten — übersprungen");

            return ['source' => $srcCount, 'imported' => 0, 'skipped' => $srcCount];
        }

        if ($dryRun) {
            return ['source' => $srcCount, 'imported' => 0, 'skipped' => 0];
        }

        // Platzhalter-Limit je Treiber (SQLite 32.766, MySQL 65.535) → Chunk-Zeilen
        // spaltenabhängig deckeln, sonst „too many SQL variables" bei breiten Tabellen.
        $chunkRows = max(1, min(self::CHUNK, intdiv($this->bindCap(), max(1, count($shared)))));

        $colList = implode(', ', array_map(fn ($c) => '"'.$c.'"', $shared));
        $stmt = $pdo->query("SELECT {$colList} FROM {$table}");

        $bar = $srcCount > $chunkRows ? $this->output->createProgressBar($srcCount) : null;
        $bar?->setFormat(" {$table}: %current%/%max%");

        $chunk = [];
        $imported = 0;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($hasTeam) {
                $row['team_id'] = self::rewriteTeamId($row['team_id'], $this->teamId);
            }
            $chunk[] = $row;
            if (count($chunk) >= $chunkRows) {
                DB::table($table)->insert($chunk);
                $imported += count($chunk);
                $bar?->advance(count($chunk));
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            DB::table($table)->insert($chunk);
            $imported += count($chunk);
            $bar?->advance(count($chunk));
        }
        $bar?->finish();
        if ($bar) {
            $this->newLine();
        }

        return ['source' => $srcCount, 'imported' => $imported, 'skipped' => $srcCount - $imported];
    }

    /** Sichere Platzhalter-Obergrenze pro Insert je Ziel-Treiber (konservativ unter dem Hard-Limit). */
    private function bindCap(): int
    {
        return match ($this->driver) {
            'mysql' => 60000,   // Hard-Limit 65.535
            'sqlite' => 30000,  // Hard-Limit 32.766
            default => 20000,   // z. B. pgsql: reichlich Reserve
        };
    }

    /** Spaltennamen einer Quell-Tabelle via PRAGMA. */
    private function sourceColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("PRAGMA table_info({$table})");

        return array_map(fn ($r) => $r['name'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Ziel-Tabellen leeren (FK-Checks sind im umgebenden Callback bereits aus). */
    private function freshen(array $tables): void
    {
        $this->withoutForeignKeyChecks(function () use ($tables) {
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }
        }, false);
        $this->line('· '.count($tables).' Ziel-Tabellen geleert.');
    }

    /** FK-Checks für die Dauer des Callbacks abschalten (treiber-spezifisch). */
    private function withoutForeignKeyChecks(callable $fn, bool $dryRun): void
    {
        if ($dryRun) {
            $fn();

            return;
        }

        if ($this->driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                $fn();
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }

            return;
        }

        if ($this->driver === 'sqlite') {
            // PRAGMA muss außerhalb einer Transaktion stehen.
            DB::statement('PRAGMA foreign_keys = OFF');
            try {
                $fn();
            } finally {
                DB::statement('PRAGMA foreign_keys = ON');
            }

            return;
        }

        // Unbekannter Treiber (z. B. pgsql): ohne FK-Abschaltung ausführen.
        $fn();
    }
}
