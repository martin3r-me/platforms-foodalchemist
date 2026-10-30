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
class EnrichGeneratedRecipeJob implements ShouldQueue, \Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \Platform\FoodAlchemist\Support\MitKiVerursacher;   // Spec 84 A-3: Job läuft im Auftragsteam

    public int $timeout = 300;
    public int $tries = 1;

    public ?string $knowledgeRunId = null;

    public function __construct(
        public string $runId,
        public int $teamId,
        public int $userId,
        public int $recipeId,
        public array $recipePayload,
        public ?float $zielVk = null,
        ?string $knowledgeRunId = null,
        /** Spec 53 / Paket C: Kaskaden-Step, an dem Phase + Konformitätsprüfung sichtbar werden. */
        public ?int $cascadeStepId = null,
        public bool $extern = false,   // #1002 P2c-2
        public int $versuch = 0,
    ) {
        // #1002 Kaskade: aus einem externen MCP-Aufruf/Lauf angestoßen → selbst extern, Dispatch erst nach dem Commit
        $this->extern = $this->extern || app(\Platform\FoodAlchemist\Services\Ai\KiExternModus::class)->fuerDispatch();
        if ($this->extern && $this->versuch === 0) {
            $this->afterCommit = true;
        }
        $this->knowledgeRunId = $knowledgeRunId;
        if ($this->knowledgeRunId === null && true) {
            $this->knowledgeRunId = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::query()
                ->where('team_id', $this->teamId)->where('is_sales_recipe', false)->whereKey($this->recipeId)->value('knowledge_run_id');
        }
    }

    // ── #1002 P2c-2 · KiExternWiederholbar ──
    public function externAktiv(): bool
    {
        return $this->extern;
    }

    public function externSchluessel(): string
    {
        return "enrich-generated:{$this->runId}";
    }

    public function externTeamId(): int
    {
        return $this->teamId;
    }

    public function externVersuch(): int
    {
        return $this->versuch;
    }

    public function externNutzlast(): array
    {
        return ['run_id' => $this->runId, 'team_id' => $this->teamId, 'user_id' => $this->userId, 'recipe_id' => $this->recipeId,
            'recipe_payload' => $this->recipePayload, 'ziel_vk' => $this->zielVk, 'knowledge_run_id' => $this->knowledgeRunId, 'cascade_step_id' => $this->cascadeStepId];
    }

    public static function fromExternNutzlast(array $n, int $versuch): static
    {
        return new static((string) $n['run_id'], (int) $n['team_id'], (int) $n['user_id'], (int) $n['recipe_id'], (array) ($n['recipe_payload'] ?? []),
            $n['ziel_vk'] === null ? null : (float) $n['ziel_vk'], $n['knowledge_run_id'] ?? null, $n['cascade_step_id'] === null ? null : (int) $n['cascade_step_id'], true, $versuch);
    }

    public function handle(RecipeOneShotService $oneShot): void
    {
        $stand = app(\Platform\FoodAlchemist\Services\Ai\KiExternLauf::class)->fuehreAus($this, fn () => $this->arbeite($oneShot));
        if ($stand === \Platform\FoodAlchemist\Services\Ai\KiExternLauf::WARTET) {
            Cache::put(GenerateRecipeJob::cacheKey($this->runId), [...$this->recipePayload, 'status' => 'enriching', 'progress' => 'wartet auf KI-Antwort (ki_auftraege)', 'wartet_auf' => 'ki_auftraege'], now()->addMinutes(15));
        }
    }

    private function arbeite(RecipeOneShotService $oneShot): void
    {
        $team = Team::find($this->teamId);
        if ($this->knowledgeRunId === null || $team === null) {
            $this->handleInRun($oneShot);
            return;
        }
        $run = app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeRunService::class)->load($team, $this->knowledgeRunId);
        app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeRunContext::class)->within($run, fn () => $this->handleInRun($oneShot));
    }

    private function handleInRun(RecipeOneShotService $oneShot): void
    {
        $team = Team::find($this->teamId);
        $user = User::find($this->userId);
        $recipe = $team === null ? null : FoodAlchemistRecipe::visibleToTeam($team)->find($this->recipeId);
        if ($team === null || $user === null || $recipe === null) {
            $this->fertigMitFehler('Team, User oder Rezept für die Anreicherung nicht gefunden.');

            return;
        }

        Auth::login($user);
        // Spec 53 / Paket C: Phase am Kaskaden-Step sichtbar machen, solange dieser (Voll-Anreicherungs-)
        // Pass läuft — derselbe Text wie EnrichRecipeJob::markEnrich, damit die Anzeige nicht unterscheidet,
        // welcher der beiden Anreicherungs-Jobs gerade läuft.
        if ($this->cascadeStepId !== null) {
            app(\Platform\FoodAlchemist\Services\PlanningCascadeService::class)
                ->setzePhase($this->cascadeStepId, \Platform\FoodAlchemist\Services\PlanningCascadeService::PHASE_ANREICHERUNG);
        }
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
                    $this->knowledgeRunId, $this->cascadeStepId, extern: $this->extern,
                )->afterCommit();
            } catch (\Throwable $e) {
                // schlucken — Konformität ist nachgelagert, nie ein Grund für einen Enrich-Fehler.
            }
        } catch (\Throwable $e) {
            if (app(\Platform\FoodAlchemist\Services\Ai\KiExternModus::class)->wartet()) {
                return;                                              // wartet auf eine externe Antwort: kein „fertig mit Fehler“, die Wiederholung folgt
            }
            $this->fertigMitFehler($e->getMessage());
        } finally {
            if ($this->cascadeStepId !== null) {
                app(\Platform\FoodAlchemist\Services\PlanningCascadeService::class)
                    ->setzePhase($this->cascadeStepId, null);
            }
        }
    }

    /** Das Rezept bleibt auch bei Timeout/Fatal ein erfolgreiches Ergebnis. */
    public function failed(\Throwable $e): void
    {
        $this->fertigMitFehler('Anreicherung abgebrochen: ' . $e->getMessage());
        // Harter Job-Tod (Timeout/OOM) läuft NICHT durchs finally in handleInRun() — ohne das hier
        // bliebe die Phase am Step stehen und $pollAktiv würde für immer weiterpollen.
        if ($this->cascadeStepId !== null) {
            app(\Platform\FoodAlchemist\Services\PlanningCascadeService::class)
                ->setzePhase($this->cascadeStepId, null);
        }
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
