<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\GpFormService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\LoeffelGewichtNachholen;

/**
 * Schätzt die fehlenden Löffel-Gewichte der GPs eines importierten Rezepts ({@see LoeffelGewichtNachholen}) und rechnet das Rezept
 * danach neu. Je GP über {@see GpFormService::estimateKi} (gp.zaehl_einheiten, Herkunft ki, manuelle Formen bleiben). Ein GP, das
 * dieses Team nicht pflegen darf, wird übersprungen (bleibt beim Rückfall). Ein Fehler wird geloggt, nicht wiederholt.
 */
class LoeffelGewichtNachholenJob implements ShouldBeUnique, ShouldQueue, \Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \Platform\FoodAlchemist\Support\MitKiVerursacher;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    /** @param  list<int>  $gpIds */
    public function __construct(
        public int $teamId,
        public int $userId,
        public int $recipeId,
        public array $gpIds,
        public bool $extern = false,
        public int $versuch = 0,
    ) {}

    // ── #1002 · KiExternWiederholbar ──
    public function externAktiv(): bool
    {
        return $this->extern;
    }

    public function externSchluessel(): string
    {
        return "loeffel-gewicht:{$this->teamId}:{$this->recipeId}";
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
        return ['team_id' => $this->teamId, 'user_id' => $this->userId, 'recipe_id' => $this->recipeId, 'gp_ids' => $this->gpIds];
    }

    public static function fromExternNutzlast(array $n, int $versuch): static
    {
        return new static((int) $n['team_id'], (int) $n['user_id'], (int) $n['recipe_id'], array_map('intval', (array) $n['gp_ids']), true, $versuch);
    }

    public function uniqueId(): string
    {
        return 'loeffel-gewicht-'.$this->recipeId;
    }

    public function handle(GpFormService $formen, RecipeRecomputeService $recompute): void
    {
        app(\Platform\FoodAlchemist\Services\Ai\KiExternLauf::class)->fuehreAus($this, fn () => $this->arbeite($formen, $recompute));
    }

    private function arbeite(GpFormService $formen, RecipeRecomputeService $recompute): void
    {
        $team = Team::find($this->teamId);
        $user = User::find($this->userId);
        $recipe = FoodAlchemistRecipe::find($this->recipeId);
        if ($team === null || $user === null || $recipe === null) {
            return;
        }
        Auth::login($user);   // Team-Kontext für Call-Log, Kill-Switch, Budget und das Pflege-Gate der Formen
        $offen = array_values(array_intersect($this->gpIds, LoeffelGewichtNachholen::fehlende($recipe)));   // inzwischen von Hand gepflegt?
        $geschaetzt = 0;
        foreach ($offen as $gpId) {
            try {
                $geschaetzt += $formen->estimateKi($team, $gpId);
            } catch (\Throwable $e) {
                Log::info("Löffel-Gewicht für GP {$gpId} nicht geschätzt: {$e->getMessage()}");   // fremdes GP / keine Form — bleibt beim Rückfall
            }
        }
        if ($geschaetzt > 0) {
            $recompute->recomputeAndPropagate($this->recipeId);
        }
    }
}
