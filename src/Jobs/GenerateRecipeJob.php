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

    /** < Worker-Timeout (600 s), > typischer Lauf (~25 s + Nachbearbeitung). */
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
            $r = $generator->generiere($team, $this->description, $this->parameter, null, $this->vkModus);
            if ($r === [] || ! isset($r['recipe'])) {
                throw new \RuntimeException('Generierung lieferte kein Ergebnis.');
            }
            $this->schreibe([
                'status' => 'done',
                'recipe_id' => $r['recipe']->id,
                'name' => $r['recipe']->name,
                'statistik' => $r['statistik'],
                'offene' => $r['offene'],
            ]);
        } catch (\Throwable $e) {
            $this->schreibe(['status' => 'error', 'fehler' => $e->getMessage()]);
        }
    }

    /** Job-Tod (Timeout/Fatal außerhalb des handle-try) → Status trotzdem setzen, sonst pollt die UI ewig. */
    public function failed(\Throwable $e): void
    {
        $this->schreibe(['status' => 'error', 'fehler' => 'Generierung abgebrochen: ' . $e->getMessage()]);
    }

    private function schreibe(array $data): void
    {
        Cache::put(self::cacheKey($this->runId), $data, now()->addMinutes(15));
    }
}
