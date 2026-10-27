<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\GpAromaNachholenJob;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpAromaService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * 27.10.2026 (Dominique/Kurator): JEDER Anlageweg eines GP zieht Kern-Anker und Geschmacksprofil nach — bisher nur der Rezept-Mint.
 * Ausgelöst vom Observer `created` (alle Eloquent-Wege: GP-Dialog, gps.POST, LA-First, GP-Abgleich, Hardstop, Marken-Split) und
 * vom GP-Paket-Import (dort nur der kostenlose Teil).
 *
 *  1. SOFORT, ohne KI und ohne Nutzer: Anker und Profil von einigen Geschwistern ({@see GpAromaService::geschwisterNachziehen()}).
 *  2. Fehlt danach noch etwas: Job mit KI — nur mit angemeldetem Nutzer und aktiver Team-KI (wie {@see SensorikNachholen}: Importe
 *     und Befehle ohne Nutzer kosten nichts; für sie ist `foodalchemist:gp-aroma-backfill` da). Verzögert, je GP eindeutig,
 *     nur mit echtem Worker (nie auf einer sync-Queue).
 */
final class GpAromaNachholen
{
    public const VERZOEGERUNG_SEKUNDEN = 90;

    public static function ausloesen(FoodAlchemistGp $gp): void
    {
        $aroma = app(GpAromaService::class);
        if (! $aroma->anreicherbar($gp)) {
            return;
        }
        $aroma->geschwisterNachziehen([(int) $gp->id]);

        $user = Auth::user();
        $team = $user?->currentTeamRelation;
        if ($user === null || ! $team instanceof Team || ! self::braucht((int) $gp->id) || ! app(TeamSettingsService::class)->kiAktiv($team)) {
            return;
        }
        // Nie KI synchron im Speicherpfad: auf einer sync-Queue (demo, Test-Harness) liefe der Job sofort IN der Anlage — Sekunden
        // Wartezeit je GP, bei LA-First-Massenanlagen Minuten. Dort bleiben Knöpfe, „Alles anreichern" und der Backfill.
        if (app('queue')->connection() instanceof \Illuminate\Queue\SyncQueue) {
            return;
        }
        GpAromaNachholenJob::dispatch((int) $team->id, (int) $user->id, (int) $gp->id)
            ->onQueue(Warteschlange::anreichern())
            ->delay(now()->addSeconds(self::VERZOEGERUNG_SEKUNDEN))
            ->afterCommit();
    }

    /** @return list<string> die KI-Schritte, die dieses GP noch braucht (anker, sensorik) */
    public static function schritte(int $gpId): array
    {
        $aroma = app(GpAromaService::class);

        return array_values(array_filter([
            $aroma->hatAnker($gpId) ? null : 'anker',
            $aroma->profilFehlt($gpId) ? 'sensorik' : null,
        ]));
    }

    public static function braucht(int $gpId): bool
    {
        return self::schritte($gpId) !== [];
    }
}
