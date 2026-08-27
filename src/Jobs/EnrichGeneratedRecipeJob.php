<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeOneShotService;

/** Zweite Phase der KI-Erstellung: reichert ein bereits gespeichertes Rezept an. */
class EnrichGeneratedRecipeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        public string $runId,
        public int $teamId,
        public int $userId,
        public int $recipeId,
        public array $recipePayload,
        public ?float $zielVk = null,
    ) {
    }

    public function handle(RecipeOneShotService $oneShot): void
    {
        $team = Team::find($this->teamId);
        $user = User::find($this->userId);
        $recipe = $team === null ? null : FoodAlchemistRecipe::visibleToTeam($team)->find($this->recipeId);
        if ($team === null || $user === null || $recipe === null) {
            $this->fertigMitFehler('Team, User oder Rezept für die Anreicherung nicht gefunden.');

            return;
        }

        Auth::login($user);
        try {
            $this->schreibe([
                ...$this->recipePayload,
                'status' => 'done',
                'anreicherung' => $oneShot->anreichern($team, $recipe, $this->zielVk, completeCoverage: true),
            ]);
            // Schicht 3: nach der Anreicherung (Rezept jetzt final) den Konformitäts-Critic
            // anstoßen — best-effort, ein Dispatch-Fehler kippt das fertige Rezept nicht.
            try {
                ConformanceCheckJob::dispatch(
                    $this->teamId, $this->userId, $recipe->is_sales_recipe ? 'gericht' : 'basisrezept', (int) $recipe->id,
                );
            } catch (\Throwable $e) {
                // schlucken — Konformität ist nachgelagert, nie ein Grund für einen Enrich-Fehler.
            }
        } catch (\Throwable $e) {
            $this->fertigMitFehler($e->getMessage());
        }
    }

    /** Das Rezept bleibt auch bei Timeout/Fatal ein erfolgreiches Ergebnis. */
    public function failed(\Throwable $e): void
    {
        $this->fertigMitFehler('Anreicherung abgebrochen: ' . $e->getMessage());
    }

    private function fertigMitFehler(string $fehler): void
    {
        $this->schreibe([
            ...$this->recipePayload,
            'status' => 'done',
            'anreicherung' => [
                'run_id' => null, 'schritte' => [], 'uebersprungen' => [],
                'uebernommen' => 0, 'offen' => 0,
                'fehler' => mb_strimwidth($fehler, 0, 300),
                'kohaerenz_urteil' => null, 'wirtschaftlichkeit' => null, 'coverage' => null,
            ],
        ]);
    }

    private function schreibe(array $data): void
    {
        Cache::put(GenerateRecipeJob::cacheKey($this->runId), $data, now()->addMinutes(15));
    }
}
