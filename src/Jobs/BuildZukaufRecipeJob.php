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
class BuildZukaufRecipeJob implements ShouldQueue, \Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \Platform\FoodAlchemist\Support\MitKiVerursacher, \Platform\FoodAlchemist\Services\Ai\KiExternJobTeil;   // Spec 84 A-3: Job läuft im Auftragsteam

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
        public bool $extern = false,   // #1002 Kaskade: aus einem externen Lauf/MCP-Aufruf → KI-Auftrag statt Plattform-Provider
        public int $versuch = 0,
    ) {
        $this->erbeExtern();
    }

    // ── #1002 Kaskade · KiExternWiederholbar ──
    public function externSchluessel(): string
    {
        return "build-zukauf:{$this->teamId}:{$this->stepId}";
    }

    public function externNutzlast(): array
    {
        return ['team_id' => $this->teamId, 'user_id' => $this->userId, 'step_id' => $this->stepId, 'text' => $this->text, 'ware' => $this->ware, 'params' => $this->params];
    }

    public static function fromExternNutzlast(array $n, int $versuch): static
    {
        return new static((int) $n['team_id'], (int) $n['user_id'], (int) $n['step_id'], (string) $n['text'], (array) $n['ware'], (array) $n['params'], true, $versuch);
    }

    public function handle(ZukaufBasisrezeptService $zukauf, PlanningCascadeService $cascade, RecipeDependencyWorkflowService $workflow): void
    {
        app(\Platform\FoodAlchemist\Services\Ai\KiExternLauf::class)->fuehreAus($this, fn () => $this->arbeite($zukauf, $cascade, $workflow));
    }

    /** Der Job-Rumpf — im Plattformweg unverändert, im externen Weg in der Sammel-Transaktion (KiExternLauf). */
    private function arbeite(ZukaufBasisrezeptService $zukauf, PlanningCascadeService $cascade, RecipeDependencyWorkflowService $workflow): void
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
        } catch (\Platform\FoodAlchemist\Exceptions\KiAuftragAusstehendException) {
            // wartet auf die externe Antwort — kein Fehler; ANTWORT startet den Job neu
        } catch (\Throwable $e) {
            $cascade->markStepFailed($this->stepId, 'Zukauf-Basisrezept fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function failed(\Throwable $e): void
    {
        app(PlanningCascadeService::class)->markStepFailed($this->stepId, 'Zukauf-Basisrezept abgebrochen: ' . $e->getMessage());
    }
}
