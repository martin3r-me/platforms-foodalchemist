<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 85 FAV-3: die alten Favoriten-Spalten am GP (`is_favorite`, `favorite_rank`) fallen weg — Favoriten stehen seit FAV-1 im Team-Overlay
 * (`foodalchemist_team_favoriten`), FAV-2 sichert, dass sie niemand mehr liest. Riegel: steht noch ein Spalten-Favorit OHNE Overlay-Zeile
 * seines Besitzers (global → Master), bricht die Migration ab, statt ihn still zu verlieren. Index auf der Spalte zuerst (SQLite verweigert sonst).
 */
return new class extends Migration
{
    private const SPALTEN = ['is_favorite', 'favorite_rank'];

    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_gps', 'is_favorite')) {
            return;
        }
        $master = MasterTeam::id();
        $offen = DB::table('foodalchemist_gps AS g')->where('g.is_favorite', true)->whereNull('g.deleted_at')
            ->whereNotExists(fn ($q) => $q->from('foodalchemist_team_favoriten AS f')->whereColumn('f.gp_id', 'g.id')
                ->whereRaw('f.team_id = COALESCE(g.team_id, ?)', [$master ?? 0]))
            ->count();
        if ($offen > 0) {
            throw new RuntimeException("Spec 85 FAV-3: {$offen} Favoriten stehen nur in der GP-Spalte, nicht im Team-Overlay — erst Migration 2026_11_12_200100 laufen lassen.");
        }
        foreach (Schema::getIndexes('foodalchemist_gps') as $ix) {
            if (! ($ix['primary'] ?? false) && array_intersect($ix['columns'], self::SPALTEN) !== []) {
                Schema::table('foodalchemist_gps', fn (Blueprint $t) => $t->dropIndex($ix['name']));
            }
        }
        Schema::table('foodalchemist_gps', fn (Blueprint $t) => $t->dropColumn(array_values(array_filter(self::SPALTEN,
            fn ($s) => Schema::hasColumn('foodalchemist_gps', $s)))));
    }

    public function down(): void
    {
        // Nur das Schema kommt zurück; die Favoriten leben im Overlay und werden nicht zurückgeschrieben.
        Schema::table('foodalchemist_gps', function (Blueprint $t) {
            $t->boolean('is_favorite')->default(false)->index();
            $t->unsignedInteger('favorite_rank')->nullable();
        });
    }
};
