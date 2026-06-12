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
 * M7-06 / V-15: Bulk-Autopilot als Queue-Job — iteriert die Rezepte EINES
 * Runs sequenziell (rate-schonend, Ist-Verhalten) und schreibt den
 * Fortschritt je Rezept in bulk_runs (Livewire pollt). Fehler je Item werden
 * gezählt, der Run läuft weiter (ein roter Call killt nicht den Batch).
 */
class BulkEnrichJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    /** @param list<int> $recipeIds */
    public function __construct(
        public int $runId,
        public int $teamId,
        public array $recipeIds,
        public array $schritte,
    ) {
    }

    public function handle(BulkEnrichService $bulk): void
    {
        $team = Team::find($this->teamId);
        if ($team === null) {
            return;
        }
        foreach ($this->recipeIds as $recipeId) {
            $bulk->verarbeiteRezept($team, $this->runId, (int) $recipeId, $this->schritte);
        }
    }
}
