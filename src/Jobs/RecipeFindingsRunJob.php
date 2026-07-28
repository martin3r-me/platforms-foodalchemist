<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Services\RecipeFindingsBatchService;

/**
 * „KI-Befunde sammeln" asynchron — der Copilot-Batch über die fällige Arbeitsmenge.
 *
 * Bis hier war der Batch **nur** über artisan erreichbar, und auf demo hat ihn darum nie
 * jemand gefahren. Sichtbar war das nicht als „ein Command fehlt", sondern als halb leere
 * Oberfläche: ohne abgelegte Befunde gibt es keine `rezept_plausi_ki`-Signale, und der
 * „Lass das so"-Knopf im Copilot-Panel existiert gar nicht (er braucht eine `finding_id`).
 *
 * Dieser Lauf kostet **Provider-Geld pro Rezept** — anders als {@see QualityRunJob}. Darum
 * ist er ein eigener Knopf mit sichtbarem Limit und nicht in die Ampel-Messung gefaltet:
 * wer die Ampel neu messen will, soll nicht ungefragt eine Modell-Rechnung auslösen.
 */
class RecipeFindingsRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Ein Modell-Call je Rezept, gedeckelt auf {@see RecipeFindingsBatchService::MAX_LIMIT}.
     * 3600 s wie die Anreicherungs-Läufe — dieselbe Größenordnung, dieselbe Ursache.
     */
    public int $timeout = 3600;

    /**
     * Kein Retry: ein zweiter Versuch würde die bereits bezahlten Rezepte des ersten
     * erneut prüfen. Die Egress-Bremse wäre damit ausgehebelt, und zwar unsichtbar.
     * Wer nach einem Fehlschlag weitermachen will, startet einen neuen Lauf — die
     * Fälligkeits-Auswahl (`ai_reviewed_at`) überspringt das schon Geprüfte von selbst.
     */
    public int $tries = 1;

    public function __construct(
        public int $runId,
        public int $teamId,
        public int $limit,
        public bool $nurVerkauf,
        public string $pass,
        public ?int $userId = null,
    ) {
    }

    public function handle(RecipeFindingsBatchService $batch): void
    {
        $team = Team::find($this->teamId);
        if ($team === null) {
            $this->scheitern('Team ' . $this->teamId . ' existiert nicht mehr.');

            return;
        }

        $ergebnis = $batch->laufe(
            team: $team,
            limit: $this->limit,
            nurVerkauf: $this->nurVerkauf,
            pass: $this->pass,
            userId: $this->userId,
            runId: $this->runId,
        );

        // Das Ergebnis gehört an den Lauf: „was hat der Batch gefunden?" beantwortet
        // `runs.GET`, nicht der Server-Log. Status/Fortschritt setzt der Batch selbst.
        $run = FoodAlchemistBulkRun::find($this->runId);
        if ($run !== null) {
            $run->update(['context' => array_merge($run->context ?? [], [
                'geprueft' => $ergebnis['geprueft'],
                'befunde_offen' => $ergebnis['offen'],
                'befunde_neu' => $ergebnis['neu'],
            ])]);
        }
    }

    public function failed(?\Throwable $e): void
    {
        $this->scheitern($e ?? 'Unbekannter Fehler im Befunde-Lauf.');
    }

    private function scheitern(\Throwable|string $grund): void
    {
        Log::warning('[foodalchemist] Befunde-Lauf gescheitert', [
            'run_id' => $this->runId,
            'team_id' => $this->teamId,
            'grund' => $grund instanceof \Throwable ? $grund->getMessage() : $grund,
        ]);

        FoodAlchemistBulkRun::markiereGescheitert($this->runId, $grund);
    }
}
