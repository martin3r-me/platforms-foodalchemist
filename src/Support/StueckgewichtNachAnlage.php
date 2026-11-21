<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\StueckgewichtNachAnlageJob;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Stückgewicht bei loser Ware (Dominique 11.11.: „Das Grundprodukt hat das Stückgewicht und kann dann umgerechnet werden,
 * Karton wird kein Stück … geht eigentlich nur um lose Ware“). Die Umrechnung „4 Stk Aprikose“ → Gramm → EK läuft schon über das
 * Stückgewicht am GP (piece_default_g bzw. Form „stk“). Es fehlte nur bei einem GP, das aus einem LOSE pro kg/g verkauften Artikel
 * angelegt wurde: der Artikel trägt kein Stückgewicht, also blieb das GP ohne, und jede Stück-Zeile wog 0 g.
 *
 * Darum: Wird an ein GP sein ERSTER Artikel gehängt (= gerade aus dem Artikel angelegt) und ist das lose Ware (Menge in kg/g,
 * Bestelleinheit kg/g oder leer — kein Karton, kein Stück-Artikel), schätzt die KI einmal im Hintergrund die Zähl-Formen
 * ({@see \Platform\FoodAlchemist\Services\GpFormService::estimateKi}, gp.zaehl_einheiten; nicht stückige Ware wie Mehl bekommt
 * schlicht kein „stk“). Gilt nur, solange das GP noch kein Stückgewicht hat; ein gepflegter Wert wird nie überschrieben.
 *
 * Wie {@see GpAromaNachholen}: nie KI synchron im Speicherpfad, nur mit angemeldetem Nutzer und aktiver Team-KI (Importe und
 * Befehle ohne Nutzer kosten nichts), nie auf einer sync-Queue, verzögert, nach dem Commit.
 */
final class StueckgewichtNachAnlage
{
    public const VERZOEGERUNG_SEKUNDEN = 60;

    /** Bestelleinheiten loser Ware: Menge und Bestellung in Gewicht (leer = Artikel ohne eigene Bestelleinheit). */
    public const LOSE_BESTELLEINHEITEN = ['', 'KG', 'G', 'KGM', 'GRM'];

    public static function ausloesen(Team $team, FoodAlchemistGp $gp, int $laId): void
    {
        $user = Auth::user();
        if ($user === null || ! self::braucht($gp, $laId) || ! app(TeamSettingsService::class)->kiAktiv($team)
            || app('queue')->connection() instanceof \Illuminate\Queue\SyncQueue) {
            return;
        }
        StueckgewichtNachAnlageJob::dispatch((int) $team->id, (int) $user->id, (int) $gp->id,
            extern: app(\Platform\FoodAlchemist\Services\Ai\KiExternModus::class)->fuerDispatch())
            ->onQueue(Warteschlange::anreichern())
            ->delay(now()->addSeconds(self::VERZOEGERUNG_SEKUNDEN))
            ->afterCommit();
    }

    /** Erster Artikel des GP, lose Ware, GP noch ohne Stückgewicht? */
    public static function braucht(FoodAlchemistGp $gp, int $laId): bool
    {
        if (! self::ohneStueckgewicht((int) $gp->id)) {
            return false;
        }
        $artikel = DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $gp->id)->whereNull('deleted_at')->count();
        if ($artikel !== 1) {
            return false;                                                   // nur beim Anlegen aus dem Artikel, nicht bei jedem weiteren
        }
        $la = DB::table('foodalchemist_supplier_items')->where('id', $laId)->first(['unit_code', 'ordering_unit']);

        return $la !== null
            && in_array(mb_strtolower((string) $la->unit_code), ['kg', 'g'], true)
            && in_array(mb_strtoupper(trim((string) $la->ordering_unit)), self::LOSE_BESTELLEINHEITEN, true);
    }

    public static function ohneStueckgewicht(int $gpId): bool
    {
        return DB::table('foodalchemist_gps')->where('id', $gpId)->whereNull('piece_default_g')->exists()
            && ! DB::table('foodalchemist_gp_forms')->where('gp_id', $gpId)->where('form_slug', 'stk')->whereNull('deleted_at')->exists();
    }
}
