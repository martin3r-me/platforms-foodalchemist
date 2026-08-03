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
use Platform\FoodAlchemist\Services\PlanningCascadeService;

/**
 * Fan-out-Worker der Planungs-Kaskade (P1b): erdet EINE erfundene Concept-Idee zu einem VK-Gericht
 * und verdrahtet es in den vorgemerkten leeren Concept-Slot. Ein Job je erfundenem Gericht (analog
 * {@see MaterializeIdeaJob} am Foodbook-Kapitel) — so bleibt jede Generierung in ihrer eigenen
 * Queue-Ausführung (kein 502) und meldet ihr Ergebnis an den eigenen Kaskaden-Step zurück.
 *
 * Die eigentliche Arbeit (Generierung + Verdrahtung + Lineage + Step-Rückmeldung) liegt in
 * {@see PlanningCascadeService::materialisiereConceptGericht} — der Job ist nur der Auth-/Queue-Rahmen.
 */
class MaterializeConceptIdeaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Rezept-Generierung inkl. Grounding — unter dem demo-Worker-Timeout (600 s). */
    public int $timeout = 300;

    /** KI-Kosten: kein stiller Auto-Retry. */
    public int $tries = 1;

    public function __construct(
        public int $teamId,
        public int $userId,
        public int $ideaId,
        public int $cascadeStepId,
    ) {}

    public function handle(): void
    {
        $team = Team::find($this->teamId);
        if ($team === null) {
            app(PlanningCascadeService::class)->markStepFailed($this->cascadeStepId, 'Team nicht gefunden.');

            return;
        }
        $user = User::find($this->userId);
        if ($user !== null) {
            Auth::login($user);   // Team-Kontext für AiGatewayService (Kill-Switch/DNA/Call-Log)
        }

        app(PlanningCascadeService::class)->materialisiereConceptGericht($team, $this->ideaId, $this->cascadeStepId);
    }

    /** Job-Tod (Timeout/Fatal) → Step trotzdem terminal setzen, sonst hängt der Run auf „running". */
    public function failed(\Throwable $e): void
    {
        app(PlanningCascadeService::class)->markStepFailed($this->cascadeStepId, 'Fan-out abgebrochen: ' . $e->getMessage());
    }
}
