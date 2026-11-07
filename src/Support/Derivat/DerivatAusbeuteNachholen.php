<?php

namespace Platform\FoodAlchemist\Support\Derivat;

use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\DerivatAusbeuteNachholenJob;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Support\Warteschlange;

/**
 * Spec 96 · Fehlende Ausbeute schätzt die KI automatisch (Dominique 06.11.). Steht im Rezept ein Zweck-Derivat (Saft, Abrieb) ohne
 * Ausbeute, schätzt die KI sie einmal im Hintergrund (Herkunft ki) und das Rezept wird neu gerechnet. Bis dahin ist die Zeile
 * unbepreist (Reife-Lücke „Ausbeute fehlt“) — nie ein geratener Wert.
 *
 * Wie {@see \Platform\FoodAlchemist\Support\LoeffelGewichtNachholen}: nie KI synchron im Speicherpfad, nur mit angemeldetem Nutzer und
 * aktiver Team-KI, nie auf einer sync-Queue, Job nach dem Commit.
 */
final class DerivatAusbeuteNachholen
{
    /** @return list<int> GP-IDs der Zweck-Derivate dieses Rezepts ohne Ausbeute */
    public static function fehlende(FoodAlchemistRecipe $recipe): array
    {
        $ids = $recipe->ingredients()->whereNotNull('gp_id')->pluck('gp_id')->unique()->all();
        if ($ids === []) {
            return [];
        }

        return FoodAlchemistGp::query()->whereIn('id', $ids)->where('is_derivat', true)->whereNotNull('derivat_von_gp_id')->get()
            ->filter(fn (FoodAlchemistGp $gp) => DerivatAusbeute::fehlt($gp))
            ->map(fn (FoodAlchemistGp $gp) => (int) $gp->id)->values()->all();
    }

    public static function ausloesen(Team $team, FoodAlchemistRecipe $recipe): void
    {
        $gpIds = self::fehlende($recipe);
        $user = Auth::user();
        if ($gpIds === [] || $user === null || ! app(TeamSettingsService::class)->kiAktiv($team)
            || app('queue')->connection() instanceof \Illuminate\Queue\SyncQueue) {
            return;
        }
        DerivatAusbeuteNachholenJob::dispatch((int) $team->id, (int) $user->id, (int) $recipe->id, $gpIds,
            extern: app(\Platform\FoodAlchemist\Services\Ai\KiExternModus::class)->fuerDispatch())
            ->onQueue(Warteschlange::anreichern())
            ->afterCommit();
    }
}
