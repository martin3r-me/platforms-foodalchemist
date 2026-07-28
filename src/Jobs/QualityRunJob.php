<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Services\SignalDetektorService;

/**
 * „Ampel neu messen" asynchron — der Detektor-Lauf eines Teams.
 *
 * ASYNC ist hier kein Komfort, sondern die Voraussetzung dafür, dass der Knopf überhaupt
 * funktioniert: `SignalDetektorService::laufen()` fährt 11 Detektoren, die Voll-Messung
 * der Kaskade (LA→GP→Basisrezept→Gericht), den Zeitreihen-Snapshot und den Drift-Vergleich.
 * Auf demo sind das 7.942 Artikel und 2.297 Rezepte in einem Durchgang. Vorher hing das im
 * Livewire-Request und wäre ins Timeout gelaufen — dieselbe Lehre, die {@see SignalFixJob}
 * schon gezogen hat.
 *
 * Sync-Queue-Driver (Sandbox/Tests) ⇒ läuft inline; das Verhalten bleibt identisch.
 *
 * Idempotent: der Detektor dedupliziert über `dedup_key` und schließt aufgelöste Befunde
 * selbst (`emittiereUndSchliesse`). Ein zweiter Lauf erzeugt keine Dubletten — er kostet
 * nur Zeit. Gegen den Doppelklick sperrt {@see \Platform\FoodAlchemist\Services\QualityRunService}.
 */
class QualityRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Die Voll-Messung ist die teuerste lesende Operation des Moduls. 1800 s liegt bewusst
     * zwischen `SignalFixJob` (600 s) und den Anreicherungs-Läufen (3600 s): mehr als ein
     * Massen-Fix, weniger als ein Lauf, der pro Objekt das Modell ruft.
     */
    public int $timeout = 1800;

    /**
     * Kein Retry. Ein zweiter Versuch würde einen zweiten Snapshot in dieselbe Zeitreihe
     * schreiben und den Drift-Vergleich (E3) gegen einen halben Lauf halten. Lieber ein
     * sichtbar gescheiterter Lauf mit Grund als eine stille Doppelmessung.
     */
    public int $tries = 1;

    public function __construct(
        public int $runId,
        public int $teamId,
    ) {
    }

    public function handle(SignalDetektorService $detektor): void
    {
        $team = Team::find($this->teamId);
        if ($team === null) {
            $this->scheitern('Team ' . $this->teamId . ' existiert nicht mehr.');

            return;
        }

        $n = $detektor->laufen($team);

        FoodAlchemistBulkRun::whereKey($this->runId)->update([
            'status' => BulkRunStatus::Done->value,
            'done' => 1,
            'updated_at' => now(),
        ]);

        // Die Zahl gehört an den Lauf, nicht nur ins Log: „was hat der Lauf von heute Nacht
        // eigentlich gefunden?" soll `runs.GET` beantworten und nicht der Server-Log.
        $this->kontextErgaenzen(['signale' => $n]);
    }

    /**
     * Der Fehlschlag darf den Lauf nicht auf „läuft gerade" stehen lassen — genau dafür
     * hat `FoodAlchemistBulkRun` den `failed`-Zweig (22·H3b · V-054). `failed()` greift
     * auch, wenn der Worker den Job abschießt (Timeout), nicht nur bei Exceptions im Body.
     */
    public function failed(?\Throwable $e): void
    {
        // Den Throwable durchgeben, nicht die Nachricht: `markiereGescheitert` schreibt
        // daraus zusätzlich `fehler_klasse` — bei einem Timeout ist genau die Klasse die
        // Information, nicht der (oft leere) Text.
        $this->scheitern($e ?? 'Unbekannter Fehler im Qualitäts-Lauf.');
    }

    private function scheitern(\Throwable|string $grund): void
    {
        Log::warning('[foodalchemist] Qualitäts-Lauf gescheitert', [
            'run_id' => $this->runId,
            'team_id' => $this->teamId,
            'grund' => $grund instanceof \Throwable ? $grund->getMessage() : $grund,
        ]);

        // `markiereGescheitert` ist statisch und schützt selbst gegen das Rückdatieren
        // eines schon abgeschlossenen Laufs (V-054) — hier kein zweiter Statuscheck.
        FoodAlchemistBulkRun::markiereGescheitert($this->runId, $grund);
    }

    /** @param array<string,mixed> $mehr */
    private function kontextErgaenzen(array $mehr): void
    {
        $run = FoodAlchemistBulkRun::find($this->runId);
        if ($run === null) {
            return;
        }
        $run->update(['context' => array_merge($run->context ?? [], $mehr)]);
    }
}
