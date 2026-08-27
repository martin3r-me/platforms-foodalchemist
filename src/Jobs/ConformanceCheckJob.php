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
use Platform\FoodAlchemist\Services\ConformanceService;

/**
 * Schicht 3 — der Konformitäts-Critic als Async-Job (auto nach Generierung + on-demand).
 *
 * Fährt die Selbstheil-Prüfung getrennt vom Generierungs-Request: prüfen → bei Verstoß
 * EINE autonome Revise-Runde → nachprüfen → Rest als Hinweis ablegen. Läuft im Enrich-Pfad
 * NACH der Anreicherung (Rezept final), sonst direkt nach Phase 1 — nie parallel zu einem
 * anderen Schreiber desselben Rezepts.
 *
 * BEST-EFFORT: eine gescheiterte Konformitäts-Prüfung darf die bereits fertige Generierung
 * nie zum Fehler machen — jeder Fehler bleibt im Job (tries=1, kein Rethrow). Auth-Restore
 * wie bei {@see GenerateRecipeJob} (AiGatewayService liest Auth::user() für Kill-Switch/DNA/Log).
 */
class ConformanceCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public int $teamId,
        public int $userId,
        /** Adapter-Typ: basisrezept | gericht (vk) | … */
        public string $artifactTyp,
        public int $artifactId,
    ) {
    }

    public function handle(ConformanceService $conformance): void
    {
        $team = Team::find($this->teamId);
        $user = User::find($this->userId);
        if ($team === null || $user === null) {
            return;
        }

        Auth::login($user);   // Team-Kontext für AiGatewayService (Kill-Switch / Food-DNA / Call-Log)

        try {
            $conformance->pruefeUndHeile($team, $this->artifactTyp, $this->artifactId);
        } catch (\Throwable $e) {
            // Best-effort — eine gescheiterte Prüfung ist nie ein Grund, das fertige Artefakt zu kippen.
        }
    }
}
