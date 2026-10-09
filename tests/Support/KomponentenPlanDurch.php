<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Illuminate\Support\Facades\Queue;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob;
use Platform\FoodAlchemist\Services\PlanningCascadeService;

/**
 * Spec 80 B1: ein Basisrezept-Go aus der Planung plant zuerst die Komponenten (GenerateRecipePlanJob).
 * Für Tests, die den Bau-Job prüfen, winkt das den Plan wie bei „nur ein Baustein“ durch — der Bau-Job
 * landet dann mit den Lauf-Parametern im Queue-Fake, genau wie in Produktion.
 */
final class KomponentenPlanDurch
{
    public static function bauen(): void
    {
        foreach (Queue::pushed(GenerateRecipePlanJob::class) as $job) {
            app(PlanningCascadeService::class)->starteRezeptNachPlan(Team::findOrFail($job->teamId), $job->stepId, []);
        }
    }
}
