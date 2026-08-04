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
use Platform\FoodAlchemist\Services\RecipeGeneratorService;

/**
 * Async-Rezept-/VK-Generierung (2026-07-20).
 *
 * Anlass: Der synchrone Generierungs-Request (LLM ~25 s + GP-Matching +
 * Aggregation + Recompute) reißt den nginx-fastcgi-Timeout (60 s Default) →
 * PHP-FPM-Worker wird mitten im Call gekillt → 502 (kein ai_call_log, weil der
 * finally-Log nie läuft). Der max_tokens-Fix war nötig, aber nicht hinreichend.
 *
 * Lösung: Auslagern in die database-Queue (demo-Worker-Timeout 600 s). Der
 * Web-Request kehrt sofort zurück; die UI pollt das Ergebnis aus dem Cache
 * (Run-ID). Kein 502 mehr, egal wie lang der Call dauert.
 *
 * Auth-Restore: RecipeGeneratorService nimmt das Team explizit, aber der darin
 * genutzte AiGatewayService liest Auth::user() (Kill-Switch, Food-DNA-Kaskade,
 * Call-Log-Zuordnung team_id/user_id). Im Job gibt es keinen eingeloggten User
 * → wir loggen ihn wieder ein, damit die Generierung deckungsgleich zum
 * Web-Pfad läuft (currentTeamRelation = reine current_team_id-Relation).
 */
class GenerateRecipeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Nur Phase 1; die Anreicherung hat einen eigenen Queue-Job. */
    public int $timeout = 300;

    /** KI-Kosten: kein stiller Auto-Retry der ganzen Generierung. */
    public int $tries = 1;

    public function __construct(
        public string $runId,
        public int $teamId,
        public int $userId,
        public string $description,
        public array $parameter = [],
        public bool $vkModus = false,
        /** Nach erfolgreicher Generierung einen separaten Anreicherungs-Job starten. */
        public bool $vollAnreichern = false,
    ) {
    }

    public static function cacheKey(string $runId): string
    {
        return "fa:recipe-gen:{$runId}";
    }

    public function handle(RecipeGeneratorService $generator): void
    {
        $team = Team::find($this->teamId);
        $user = User::find($this->userId);
        if ($team === null || $user === null) {
            $this->schreibe(['status' => 'error', 'fehler' => 'Team oder User nicht gefunden.']);

            return;
        }

        Auth::login($user);   // Team-Kontext für AiGatewayService (Kill-Switch/DNA/Call-Log)

        try {
            $stepId = $this->cascadeStepId();
            $prepared = $stepId !== null
                ? app(\Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService::class)
                    ->prepare($team, $stepId, $this->description, $this->parameter, $this->vkModus)
                : null;
            $r = $generator->generiere($team, $this->description, $this->parameter, null, $this->vkModus, null, $prepared);
            if ($r === [] || ! isset($r['recipe'])) {
                throw new \RuntimeException('Generierung lieferte kein Ergebnis.');
            }
            // Planungs-„Go"-Lineage: Trend-Herkunft + created_via=plan_go ans Rezept, Session→konvergenz.
            $planId = isset($this->parameter['planning_session_id']) ? (int) $this->parameter['planning_session_id'] : null;
            if ($planId !== null) {
                $planSvc = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class);
                $session = $planSvc->get($team, $planId);
                if ($session !== null) {
                    $planSvc->verknuepfeArtefakt($session, 'recipe', (int) $r['recipe']->id);
                }
            }
            $payload = [
                'recipe_id' => $r['recipe']->id,
                'name' => $r['recipe']->name,
                'statistik' => $r['statistik'],
                'offene' => $r['offene'],
            ];
            if ($stepId !== null) {
                app(\Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService::class)->afterGenerated(
                    $team, $stepId, $this->userId, $r['recipe'], $r['offene'], $this->parameter,
                );
            }
            // Kaskaden-Rückkanal (P0): meldet Ergebnis an den Step, wenn dieser Job Teil einer
            // Planungs-Kaskade ist. Backward-kompatibel — ohne cascade_step_id passiert nichts.
            $this->meldeKaskade(true, (int) $r['recipe']->id, null);
            if (! $this->vollAnreichern) {
                $this->schreibe(['status' => 'done', ...$payload]);

                return;
            }

            // Phase 1 ist vollständig und sichtbar; Phase 2 arbeitet separat.
            $this->schreibe(['status' => 'enriching', ...$payload]);
            try {
                EnrichGeneratedRecipeJob::dispatch(
                    $this->runId, $this->teamId, $this->userId, (int) $r['recipe']->id,
                    $payload, $this->zielVk(),
                );
            } catch (\Throwable $e) {
                (new EnrichGeneratedRecipeJob(
                    $this->runId, $this->teamId, $this->userId, (int) $r['recipe']->id,
                    $payload, $this->zielVk(),
                ))->failed($e);
            }
        } catch (\Throwable $e) {
            $this->schreibe(['status' => 'error', 'fehler' => $e->getMessage()]);
            $this->meldeKaskade(false, null, $e->getMessage());
        }
    }

    /**
     * Spec 03 L8b-2: der Ziel-VK reist im Parameter-Bündel mit (dort ist er der
     * Prompt-Constraint) und wird danach an das Wirtschaftlichkeits-Glied gereicht,
     * das ihn gegen den gerechneten Preis hält. Kein eigener Konstruktor-Parameter:
     * es ist dieselbe Vorgabe, einmal für die KI und einmal für den Abgleich —
     * zwei Transportwege würden sie auseinanderlaufen lassen.
     */
    private function zielVk(): ?float
    {
        $roh = $this->parameter['ziel_vk_eur'] ?? null;

        return is_numeric($roh) ? (float) $roh : null;
    }

    /** Job-Tod (Timeout/Fatal außerhalb des handle-try) → Status trotzdem setzen, sonst pollt die UI ewig. */
    public function failed(\Throwable $e): void
    {
        $this->schreibe(['status' => 'error', 'fehler' => 'Generierung abgebrochen: ' . $e->getMessage()]);
        $this->meldeKaskade(false, null, 'Generierung abgebrochen: ' . $e->getMessage());
    }

    /** cascade_step_id aus dem Parameter-Bündel (Rückkanal-Ziel), null wenn kein Kaskaden-Lauf. */
    private function cascadeStepId(): ?int
    {
        $roh = $this->parameter['cascade_step_id'] ?? null;

        return is_numeric($roh) ? (int) $roh : null;
    }

    /**
     * Ergebnis/Fehler an den Kaskaden-Step zurückmelden (P0). No-op ohne cascade_step_id — hält die
     * Bestandspfade (Generator-Modals) byte-identisch. Wirft nie: ein Fehler im Rückkanal darf den
     * Job-Ausgang (Cache) nicht kippen.
     */
    private function meldeKaskade(bool $erfolg, ?int $recipeId, ?string $fehler): void
    {
        $stepId = $this->cascadeStepId();
        if ($stepId === null) {
            return;
        }
        try {
            $svc = app(\Platform\FoodAlchemist\Services\PlanningCascadeService::class);
            if ($erfolg && $recipeId !== null) {
                $svc->markStepDone($stepId, 'recipe', $recipeId);
            } else {
                $svc->markStepFailed($stepId, (string) ($fehler ?? 'Generierung fehlgeschlagen.'));
            }
        } catch (\Throwable) {
            // Rückkanal-Fehler bewusst schlucken.
        }
    }

    private function schreibe(array $data): void
    {
        Cache::put(self::cacheKey($this->runId), $data, now()->addMinutes(15));
    }
}
