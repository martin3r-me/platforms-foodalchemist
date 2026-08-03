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
use Platform\FoodAlchemist\Services\ConceptGeneratorService;

/**
 * Async-Konzept-Generierung für die Planungs-Kaskade (P1a).
 *
 * Spiegelt {@see GenerateRecipeJob}: der Concept-Assembler ruft `AiGatewayService::propose`
 * (Gerüst) + den deterministischen Assembler — im synchronen Web-Request derselbe
 * nginx-fastcgi-Timeout-/502-Risiko wie beim Rezept. Also in die Queue, UI pollt den Step.
 *
 * **Reuse-Modus (P1a):** `generiereAusBrief` baut das Konzept AUSSCHLIESSLICH aus echten
 * VK-Gerichten (keine Erfindung — Slot ohne Treffer bleibt leer). Das Erfinden + der
 * Gericht-Fan-out kommen in P1b. Ergebnis ist immer `status=draft`.
 */
class GenerateConceptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Gerüst-Propose (~1 Call) + deterministischer Assembler — unter dem Worker-Timeout (600 s). */
    public int $timeout = 180;

    /** KI-Kosten: kein stiller Auto-Retry. */
    public int $tries = 1;

    public function __construct(
        public string $runId,
        public int $teamId,
        public int $userId,
        public string $brief,
        public ?string $name = null,
        public ?int $planningSessionId = null,
        public ?int $cascadeStepId = null,
        public bool $useFavorites = false,
        public bool $favoritesConvenienceOnly = false,
    ) {}

    public static function cacheKey(string $runId): string
    {
        return "fa:concept-gen:{$runId}";
    }

    public function handle(ConceptGeneratorService $generator): void
    {
        $team = Team::find($this->teamId);
        $user = User::find($this->userId);
        if ($team === null || $user === null) {
            $this->schreibe(['status' => 'error', 'fehler' => 'Team oder User nicht gefunden.']);
            $this->meldeKaskade(false, null, 'Team oder User nicht gefunden.');

            return;
        }

        Auth::login($user);   // Team-Kontext für AiGatewayService (Kill-Switch/DNA/Call-Log)

        try {
            $r = $generator->generiereAusBrief($team, $this->brief, $this->name, 'plan_go', $this->useFavorites, $this->favoritesConvenienceOnly);
            $concept = $r['concept'] ?? null;
            if ($concept === null) {
                throw new \RuntimeException('Konzept-Generierung lieferte kein Ergebnis.');
            }
            // Planungs-„Go"-Lineage: Trend-Herkunft ans Konzept, Session→konvergenz.
            if ($this->planningSessionId !== null) {
                $planSvc = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class);
                $session = $planSvc->get($team, $this->planningSessionId);
                if ($session !== null) {
                    $planSvc->verknuepfeArtefakt($session, 'concept', (int) $concept->id);
                }
            }
            $this->schreibe([
                'status' => 'done',
                'concept_id' => (int) $concept->id,
                'name' => (string) $concept->name,
                'coverage' => $r['coverage'] ?? null,
            ]);
            $this->meldeKaskade(true, (int) $concept->id, null);
        } catch (\Throwable $e) {
            $this->schreibe(['status' => 'error', 'fehler' => $e->getMessage()]);
            $this->meldeKaskade(false, null, $e->getMessage());
        }
    }

    /** Job-Tod (Timeout/Fatal außerhalb des handle-try) → Status trotzdem setzen. */
    public function failed(\Throwable $e): void
    {
        $this->schreibe(['status' => 'error', 'fehler' => 'Konzept-Generierung abgebrochen: ' . $e->getMessage()]);
        $this->meldeKaskade(false, null, 'Konzept-Generierung abgebrochen: ' . $e->getMessage());
    }

    /** Ergebnis/Fehler an den Kaskaden-Step zurückmelden (No-op ohne cascadeStepId). Wirft nie. */
    private function meldeKaskade(bool $erfolg, ?int $conceptId, ?string $fehler): void
    {
        if ($this->cascadeStepId === null) {
            return;
        }
        try {
            $svc = app(\Platform\FoodAlchemist\Services\PlanningCascadeService::class);
            if ($erfolg && $conceptId !== null) {
                $svc->markStepDone($this->cascadeStepId, 'concept', $conceptId);
            } else {
                $svc->markStepFailed($this->cascadeStepId, (string) ($fehler ?? 'Konzept-Generierung fehlgeschlagen.'));
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
