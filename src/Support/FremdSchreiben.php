<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Spec 84 B4 · Schreibschutz im Modell, zweite Linie — in diesem Ausbau NUR Protokoll.
 *
 * `saving`/`deleting` jedes Modells mit BelongsToTeamHierarchy: Gehört die Zeile weder dem betrachtenden Team ({@see FaTeam}) noch darf
 * der Master sie pflegen (globale Basis-Kataloge, globale Stamm-Paket-Zeilen — dieselbe Regel wie `isOwnedBy`), entsteht eine Zeile im
 * Kanal `fa-fremdschreiben`. Es wird NIE geworfen: Schalter `foodalchemist.fremd_schreiben.modus` = aus | log | werfen, „werfen“ verhält
 * sich in diesem Ausbau wie „log“ (Kurator 17.10.: Werfen erst nach einer sauberen Woche io-Log, eigener PR). „Ohne Kontext“
 * (Konsole, Queue ohne Auftrags-Team) wird nie geworfen, auch später nicht — es ist eine eigene Kategorie.
 *
 * - 0 Zusatzabfragen je Speichern: Team aus dem Modell (team_id, bei Änderung auch der alte Wert), Kontext über
 *   `FaTeam::aktuellIdOhneAbfrage()`, Master-ID aus dem Prozess-Cache (nur bei globalen Zeilen gebraucht).
 * - Bewusste Fremdschreiber laufen in {@see erlaubt()} mit grep-barem Grund und erzeugen keine Zeile.
 * - Menge: je (Kategorie, Modell, Eingang, Zeilen-Team, Kontext-Team) eine Zeile pro Prozess; hart höchstens 200 Zeilen je Prozess,
 *   danach eine Schlusszeile „N unterdrückt“. Die Nachtläufe (Necta, Gebühren, Abo-Hülle) erzeugen so höchstens ein paar Zeilen.
 *
 * Grenzen (bewusst, im PR benannt): Massen-update()/delete() auf dem Query-Builder und `DB::table` feuern keine Modell-Ereignisse und
 * sind NICHT erfasst.
 */
final class FremdSchreiben
{
    public const KANAL = 'fa-fremdschreiben';

    public const MAX_ZEILEN = 200;

    /** @var list<string> Gründe der laufenden Ausnahmen (verschachtelt) */
    private static array $erlaubt = [];

    /** @var array<string, true> */
    private static array $gesehen = [];

    private static int $geschrieben = 0;

    private static int $unterdrueckt = 0;

    private static ?string $basisEingang = null;

    private static ?string $job = null;

    public static function registrieren(\Illuminate\Contracts\Foundation\Application $app): void
    {
        if (config('logging.channels.' . self::KANAL) === null) {
            config(['logging.channels.' . self::KANAL => [
                'driver' => 'daily',
                'path' => storage_path('logs/fa-fremdschreiben.log'),
                'days' => 14,
                'level' => 'debug',
                'formatter' => \Monolog\Formatter\JsonFormatter::class,
            ]]);
        }
        $events = $app['events'];
        $events->listen(\Illuminate\Console\Events\CommandStarting::class, function ($e) {
            self::$basisEingang = 'artisan:' . ($e->command ?? '?');
        });
        $events->listen(\Illuminate\Queue\Events\JobProcessing::class, function ($e) {
            self::$job = 'job:' . LangsameAbfragen::jobName($e->job ?? null);
        });
        $events->listen([\Illuminate\Queue\Events\JobProcessed::class, \Illuminate\Queue\Events\JobFailed::class], function () {
            self::$job = null;
            self::schlusszeile();
        });
        $app->terminating(fn () => self::schlusszeile());
    }

    public static function modus(): string
    {
        $m = (string) config('foodalchemist.fremd_schreiben.modus', 'aus');

        return in_array($m, ['log', 'werfen'], true) ? $m : 'aus';
    }

    /**
     * Bewusst fremd schreiben (Abo-Pflege, Recompute, Signale an andere Teams …). Der Grund steht im Aufruf und ist grep-bar.
     *
     * @template T
     * @param  callable(): T  $fn
     * @return T
     */
    public static function erlaubt(string $grund, callable $fn): mixed
    {
        self::$erlaubt[] = $grund;
        try {
            return $fn();
        } finally {
            array_pop(self::$erlaubt);
        }
    }

    /** Hook aus BelongsToTeamHierarchy (`saving`, `deleting`). Wirft nie. */
    public static function pruefe(Model $model, string $vorgang): void
    {
        if (self::$erlaubt !== [] || self::modus() === 'aus') {
            return;
        }
        try {
            $zeile = $model->getAttribute('team_id');
            $vorher = $model->exists ? $model->getOriginal('team_id') : $zeile;
            $kontext = FaTeam::aktuellIdOhneAbfrage();
            if ($kontext === null) {
                self::melde('ohne_kontext', $model, $vorgang, $vorher, null);

                return;
            }
            foreach (array_unique([$zeile, $vorher], SORT_REGULAR) as $t) {
                if (! self::darf($model, $t, $kontext)) {
                    self::melde('fremd', $model, $vorgang, $t, $kontext);

                    return;
                }
            }
        } catch (\Throwable $e) {
            unset($e);   // bewusst still: die zweite Linie darf das Speichern nie stören
        }
    }

    /** Dieselbe Regel wie `isOwnedBy`, ohne Team-Modell und ohne Abfrage. */
    private static function darf(Model $model, mixed $zeilenTeam, int $kontext): bool
    {
        if ($zeilenTeam !== null) {
            return (int) $zeilenTeam === $kontext;
        }
        if (MasterTeam::id() !== $kontext) {
            return false;
        }
        if ($model::istBasisKatalog()) {
            return true;
        }

        return defined($model::class . '::STAMM_KLASSE') && ($model->getAttribute('stamm_class') ?? StammPaket::BASIS) === StammPaket::PAKET;
    }

    private static function melde(string $art, Model $model, string $vorgang, mixed $zeilenTeam, ?int $kontext): void
    {
        $eingang = self::eingang();
        $schluessel = implode('|', [$art, $model::class, $eingang, (string) $zeilenTeam, (string) $kontext]);
        if (isset(self::$gesehen[$schluessel])) {
            return;
        }
        self::$gesehen[$schluessel] = true;
        if (self::$geschrieben >= self::MAX_ZEILEN) {
            self::$unterdrueckt++;

            return;
        }
        self::$geschrieben++;
        Log::channel(self::KANAL)->info('Spec 84 B4: Schreiben ' . ($art === 'fremd' ? 'auf fremde Zeile' : 'ohne Team-Kontext'), [
            'art' => $art, 'modus' => self::modus(), 'modell' => class_basename($model), 'id' => $model->getKey(), 'vorgang' => $vorgang,
            'zeilen_team' => $zeilenTeam !== null ? (int) $zeilenTeam : null, 'kontext_team' => $kontext, 'eingang' => $eingang,
            'aufrufer' => self::aufrufer(),
        ]);
    }

    /** Eine Schlusszeile je Prozess/Job, falls etwas unterdrückt wurde; danach Zähler zurück. */
    public static function schlusszeile(): void
    {
        if (self::$unterdrueckt > 0) {
            Log::channel(self::KANAL)->info('Spec 84 B4: weitere Fremdschreib-Fälle unterdrückt', ['art' => 'unterdrueckt', 'anzahl' => self::$unterdrueckt,
                'eingang' => self::eingang()]);
        }
        self::$gesehen = [];
        self::$geschrieben = 0;
        self::$unterdrueckt = 0;
    }

    private static function eingang(): string
    {
        if (self::$job !== null) {
            return self::$job;
        }
        if (($e = \Platform\FoodAlchemist\Models\Scopes\TeamSicht::eingang()) !== null) {
            return $e;
        }
        if (app()->runningInConsole()) {
            return self::$basisEingang ?? 'konsole';
        }

        return 'http:' . (request()->route()?->getName() ?? request()->path());
    }

    /** Erster Frame aus dem Modul-`src/` außerhalb dieser Klasse und des Traits. */
    private static function aufrufer(): ?string
    {
        $src = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $f) {
            $datei = $f['file'] ?? '';
            if ($datei !== '' && str_starts_with($datei, $src) && $datei !== __FILE__ && ! str_ends_with($datei, 'BelongsToTeamHierarchy.php')) {
                return substr($datei, strlen($src)) . ':' . ($f['line'] ?? 0);
            }
        }

        return null;
    }

    /** Prozess-Merker leeren (Tests). */
    public static function zuruecksetzen(): void
    {
        self::$erlaubt = [];
        self::$gesehen = [];
        self::$geschrieben = 0;
        self::$unterdrueckt = 0;
        self::$basisEingang = null;
        self::$job = null;
    }
}
