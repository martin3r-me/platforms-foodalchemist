<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;

/** Erzeugt einen textlichen Gericht-Bauplan; noch kein Recipe-Datensatz. */
class GenerateDishProposalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 1;

    public function __construct(
        public int $teamId,
        public int $userId,
        public int $sessionId,
        public int $stepId,
        public string $brief,
        public string $creativeMode,
    ) {}

    public function handle(IdeenService $ideen, PlanningCascadeService $cascade): void
    {
        $team = Team::find($this->teamId);
        $step = FoodAlchemistCascadeRunStep::find($this->stepId);
        if ($team === null || $step === null || $cascade->istAbgebrochen((int) $step->cascade_run_id)) {
            return;
        }
        if (($user = User::find($this->userId)) !== null) {
            Auth::login($user);
        }
        try {
            $result = $ideen->kiDivergenzSession($team, $this->sessionId, $this->brief, 1, $this->creativeMode);
            $idee = $result['angelegt'][0] ?? null;
            if ($idee === null) {
                throw new \RuntimeException('Kein verwertbarer Gerichtsvorschlag erzeugt.');
            }
            if ($cascade->istAbgebrochen((int) $step->cascade_run_id)) {
                $idee->update(['status' => 'verworfen']);
                return;
            }
            $meta = $idee->source_meta ?? [];
            $meta['target_concept_slot_id'] = 0;
            $idee->update(['source_meta' => $meta]);
            $step->update([
                'label' => $idee->title,
                'status' => 'geplant',
                'context_snapshot' => [
                    'dish_idea_id' => (int) $idee->id,
                    'beschreibung' => (string) ($idee->description ?? ''),
                    'komponenten' => array_values((array) ($meta['komponenten'] ?? [])),
                ],
            ]);
            $cascade->recomputeRunStatus((int) $step->cascade_run_id);
        } catch (\Throwable $e) {
            $cascade->markStepFailed($this->stepId, $e->getMessage());
        }
    }
}
