<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Illuminate\Support\Facades\Queue;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;

/**
 * Spec 80 B1: ein Basisrezept-Go aus der Planung plant zuerst die Komponenten (GenerateRecipePlanJob).
 * Für Tests, die den Bau-Job prüfen, winkt das den Plan wie bei „nur ein Baustein“ durch — der Bau-Job
 * landet dann mit den Lauf-Parametern im Queue-Fake, genau wie in Produktion. Kind-Basisrezepte planen ebenfalls
 * zuerst (automatisch, ohne Gate) und werden hier genauso durchgewunken.
 */
final class KomponentenPlanDurch
{
    public static function bauen(): void
    {
        foreach (Queue::pushed(GenerateRecipePlanJob::class) as $job) {
            $step = FoodAlchemistCascadeRunStep::find($job->stepId);
            if ($step?->parent_step_id !== null) {
                // Kind-Basisrezept: plant automatisch und baut ohne Gate — hier als „ein Baustein“ durchgewinkt.
                if ($step->status === 'running' && $step->generator_run_id === null) {
                    app(RecipeDependencyWorkflowService::class)->baueKindNachPlan(Team::findOrFail($job->teamId), $step, $job->userId, $job->brief, $job->params, []);
                }

                continue;
            }
            app(PlanningCascadeService::class)->starteRezeptNachPlan(Team::findOrFail($job->teamId), $job->stepId, []);
        }
    }
}
