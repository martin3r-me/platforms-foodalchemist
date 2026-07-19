<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R2.5 — Leitplanken für das Saison-Auto-Pricing (intern-vorschlagend):
 * Margen-Zielband (min/max %) + max. VK-Delta je Freigabe + Mindestmarge.
 * Verlässt die LIVE-Marge das Band, entsteht ein „VK-Anpassung empfohlen"-Signal;
 * die Freigabe selbst bleibt menschlich (Snapshot). Alle nullable → Team-Wert vor Code-Default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            foreach ([
                'season_margin_band_min_pct',
                'season_margin_band_max_pct',
                'max_vk_delta_pct',
                'min_margin_pct',
            ] as $col) {
                if (! Schema::hasColumn('foodalchemist_team_settings', $col)) {
                    $table->decimal($col, 5, 2)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            foreach ([
                'season_margin_band_min_pct',
                'season_margin_band_max_pct',
                'max_vk_delta_pct',
                'min_margin_pct',
            ] as $col) {
                if (Schema::hasColumn('foodalchemist_team_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
