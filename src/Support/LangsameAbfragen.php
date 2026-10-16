<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Spec 84 E-1 · Messung langsamer Abfragen in der Anwendung.
 *
 * Ein `DB::listen` zählt jede Abfrage. Ab `foodalchemist.leistung.langsam_ms` schreibt er eine Zeile in den Kanal
 * `fa-langsam` (Dauer, SQL MIT Platzhaltern, Verbindung, Eingang, Team, Nutzer, Aufrufer aus dem Modul). Am Ende eines
 * Requests/Jobs/Tool-Aufrufs kommt eine Zusammenfassung, wenn er auffällig viele Abfragen oder viel Summe hatte, mit
 * den N+1-Mustern (gleiches SQL ≥ `n_plus_1`-mal).
 *
 * Regeln: nie werfen, nie bremsen. Unter der Schwelle nur Zähler + ein Hash, kein Backtrace. Bindings werden NIE
 * geschrieben (Datenschutz: Namen, Mails, Preise). Ist `langsam_ms` null, kehrt der Listener sofort zurück.
 */
final class LangsameAbfragen
{
    public const KANAL = 'fa-langsam';

    /** Obergrenze verschiedener Muster je Abschnitt (Speicher bleibt klein, auch in langen Läufen). */
    private const MAX_MUSTER = 500;

    private static ?string $basisEingang = null;

    private static ?string $eingang = null;

    private static ?int $teamId = null;

    private static int $anzahl = 0;

    private static float $summeMs = 0.0;

    /** @var array<string, array{sql: string, n: int, ms: float}> */
    private static array $muster = [];

    private static bool $inArbeit = false;

    public static function registrieren(\Illuminate\Contracts\Foundation\Application $app): void
    {
        if (config('logging.channels.' . self::KANAL) === null) {
            config(['logging.channels.' . self::KANAL => [
                'driver' => 'daily',
                'path' => storage_path('logs/fa-langsam.log'),
                'days' => 14,
                'level' => 'debug',
                'formatter' => \Monolog\Formatter\JsonFormatter::class,
            ]]);
        }

        \Illuminate\Support\Facades\DB::listen(fn (QueryExecuted $q) => self::abfrage($q));

        $events = $app['events'];
        $events->listen(\Illuminate\Queue\Events\JobProcessing::class, fn ($e) => self::beginne('job:' . $e->job->resolveName()));
        $events->listen([\Illuminate\Queue\Events\JobProcessed::class, \Illuminate\Queue\Events\JobFailed::class], fn () => self::abschliessen());
        $events->listen(\Illuminate\Console\Events\CommandStarting::class, function ($e) {
            self::$basisEingang = 'artisan:' . ($e->command ?? '?');
        });
        $app->terminating(fn () => self::abschliessen());
    }

    public static function aktiv(): bool
    {
        return config('foodalchemist.leistung.langsam_ms') !== null;
    }

    /** Ein Abschnitt (Job, Tool-Aufruf) beginnt: Zähler auf null, Eingang setzen. */
    public static function beginne(string $eingang, ?int $teamId = null): void
    {
        self::$anzahl = 0;
        self::$summeMs = 0.0;
        self::$muster = [];
        self::$eingang = $eingang;
        self::$teamId = $teamId;
    }

    /**
     * Führt $arbeit als eigenen Abschnitt aus (z. B. ein MCP-Tool) und stellt den äußeren Stand danach wieder her.
     *
     * @template T
     * @param  callable(): T  $arbeit
     * @return T
     */
    public static function imEingang(string $eingang, ?int $teamId, callable $arbeit): mixed
    {
        if (! self::aktiv()) {
            return $arbeit();
        }
        $vorher = [self::$eingang, self::$teamId, self::$anzahl, self::$summeMs, self::$muster];
        self::beginne($eingang, $teamId);
        try {
            return $arbeit();
        } finally {
            self::abschliessen();
            [self::$eingang, self::$teamId, self::$anzahl, self::$summeMs, self::$muster] = $vorher;
        }
    }

    public static function abfrage(QueryExecuted $q): void
    {
        if (self::$inArbeit) {
            return;
        }
        $schwelle = config('foodalchemist.leistung.langsam_ms');
        if ($schwelle === null) {
            return;
        }
        self::$inArbeit = true;
        try {
            $ms = (float) $q->time;
            self::$anzahl++;
            self::$summeMs += $ms;
            $muster = self::muster($q->sql);
            $h = md5($muster);
            if (isset(self::$muster[$h])) {
                self::$muster[$h]['n']++;
                self::$muster[$h]['ms'] += $ms;
            } elseif (count(self::$muster) < self::MAX_MUSTER) {
                self::$muster[$h] = ['sql' => $muster, 'n' => 1, 'ms' => $ms];
            }

            if ($ms >= (float) $schwelle) {
                Log::channel(self::KANAL)->info('langsam', [
                    'art' => 'langsam',
                    'ms' => round($ms, 1),
                    'sql' => $muster,
                    'verbindung' => $q->connectionName,
                    'eingang' => self::eingang(),
                    'team_id' => self::teamId(),
                    'user_id' => self::userId(),
                    'aufrufer' => self::aufrufer(),
                ]);
            }
        } catch (\Throwable $e) {
            // bewusst still: Die Messung darf den Request nie stören (Spec 84 E-1); ein Log-Versuch hier könnte selbst
            // Abfragen auslösen und in diese Methode zurückkehren.
            unset($e);
        } finally {
            self::$inArbeit = false;
        }
    }

    /** Abschnitt zu Ende: Zusammenfassung schreiben, wenn auffällig; Zähler leeren. */
    public static function abschliessen(): void
    {
        if (self::$inArbeit || ! self::aktiv() || self::$anzahl === 0) {
            self::$eingang = null;
            self::$teamId = null;

            return;
        }
        self::$inArbeit = true;
        try {
            $schwelleN = (int) config('foodalchemist.leistung.n_plus_1', 20);
            $nPlus1 = array_values(array_filter(self::$muster, fn ($m) => $m['n'] >= $schwelleN));
            usort($nPlus1, fn ($a, $b) => $b['n'] <=> $a['n']);
            $auffaellig = self::$anzahl > (int) config('foodalchemist.leistung.zusammenfassung_anzahl', 200)
                || self::$summeMs > (float) config('foodalchemist.leistung.zusammenfassung_ms', 2000)
                || $nPlus1 !== [];
            if ($auffaellig) {
                Log::channel(self::KANAL)->info('zusammenfassung', [
                    'art' => 'zusammenfassung',
                    'anzahl' => self::$anzahl,
                    'summe_ms' => round(self::$summeMs, 1),
                    'eingang' => self::eingang(),
                    'team_id' => self::teamId(),
                    'user_id' => self::userId(),
                    'n_plus_1' => array_map(fn ($m) => ['sql' => $m['sql'], 'anzahl' => $m['n'], 'summe_ms' => round($m['ms'], 1)], array_slice($nPlus1, 0, 5)),
                ]);
            }
        } catch (\Throwable $e) {
            unset($e);   // bewusst still, s. abfrage()
        } finally {
            self::$anzahl = 0;
            self::$summeMs = 0.0;
            self::$muster = [];
            self::$eingang = null;
            self::$teamId = null;
            self::$inArbeit = false;
        }
    }

    /** SQL-Muster: Platzhalter bleiben, IN-Listen und Zahl-Literale werden zusammengefasst, Leerraum normalisiert. */
    public static function muster(string $sql): string
    {
        $s = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $s = preg_replace('/\(\s*\?(\s*,\s*\?)+\s*\)/', '(?…)', $s) ?? $s;
        $s = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "'?'", $s) ?? $s;   // Literale nie ins Log
        $s = preg_replace('/(?<![\w"`.])\d+(\.\d+)?\b/', '?', $s) ?? $s;

        return mb_substr($s, 0, 2000);
    }

    private static function eingang(): string
    {
        if (self::$eingang !== null) {
            return self::$eingang;
        }
        if (app()->runningInConsole()) {
            return self::$basisEingang ?? 'konsole';
        }
        $req = request();
        $comps = $req->input('components');
        if (is_array($comps) && isset($comps[0]['snapshot'])) {
            $snap = json_decode((string) $comps[0]['snapshot'], true);
            $name = $snap['memo']['name'] ?? '?';
            $methode = $comps[0]['calls'][0]['method'] ?? 'render';

            return 'livewire:' . $name . '@' . $methode;
        }
        $route = $req->route();
        $name = $route !== null && method_exists($route, 'getName') ? $route->getName() : null;

        return 'http:' . ($name ?: $req->method() . ' /' . ltrim($req->path(), '/'));
    }

    private static function teamId(): ?int
    {
        if (self::$teamId !== null) {
            return self::$teamId;
        }
        $u = Auth::hasUser() ? Auth::user() : null;   // nie selbst eine Nutzer-Abfrage auslösen

        return $u !== null && isset($u->current_team_id) ? (int) $u->current_team_id : null;
    }

    private static function userId(): ?int
    {
        return Auth::hasUser() ? (int) Auth::id() : null;
    }

    /** Erster Frame aus dem Modul-`src/` (ohne diese Klasse): Datei relativ zu src/ + Zeile. */
    private static function aufrufer(): ?string
    {
        $src = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $f) {
            $datei = $f['file'] ?? '';
            if ($datei !== '' && str_starts_with($datei, $src) && $datei !== __FILE__) {
                return substr($datei, strlen($src)) . ':' . ($f['line'] ?? 0);
            }
        }

        return null;
    }
}
