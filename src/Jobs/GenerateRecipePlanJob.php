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
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\RecipeKomponentenPlanService;

/**
 * Spec 80 B1 — Komponenten-Plan eines Basisrezepts (Gegenstück zu {@see GenerateDishProposalJob} für
 * Gerichte). Ergebnis: Step `geplant` mit `context_snapshot.komponenten` — der Mensch bestätigt, dann baut
 * {@see PlanningCascadeService::starteRezeptNachPlan}. Ein Plan mit höchstens einer Komponente braucht kein
 * Gate (ein Baustein) und wird sofort gebaut.
 */
class GenerateRecipePlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    /** @param  array<string, mixed>  $params */
    public function __construct(
        public int $teamId,
        public int $userId,
        public int $stepId,
        public string $brief,
        public array $params,
    ) {}

    public function handle(RecipeKomponentenPlanService $plan, PlanningCascadeService $cascade): void
    {
        $team = Team::find($this->teamId);
        $step = FoodAlchemistCascadeRunStep::find($this->stepId);
        if ($team === null || $step === null || $cascade->istAbgebrochen((int) $step->cascade_run_id)) {
            return;
        }
        if (($user = User::find($this->userId)) !== null) {
            Auth::login($user);
        }
        $cascade->setzePhase($this->stepId, 'Komponenten werden geplant …');
        try {
            $istKind = $step->parent_step_id !== null;
            $komponenten = $plan->plane($team, $this->brief, $this->params,
                $istKind ? (int) config('foodalchemist.kaskade.kind_max_komponenten', 4) : null);
            $cascade->setzePhase($this->stepId, null);
            if ($cascade->istAbgebrochen((int) $step->cascade_run_id)) {
                return;
            }
            if ($istKind) {
                // Kind-Basisrezept: kein Mensch-Gate, direkt nach dem Plan bauen (Dominique 09.10.).
                app(\Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService::class)
                    ->baueKindNachPlan($team, $step->fresh(), $this->userId, $this->brief, $this->params, $komponenten);

                return;
            }
            if (count($komponenten) <= 1) {
                // Ein Baustein: kein Plan-Gate, direkt bauen wie bisher.
                $cascade->starteRezeptNachPlan($team, $this->stepId, []);

                return;
            }
            $snapshot = is_array($step->fresh()?->context_snapshot) ? $step->fresh()->context_snapshot : [];
            $step->fresh()?->update([
                'status' => 'geplant',
                'context_snapshot' => [...$snapshot, 'plan' => true, 'komponenten' => $komponenten],
            ]);
            $cascade->recomputeRunStatus((int) $step->cascade_run_id);
        } catch (\Throwable $e) {
            $cascade->markStepFailed($this->stepId, 'Komponenten-Plan fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function failed(\Throwable $e): void
    {
        app(PlanningCascadeService::class)->markStepFailed($this->stepId, 'Komponenten-Plan abgebrochen: ' . $e->getMessage());
    }
}
