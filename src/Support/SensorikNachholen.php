<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\SensorikNachholenJob;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Spec 60 (Dominique 16.10.): Neue Basisrezepte bekommen ihre KI-Sensorik (Geschmack + Texturen)
 * automatisch — bisher nur über Generator/„Alles anreichern"/Knopf/MCP, von Hand angelegte nie.
 *
 * Ausgelöst NUR aus dem Speicherpfad von RecipeService (anlegen, ändern, Zutaten) mit angemeldetem
 * Nutzer. Bulk-Recompute, Importe und Befehle ohne Nutzer lösen nichts aus (keine Massen-KI-Kosten).
 * Bedingungen: Basisrezept (kein Gericht/VK), Zubereitung + Zutaten vorhanden, noch nie bewertet,
 * KI des Teams an. Der Job ist je Rezept eindeutig und prüft beim Laufen erneut.
 */
final class SensorikNachholen
{
    /** Wartezeit, damit schnelles Weiterbearbeiten erst fertig ist. */
    public const VERZOEGERUNG_SEKUNDEN = 90;

    public static function ausloesen(Team $team, int $recipeId): void
    {
        $user = Auth::user();
        if ($user === null || ! self::braucht($recipeId) || ! app(TeamSettingsService::class)->kiAktiv($team)) {
            return;
        }
        SensorikNachholenJob::dispatch((int) $team->id, (int) $user->id, $recipeId)
            ->onQueue(Warteschlange::anreichern())
            ->delay(now()->addSeconds(self::VERZOEGERUNG_SEKUNDEN))
            ->afterCommit();
    }

    /** Basisrezept mit Zubereitung und Zutaten, noch ohne Sensorik? */
    public static function braucht(int $recipeId): bool
    {
        $r = DB::table('foodalchemist_recipes')->where('id', $recipeId)->whereNull('deleted_at')
            ->first(['is_sales_recipe', 'preparation']);
        if ($r === null || (bool) $r->is_sales_recipe || trim((string) $r->preparation) === '') {
            return false;
        }
        if (DB::table('foodalchemist_recipe_taste_vectors')->where('recipe_id', $recipeId)->exists()) {
            return false;
        }

        return DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $recipeId)->whereNull('deleted_at')->exists();
    }
}
