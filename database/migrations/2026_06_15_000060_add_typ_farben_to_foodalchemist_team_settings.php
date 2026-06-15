<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 (UI-Vereinheitlichung): pro-Typ-Farben für GP / Basisrezept / Gericht,
 * teamweit konfigurierbar. Eine JSON-Spalte {gp, basisrezept, gericht} mit Hex-Werten;
 * leer/null → Defaults aus TeamSettingsService::typFarben(). Additiv, rückwärtskompatibel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'typ_farben')) {
                $table->json('typ_farben')->nullable();   // {gp:'#7c3aed', basisrezept:'#0d9488', gericht:'#d97706'}
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_team_settings', 'typ_farben')) {
                $table->dropColumn('typ_farben');
            }
        });
    }
};
