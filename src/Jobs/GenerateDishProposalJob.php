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
        // Spec 53 / Paket C-Nachtrag: Phase sichtbar machen, solange der Bauplan-Call läuft — dieser
        // Step-Typ hat keinen eigenen fortschritt()-Callback (kiDivergenzSession meldet keine Stufen),
        // daher EINE Phase statt vier.
        $cascade->setzePhase($this->stepId, 'Gerichtsvorschlag wird erfunden …');
        try {
            $result = $ideen->kiDivergenzSession($team, $this->sessionId, $this->brief, 1, $this->creativeMode);
            $idee = $result['angelegt'][0] ?? null;
            if ($idee === null) {
                throw new \RuntimeException('Kein verwertbarer Gerichtsvorschlag erzeugt.');
            }
            if ($cascade->istAbgebrochen((int) $step->cascade_run_id)) {
                $idee->update(['status' => 'verworfen']);
                $cascade->setzePhase($this->stepId, null);
                return;
            }
            $meta = $idee->source_meta ?? [];
            $meta['target_concept_slot_id'] = 0;
            $idee->update(['source_meta' => $meta]);
            // VOR dem Status-Wechsel löschen: setzePhase() lässt nur queued/running/done/freigegeben
            // zu (Guard gegen tote Steps) — nach dem Sprung auf `geplant` würde der Aufruf No-op sein,
            // und die stale Model-Instanz $step (geladen vor jedem setzePhase-Query-Update) darf `phase`
            // auch nicht im untenstehenden $step->update() „miterledigen" (Dirty-Checking sähe es
            // fälschlich als bereits null und ließe die Spalte in der UPDATE-Query ganz weg).
            $cascade->setzePhase($this->stepId, null);
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

    /** Job-Tod (Timeout/Fatal außerhalb des handle-try) → Step terminal setzen (löscht auch die
     *  Phase), sonst bleibt „Gerichtsvorschlag wird erfunden …" für immer stehen. */
    public function failed(\Throwable $e): void
    {
        app(PlanningCascadeService::class)->markStepFailed($this->stepId, 'Gerichtsvorschlag abgebrochen: ' . $e->getMessage());
    }
}
