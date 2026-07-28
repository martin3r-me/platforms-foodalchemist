<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Services\FileArticleImportService;

/**
 * Spec 13 · S3b — der scharfe Kanal-B-Import als Queue-Job. Grund für den Job: der Lauf
 * schreibt Artikel, Preise und Detail-Blöcke und rechnet danach die ganze betroffene
 * Rezept-Menge neu (`RecipeRecomputeService::recomputeMany`, seit V-049 ohne Deckel —
 * der Job-Timeout ist die verbleibende Schranke) — das gehört nicht in einen
 * synchronen Tool-Call (dieselbe Lehre wie beim Rezept-Generator, ROADMAP „URSACHE 4").
 *
 * **Kein zweiter Import-Pfad:** der Job ruft dasselbe `importiere(..., apply: true)`,
 * das auch das Kommando fährt, und dasselbe `beendeRun()` — er ist nur ein anderer
 * Auslöser.
 *
 * `tries = 1` mit Absicht: der Upsert ist idempotent, ein Wiederholungslauf wäre also
 * fachlich harmlos — aber ein automatischer Retry würde einen echten Fehler (unlesbare
 * Datei, D1-Ausnahme) mehrfach mit demselben Ergebnis wiederholen und den Lauf-Status
 * verschleiern. Wiederholt wird menschlich, mit derselben Datei.
 */
class ImportArticlesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Reader (20k Zeilen) + Schreibpfad + Recompute-Kette — großzügig, aber begrenzt. */
    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public int $runId,
        public int $teamId,
        public int $supplierId,
        public string $pfad,
    ) {
    }

    public function handle(FileArticleImportService $import): void
    {
        $team = Team::find($this->teamId);
        if ($team === null) {
            $this->markiere(BulkRunStatus::Failed);

            return;
        }

        $bericht = $import->importiere($team, $this->supplierId, $this->pfad, apply: true);
        $import->beendeRun($this->runId, $bericht);
    }

    /**
     * Der eigene Fehl-Pfad — greift bei Ausnahme UND Timeout. Ohne ihn stünde die
     * Lauf-Zeile für immer auf `running` und `ingest.STATUS` meldete einen toten Lauf
     * dauerhaft als „läuft gerade" (V-054). Die Bestands-Schreiber (Kommandos,
     * Bulk-Autopilot) behalten ihr Verhalten — das ist bewusst keine Sammel-Reparatur.
     */
    public function failed(?\Throwable $e): void
    {
        $this->markiere(BulkRunStatus::Failed);
    }

    private function markiere(BulkRunStatus $status): void
    {
        FoodAlchemistBulkRun::whereKey($this->runId)
            ->update(['status' => $status->value, 'updated_at' => now()]);
    }
}
