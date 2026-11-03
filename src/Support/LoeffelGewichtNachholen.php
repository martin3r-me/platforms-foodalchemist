<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\LoeffelGewichtNachholenJob;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\GpFormService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Löffel beim Import (Dominique 03.11.: „mir ging es darum, dass beim Import so etwas gut umgerechnet werden kann“): steht im
 * importierten Rezept „1 TL Zimt“ und hat Zimt noch kein TL-Gewicht, schätzt die KI es einmal im Hintergrund (Herkunft ki,
 * {@see GpFormService::estimateKi}) und das Rezept wird neu gerechnet. Bis dahin gilt der Rückfall aufs Vokabular (5 ml = 5 g).
 *
 * Wie {@see GpAromaNachholen}: nie KI synchron im Speicherpfad, nur mit angemeldetem Nutzer und aktiver Team-KI, nie auf einer
 * sync-Queue, Job nach dem Commit. Jedes GP wird nur geschätzt, solange ihm die Form fehlt — weitere Rezepte nutzen denselben Wert.
 */
final class LoeffelGewichtNachholen
{
    /** @return list<int> GP-IDs mit Löffel-Zeile ohne hinterlegtes Löffel-Gewicht */
    public static function fehlende(FoodAlchemistRecipe $recipe): array
    {
        return DB::table('foodalchemist_recipe_ingredients as z')
            ->join('foodalchemist_vocab_units as e', 'e.id', '=', 'z.unit_vocab_id')
            ->where('z.recipe_id', $recipe->id)->whereNull('z.deleted_at')->whereNotNull('z.gp_id')
            ->whereIn('e.slug', GpFormService::LOEFFEL_SLUGS)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_gp_forms as f')
                ->whereColumn('f.gp_id', 'z.gp_id')->whereColumn('f.form_slug', 'e.slug')->whereNull('f.deleted_at'))
            ->distinct()->pluck('z.gp_id')->map(fn ($v) => (int) $v)->values()->all();
    }

    public static function ausloesen(Team $team, FoodAlchemistRecipe $recipe): void
    {
        $gpIds = self::fehlende($recipe);
        $user = Auth::user();
        if ($gpIds === [] || $user === null || ! app(TeamSettingsService::class)->kiAktiv($team)
            || app('queue')->connection() instanceof \Illuminate\Queue\SyncQueue) {
            return;
        }
        LoeffelGewichtNachholenJob::dispatch((int) $team->id, (int) $user->id, (int) $recipe->id, $gpIds,
            extern: app(\Platform\FoodAlchemist\Services\Ai\KiExternModus::class)->fuerDispatch())
            ->onQueue(Warteschlange::anreichern())
            ->afterCommit();
    }
}
