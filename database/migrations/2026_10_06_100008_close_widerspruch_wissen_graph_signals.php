<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 60 · P8: das Signal `widerspruch_wissen_graph` ist abgelöst. Es verglich Pairing-Dokumente
 * mit dem Anker-Graphen; diese Dokumente gibt es nicht mehr (demo 2026-10-06: 0 Dokumente der
 * Kategorie `pairing`, 385 offene Signale je Team, seit 07.08. unverändert). Der Detektor ist
 * entfernt — ohne diese Migration blieben die Meldungen für immer offen.
 *
 * Geschlossen wie ein automatisch gemessener Befund (SignalService::schliesseVerschwundene):
 * Status `erledigt`, Grund im payload, Titel und Beschreibung bleiben als Historie.
 * Freigabe zum Schließen: Dominique, 2026-10-06.
 */
return new class extends Migration
{
    public function up(): void
    {
        $grund = 'Spec 60: Signal abgelöst (Pairing-Dokumente entfernt, neue Pairing-Signale) — automatisch geschlossen';
        $jetzt = now();

        DB::table('foodalchemist_signals')->where('type', 'widerspruch_wissen_graph')->where('status', 'offen')
            ->orderBy('id')->chunkById(500, function ($zeilen) use ($grund, $jetzt) {
                foreach ($zeilen as $z) {
                    $payload = json_decode((string) ($z->payload ?? ''), true);
                    $payload = (is_array($payload) ? $payload : []) + [
                        'auto_geschlossen' => $grund,
                        'auto_geschlossen_am' => $jetzt->toIso8601String(),
                    ];
                    DB::table('foodalchemist_signals')->where('id', $z->id)->update([
                        'status' => 'erledigt', 'erledigt_at' => $jetzt, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                        'updated_at' => $jetzt,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // bewusst leer: ein wieder geöffnetes Alt-Signal hätte keinen Detektor mehr, der es schließt
    }
};
