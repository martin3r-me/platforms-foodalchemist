<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Jobs\QualityRunJob;
use Platform\FoodAlchemist\Jobs\RecipeFindingsRunJob;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;

/**
 * Die **eine** Tür zu den beiden Qualitäts-Läufen: Cockpit-Knopf, MCP und Scheduler
 * reihen hier ein, statt jeder für sich einen Job zu dispatchen.
 *
 * Der Anlass war ein doppelter Ausfall auf demo (2026-07-28):
 *
 *  1. Der „Prüfen"-Knopf lag seit `a372369` (2026-06-17) im Cockpit, war aber
 *     **unerreichbar** — er hing im `<x-slot:end>` des Core-`x-ui-page-actionbar`, und
 *     dessen Inhalt rendert auf demo nicht (dieselbe Seite verlor „+ Neues Wissen").
 *  2. Selbst erreichbar hätte er nichts genützt: `ReviewQueue::detektorLaufen()` rief
 *     `SignalDetektorService::laufen()` **synchron im Livewire-Request** — 11 Detektoren
 *     plus Voll-Messung der Kaskade plus Snapshot plus Drift, auf demo über 7.942 Artikel
 *     und 2.297 Rezepte. Das ist kein Request, das ist ein Batch.
 *
 * Die Folge war ein blinder Fleck, der wie ein fehlendes Feature aussah: 20+ Signal-Typen
 * (Rezept/Konzept/Foodbook) und die ganze Zeitreihe existierten im Code, aber nie in den
 * Daten, weil niemand den Detektor je ausgeführt hat.
 *
 * Beide Läufe sind getrennt, und zwar aus Kostengründen — nicht aus Ordnungsliebe:
 * `Ampel` ist deterministisch und gratis, `Befunde` ruft das Modell pro Rezept. Ein
 * gemeinsamer Knopf hätte jede Ampel-Messung zu einer Provider-Rechnung gemacht.
 */
class QualityRunService
{
    /**
     * „Ampel neu messen" — Detektoren, DQ-Kaskade, Snapshot und Drift asynchron.
     *
     * Idempotent gegen Doppelklick: läuft für dieses Team schon ein Ampel-Lauf, wird
     * **dieser** zurückgegeben statt ein zweiter eingereiht. Zwei parallele Detektor-Läufe
     * wären nicht bloß Verschwendung — sie schreiben beide einen Snapshot in dieselbe
     * Zeitreihe, und der Drift-Vergleich (E3) hält dann einen Punkt gegen sich selbst und
     * meldet „keine Veränderung", wo eine war.
     *
     * @return array{run_id:int,bereits_laufend:bool}
     */
    public function starteAmpelLauf(Team $team, ?int $userId = null): array
    {
        $offen = $this->laufenderLauf($team, BulkRunType::Detektor);
        if ($offen !== null) {
            return ['run_id' => (int) $offen->id, 'bereits_laufend' => true];
        }

        // total = 1: der Lauf ist ein Vorgang, keine Stückliste. `umfangSteht: false`
        // hält `starte()` davon ab, ihn als „leere Menge" sofort abzuschließen.
        $run = FoodAlchemistBulkRun::starte(
            $team->id,
            BulkRunType::Detektor,
            1,
            ['gegenstand' => 'signale + datenqualitaet + snapshot + drift'],
            $userId,
            umfangSteht: false,
        );

        QualityRunJob::dispatch((int) $run->id, (int) $team->id);

        return ['run_id' => (int) $run->id, 'bereits_laufend' => false];
    }

    /**
     * „KI-Befunde sammeln" — der Copilot-Batch über die fällige Arbeitsmenge.
     *
     * Anders als beim Ampel-Lauf ist ein zweiter Lauf hier **nicht** sinnlos: die
     * Arbeitsmenge ist durch `--limit` gedeckelt, und wer den Bestand abarbeiten will,
     * startet bewusst mehrfach. Der Doppelklick-Schutz greift darum absichtlich nicht —
     * die Bremse ist das Limit, nicht die Einreihung.
     *
     * @return array{run_id:int,limit:int}
     */
    public function starteBefundeLauf(
        Team $team,
        int $limit = RecipeFindingsBatchService::DEFAULT_LIMIT,
        bool $nurVerkauf = false,
        string $pass = RecipeFindingService::PASS_COPILOT,
        ?int $userId = null,
    ): array {
        $limit = max(1, min(RecipeFindingsBatchService::MAX_LIMIT, $limit));

        // Die Quittung entsteht VOR dem Worker: ein Aufrufer (MCP) bekommt sofort eine
        // `run_id`, die er über `runs.GET` verfolgen kann. Den Umfang kennt erst der Job
        // nach der Arbeitsmengen-Abfrage — darum `umfangSteht: false`, sonst würde ein Lauf
        // mit total 0 hier fälschlich als „leere Menge" abgeschlossen, bevor gezählt wurde.
        $run = FoodAlchemistBulkRun::starte(
            $team->id,
            BulkRunType::Review,
            0,
            ['pass' => $pass, 'limit' => $limit, 'nur_verkauf' => $nurVerkauf],
            $userId,
            umfangSteht: false,
        );

        RecipeFindingsRunJob::dispatch((int) $run->id, (int) $team->id, $limit, $nurVerkauf, $pass, $userId);

        return ['run_id' => (int) $run->id, 'limit' => $limit];
    }

    /** Läuft für dieses Team schon ein Lauf dieser Art (und ist er nicht verwaist)? */
    private function laufenderLauf(Team $team, BulkRunType $typ): ?FoodAlchemistBulkRun
    {
        return FoodAlchemistBulkRun::query()
            ->where('team_id', $team->id)
            ->where('type', $typ->value)
            ->where('status', BulkRunStatus::Running->value)
            ->where('updated_at', '>=', now()->subHours(FoodAlchemistBulkRun::VERWAIST_NACH_STUNDEN))
            ->latest('id')
            ->first();
    }
}
