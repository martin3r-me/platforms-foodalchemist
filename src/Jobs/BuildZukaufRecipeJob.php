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
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\ZukaufBasisrezeptService;

/**
 * Baut ein Zukauf-Basisrezept für einen Kind-Step (Kaufware im Gericht) — ohne Komponenten-Plan, ohne den großen
 * Generator, ohne Heilung. Danach wie ein fertig gebautes Kind: an die Eltern-Zeile binden, Step done.
 */
class BuildZukaufRecipeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    /**
     * @param  array{gp_id: int, gp_name: string}  $ware
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public int $teamId,
        public int $userId,
        public int $stepId,
        public string $text,
        public array $ware,
        public array $params,
    ) {}

    public function handle(ZukaufBasisrezeptService $zukauf, PlanningCascadeService $cascade, RecipeDependencyWorkflowService $workflow): void
    {
        $team = Team::find($this->teamId);
        $step = FoodAlchemistCascadeRunStep::find($this->stepId);
        if ($team === null || $step === null || $cascade->istAbgebrochen((int) $step->cascade_run_id)) {
            return;
        }
        if (($user = User::find($this->userId)) !== null) {
            Auth::login($user);
        }
        $cascade->setzePhase($this->stepId, 'Zukauf wird angelegt …');
        try {
            $recipe = $zukauf->baue($team, $step, $this->text, $this->ware, $this->params);
            $cascade->setzePhase($this->stepId, null);
            $workflow->afterGenerated($team, $this->stepId, $this->userId, $recipe, [], [...$this->params, 'cascade_step_id' => $this->stepId]);
            $cascade->markStepDone($this->stepId, 'recipe', (int) $recipe->id);
        } catch (\Throwable $e) {
            $cascade->markStepFailed($this->stepId, 'Zukauf-Basisrezept fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function failed(\Throwable $e): void
    {
        app(PlanningCascadeService::class)->markStepFailed($this->stepId, 'Zukauf-Basisrezept abgebrochen: ' . $e->getMessage());
    }
}
