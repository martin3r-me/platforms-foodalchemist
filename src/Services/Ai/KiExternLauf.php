<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Exceptions\KiExternSchrankeException;

/**
 * #1002 (P2a): Job-Rumpf im externen Modus (Sammel-Transaktion). Dieselbe Mechanik wie die Tool-Hülle (Support/KiUmleitung), nur
 * für Jobs mit Wiederholungs-Nutzlast: wartet der Rumpf auf eine Antwort, Rollback, Aufträge danach anlegen, `ki_auftraege.ANTWORT`
 * startet den Job neu. Antworten werden erst mit einem vollständigen Lauf verbraucht (der Verbrauchs-UPDATE steht in der Transaktion).
 */
class KiExternLauf
{
    /** @var list<string>|null laufende Wache auf Nicht-DB-Nebenwirkungen (nur während eines externen Rumpfs gesetzt) */
    private static ?array $wache = null;

    /** Objekt-ID des Event-Dispatchers, an dem die Wache hängt (neue App-Instanz = neu registrieren, z. B. in Tests/Octane) */
    private static ?int $wacheFuer = null;

    /** Harmloses Cache-Rauschen der Plattform (Worker-Heartbeat, Wechselkurs) — kein Effekt, den ein Rollback bereuen müsste */
    private const UNKRITISCHE_CACHE_KEYS = ['fa:worker:heartbeat', 'foodalchemist.wechselkurs.'];

    /** @var list<string> Nebenwirkungen des letzten externen Rumpfs (Queue-Dispatch, Cache-Schreiben, Mail) — für Tests und Log */
    public static array $nebenwirkungen = [];

    /** Einmalig: Ereignisse, die ein Rollback nicht zurücknimmt. Nur aktiv, solange {@see $wache} gesetzt ist. */
    /** Wache starten (Tool-Hülle und Job-Lauf teilen sie): ab hier werden Nicht-DB-Nebenwirkungen gesammelt. */
    public static function wacheStart(): void
    {
        self::registriereWache();
        self::$wache = [];
    }

    /**
     * Wache beenden: liefert die Nebenwirkungen, hält sie in {@see $nebenwirkungen} fest und schreibt bei Funden einen Log-Eintrag.
     *
     * @return list<string>
     */
    public static function wacheEnde(string $key): array
    {
        self::$nebenwirkungen = self::$wache ?? [];
        self::$wache = null;
        if (self::$nebenwirkungen !== []) {
            // Ein Rollback nimmt das nicht zurück: der Rumpf gehört auf DB::afterCommit / ->afterCommit() (Pflichtliste Tabelle D)
            \Illuminate\Support\Facades\Log::error("[KI-extern] Nicht-DB-Nebenwirkung im externen Lauf [{$key}] vor dem Commit", ['nebenwirkungen' => self::$nebenwirkungen]);
        }

        return self::$nebenwirkungen;
    }

    private static function registriereWache(): void
    {
        $dispatcher = app('events');
        if (self::$wacheFuer === spl_object_id($dispatcher)) {
            return;
        }
        self::$wacheFuer = spl_object_id($dispatcher);
        $melde = function (string $was) {
            if (self::$wache !== null) {
                self::$wache[] = $was;
            }
        };
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobQueued::class, fn ($e) => $melde('Queue-Dispatch '.($e->job instanceof \Closure ? 'Closure' : (is_object($e->job) ? $e->job::class : (string) $e->job))));
        // Sync-Queue feuert kein JobQueued, der Job läuft sofort inline → JobProcessing (bei echten Queues passiert das im Worker, nicht hier)
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobProcessing::class, fn ($e) => $melde('Queue-Dispatch (sync) '.$e->job->resolveName()));
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Cache\Events\KeyWritten::class, function ($e) use ($melde) {
            foreach (self::UNKRITISCHE_CACHE_KEYS as $praefix) {
                if (str_starts_with((string) $e->key, $praefix)) {
                    return;
                }
            }
            $melde('Cache::put '.$e->key);
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, fn () => $melde('Mail'));
    }

    public const FERTIG = 'fertig';

    public const WARTET = 'wartet';

    public const LAEUFT_BEREITS = 'laeuft_bereits';

    /**
     * @param  callable(): mixed  $arbeit  der Job-Rumpf
     * @return string FERTIG | WARTET | LAEUFT_BEREITS
     */
    public function fuehreAus(KiExternWiederholbar $job, callable $arbeit): string
    {
        if (! $job->externAktiv()) {
            $arbeit();                                              // Plattformweg: bytegleich, keine Transaktion, kein Sammel-Modus

            return self::FERTIG;
        }
        $teamId = $job->externTeamId();
        $key = $job->externSchluessel();
        // Ein laufender Lauf je Ziel: ein neuer Start (Versuch 0) findet einen wartenden/angestoßenen Lauf vor → kein zweiter
        if ($job->externVersuch() === 0 && app(KiAuftragService::class)->laufAktiv($teamId, $key)) {
            return self::LAEUFT_BEREITS;
        }

        $modus = app(KiExternModus::class);
        $db = DB::connection();
        $niveau = $db->transactionLevel();
        self::wacheStart();
        $db->beginTransaction();
        $fehler = null;
        try {
            $modus->withinSammelnd($arbeit, [
                'klasse' => $job::class, 'nutzlast' => $job->externNutzlast(), 'versuch' => $job->externVersuch(), 'lauf_key' => $key,
            ]);
        } catch (\Throwable $e) {
            $fehler = $e;
        }
        self::wacheEnde($key);
        $merker = $modus->entnimm();

        if ($merker['wartet'] === []) {
            if ($fehler !== null) {
                $db->rollBack($niveau);

                throw $fehler;
            }
            $db->commit();

            return self::FERTIG;
        }

        $db->rollBack($niveau);
        $max = (int) config('foodalchemist.ai.extern_max_wiederholungen', 30);
        if ($job->externVersuch() >= $max) {
            throw new KiExternSchrankeException("Externer Lauf [{$key}] hat die Wiederholungs-Schranke ({$max}) erreicht — der Fingerabdruck der KI-Aufträge ist instabil "
                .'(ki_auftraege-Zähler „doppelt“ prüfen) oder der Lauf hat mehr KI-Glieder als erwartet.');
        }
        app(KiAuftragService::class)->legeAn($merker['vorgemerkt']);

        return self::WARTET;
    }
}
