<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Roadmap Planung-Leitstelle · Etappe 8 (Robustheit) »Worker-Präsenz« — Teil 1 (Heartbeat + Status).
 *
 * Die Leitstelle dispatcht ausnahmslos Queue-Jobs (LLM raus aus dem Web-Request); ohne einen
 * laufenden `queue:work`-Prozess produziert sie NICHTS — der Nutzer sieht nur einen Spinner.
 * Bisher fängt das nur der REAKTIVE Per-Lauf-Watchdog ({@see \Platform\FoodAlchemist\Livewire\Planung\Index}:
 * `pruefeLauf`), und der schlägt erst NACH ~90 s eines hängenden Laufs an. Dieser Service liefert das
 * PROAKTIVE Gegenstück: ein Herzschlag, den jeder lebende Worker beim Queue-Looping/Job-Verarbeiten
 * stempelt, plus eine Ampel, die die UI VOR dem Go zeigen kann.
 *
 * **Signal-Quelle (real, kein Rate):** Laravel feuert `Illuminate\Queue\Events\Looping` bei JEDER
 * Worker-Schleifen-Iteration — auch im Leerlauf (der Worker schläft `--sleep` Sekunden und loopt weiter).
 * Ein lebender Worker stempelt also weiter, selbst ohne Arbeit; ein fehlender Worker stempelt nie mehr.
 * Zusätzlich stempelt `JobProcessing` (Job-Start), damit auch busy-Phasen frisch bleiben.
 *
 * **Blinder Fleck (bewusst, dokumentiert):** ein EINZELNER, sehr langer Job (z. B. ein 60-s-LLM-Entwurf)
 * feuert dazwischen kein `Looping` → der Herzschlag kann während dieses einen Jobs altern. Genau diesen
 * Fall deckt der reaktive Per-Lauf-Watchdog (90 s) ab; die beiden ergänzen sich. {@see STILL_SEKUNDEN}
 * ist deshalb großzügig gewählt (60 s), damit ein normal langer Einzel-Job die Ampel nicht fälschlich
 * auf »still« kippt.
 */
class WorkerHealthService
{
    /** Cache-Key des Worker-Herzschlags (Unix-Sekunden des letzten Stempels). */
    public const HEARTBEAT_KEY = 'fa:worker:heartbeat';

    /** Throttle-Key: begrenzt die Stempel-Schreiblast auf höchstens 1× je {@see THROTTLE_SEKUNDEN}. */
    public const THROTTLE_KEY = 'fa:worker:heartbeat:throttle';

    /**
     * Höchstens alle N Sekunden schreiben. `Looping` feuert im Leerlauf sekündlich und unter Last
     * vielfach — ungedrosselt wäre das ein Cache-Schreibsturm. 10 s hält die Last winzig und liegt
     * weit unter {@see STILL_SEKUNDEN}.
     */
    public const THROTTLE_SEKUNDEN = 10;

    /**
     * Ab diesem Alter des letzten Herzschlags gilt der Worker als »still« (kein lebender `queue:work`
     * mehr wahrscheinlich). Großzügig über THROTTLE + der realistischen Dauer eines langen Einzel-Jobs,
     * damit ein in-flight-Job die Ampel nicht fälschlich kippt; den eng getakteten Hänger-Fall deckt der
     * reaktive Per-Lauf-Watchdog (90 s) ab.
     */
    public const STILL_SEKUNDEN = 60;

    /** So lange bleibt der letzte Stempel lesbar — ein toter Worker liest dann als »still« (altes Datum), nicht als »unbekannt« (nie gesehen). */
    public const HEARTBEAT_TTL_SEKUNDEN = 3600;

    /**
     * Stempelt den Herzschlag — vom Queue-Event-Listener bei `Looping`/`JobProcessing` gerufen.
     * Gedrosselt über einen atomaren `Cache::add`-Riegel: nur der erste Aufruf je {@see THROTTLE_SEKUNDEN}
     * schreibt tatsächlich, alle weiteren no-oppen. Fail-soft: ein Cache-Fehler darf den Worker nie kippen.
     */
    public function heartbeat(): void
    {
        try {
            // add() ist atomar true nur für den ERSTEN Aufruf im Fenster → dedupt den Schreib-Sturm.
            if (! Cache::add(self::THROTTLE_KEY, 1, self::THROTTLE_SEKUNDEN)) {
                return;
            }
            Cache::put(self::HEARTBEAT_KEY, now()->timestamp, self::HEARTBEAT_TTL_SEKUNDEN);
        } catch (\Throwable) {
            // Der Herzschlag ist Diagnose, kein Arbeitsschritt — ein Cache-Ausfall darf keinen Job kippen.
        }
    }

    /**
     * Ampel für die UI. Rein lesend, fail-soft.
     *
     * @return array{state: 'gesund'|'still'|'unbekannt', zuletzt: ?Carbon, alter_sek: ?int}
     *               - `unbekannt`: nie ein Herzschlag gesehen (Worker lief noch nie / Cache leer)
     *               - `gesund`:    letzter Herzschlag jünger als {@see STILL_SEKUNDEN}
     *               - `still`:     letzter Herzschlag zu alt → vermutlich kein Worker aktiv
     */
    public function status(): array
    {
        try {
            $ts = Cache::get(self::HEARTBEAT_KEY);
        } catch (\Throwable) {
            $ts = null;
        }
        if (! is_int($ts) && ! (is_string($ts) && ctype_digit($ts))) {
            return ['state' => 'unbekannt', 'zuletzt' => null, 'alter_sek' => null];
        }
        $ts = (int) $ts;
        $alter = max(0, now()->timestamp - $ts);

        return [
            'state' => $alter <= self::STILL_SEKUNDEN ? 'gesund' : 'still',
            'zuletzt' => Carbon::createFromTimestamp($ts),
            'alter_sek' => $alter,
        ];
    }

    /** Bequemer Bool für Aufrufer, die nur „arbeitet gerade ein Worker?" wissen wollen. */
    public function istGesund(): bool
    {
        return $this->status()['state'] === 'gesund';
    }
}
