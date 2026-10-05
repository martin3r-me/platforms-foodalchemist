<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PricingCascadeService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;

/**
 * Verlust-Defaults geändert (Settings/Kalkulation oder team_settings.PUT) → die Rezepte
 * des Teams UND seiner Nachfahren-Teams neu rechnen. Nachfahren gehören dazu, weil die
 * Verlust-Maps org-vererbt sind (TeamSettingsService::ORG_VERERBT): ein Kind ohne eigene
 * Map rechnet mit der des Eltern-Teams.
 *
 * Vorher rechnete das Speichern nur Darreichungen/Pakete/Konzepte/Angebote neu — die
 * bauten auf den ALTEN Rezept-Aggregaten auf, Ausbeute und EK/kg blieben stehen.
 *
 * ASYNC wie SignalFixJob: ein Team hat schnell vierstellig Rezepte. Sync-Queue-Driver
 * (Sandbox/Tests) ⇒ läuft inline.
 */
class RecomputeTeamRecipesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(public int $teamId)
    {
    }

    public function handle(RecipeRecomputeService $recompute, PricingCascadeService $preise): void
    {
        $team = Team::find($this->teamId);
        if ($team === null) {
            return;
        }

        $recipeIds = FoodAlchemistRecipe::whereIn('team_id', self::teamUndNachfahren($team->id))->pluck('id')->all();
        if ($recipeIds !== []) {
            $recompute->recomputeMany($recipeIds);     // topologisch, Preis-Kaskade der betroffenen inklusive
        }
        $preise->recomputeTeam($team);                 // Auto-Angebote/Konzepte des Teams wie bisher
    }

    /** @return list<int> Team-ID + alle Nachfahren (BFS über parent_team_id). */
    public static function teamUndNachfahren(int $teamId): array
    {
        $alle = [$teamId];
        $ebene = [$teamId];
        while ($ebene !== []) {
            $ebene = Team::whereIn('parent_team_id', $ebene)->whereNotIn('id', $alle)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $alle = array_merge($alle, $ebene);
        }

        return $alle;
    }

    /** Anzahl der Rezepte, die ein Lauf für dieses Team anfasst (für die UI-/MCP-Meldung). */
    public static function anzahlRezepte(int $teamId): int
    {
        return FoodAlchemistRecipe::whereIn('team_id', self::teamUndNachfahren($teamId))->count();
    }
}
