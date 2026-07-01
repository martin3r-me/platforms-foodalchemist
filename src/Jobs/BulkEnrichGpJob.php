<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\BulkEnrichService;

/**
 * GP-Bulk-Autopilot als Queue-Job (Pendant zu BulkEnrichJob für Rezepte): iteriert die
 * GPs EINES Runs sequenziell (rate-schonend) und schreibt Vorschläge je Feld. Fehler je
 * Item werden gezählt, der Run läuft weiter. Sandbox/Tests: QUEUE=sync ⇒ inline.
 */
class BulkEnrichGpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    /** @param list<int> $gpIds */
    public function __construct(
        public int $runId,
        public int $teamId,
        public array $gpIds,
        public array $schritte,
    ) {
    }

    public function handle(BulkEnrichService $bulk): void
    {
        $team = Team::find($this->teamId);
        if ($team === null) {
            return;
        }
        foreach ($this->gpIds as $gpId) {
            $bulk->verarbeiteGp($team, $this->runId, (int) $gpId, $this->schritte);
        }
    }
}
