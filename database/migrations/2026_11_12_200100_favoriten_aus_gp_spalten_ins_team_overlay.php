<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Support\MasterTeam;
use Symfony\Component\Uid\UuidV7;

/**
 * Spec 85 FAV-1: die bisherigen Favoriten-Spalten am GP (`is_favorite`, `favorite_rank`) werden die Liste ihres BESITZERS im Team-Overlay —
 * globale GPs (team_id NULL) beim Master-Team, team-eigene beim Besitzer-Team. Danach liest sie niemand mehr (FAV-2), Drop in FAV-3.
 * Idempotent (vorhandene Zeilen bleiben). Gibt es globale Favoriten, aber kein Master-Team, bricht die Migration ab statt sie still zu verlieren.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_gps', 'is_favorite')) {
            return;   // FAV-3 hat die Spalten schon entfernt
        }
        $favoriten = DB::table('foodalchemist_gps')->where('is_favorite', true)->whereNull('deleted_at')
            ->get(['id', 'team_id', 'favorite_rank']);
        if ($favoriten->isEmpty()) {
            return;
        }
        $master = MasterTeam::id();
        if ($master === null && $favoriten->contains(fn ($g) => $g->team_id === null)) {
            throw new RuntimeException('Spec 85 FAV-1: globale Favoriten vorhanden, aber kein Master-Team konfiguriert — erst foodalchemist.master_team_id setzen.');
        }
        $vorhanden = DB::table('foodalchemist_team_favoriten')->get(['team_id', 'gp_id'])
            ->mapWithKeys(fn ($r) => [$r->team_id . ':' . $r->gp_id => true]);
        $neu = [];
        foreach ($favoriten as $g) {
            $team = $g->team_id ?? $master;
            if (isset($vorhanden[$team . ':' . $g->id])) {
                continue;
            }
            $neu[] = ['uuid' => (string) UuidV7::generate(), 'team_id' => (int) $team, 'gp_id' => (int) $g->id,
                'rang' => $g->favorite_rank, 'ausgeschlossen' => false, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($neu, 500) as $teil) {
            DB::table('foodalchemist_team_favoriten')->insert($teil);
        }
    }

    public function down(): void
    {
        // Rückweg: nichts zurückzuschreiben (die Spalten entfernt FAV-3, das Overlay bleibt).
    }
};
