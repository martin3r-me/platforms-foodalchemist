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
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Services\RecipeOneShotService;

/**
 * Freigabe-Schritt der gestuften Kaskade: reichert ein freigegebenes Draft KOMPLETT an
 * ({@see RecipeOneShotService::anreichern}, completeCoverage) und erzeugt — falls beim Go der
 * KI-Bilder-Toggle (`ki_bilder`) an war — Schritt-für-Schritt-Fotos + ein Produktfoto
 * ({@see RecipeImageService}). Alles fail-soft: das freigegebene Rezept bleibt live, egal was kippt.
 */
class EnrichRecipeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        public int $teamId,
        public int $userId,
        public int $recipeId,
        public ?float $zielVk = null,
        public bool $kiBilder = false,
    ) {}

    public function handle(RecipeOneShotService $oneShot): void
    {
        $team = Team::find($this->teamId);
        $user = User::find($this->userId);
        $recipe = $team === null ? null : FoodAlchemistRecipe::visibleToTeam($team)->find($this->recipeId);
        if ($team === null || $user === null || $recipe === null) {
            return;
        }

        Auth::login($user);   // Team-Kontext (Call-Log/Kill-Switch/DNA)

        try {
            $oneShot->anreichern($team, $recipe, $this->zielVk, completeCoverage: true);
        } catch (\Throwable) {
            // Anreicherung ist fail-soft — das freigegebene Rezept bleibt live.
        }

        if ($this->kiBilder) {
            try {
                app(RecipeImageService::class)->erzeugeFuerRezept($team, $recipe->refresh());
            } catch (\Throwable) {
                // KI-Fotos sind optional (Preisfrage) — Fehler dürfen die Freigabe nie kippen.
            }
        }
    }
}
