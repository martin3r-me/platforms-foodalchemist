<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 (Settings-Audit 2026-06-15): WG-spezifische Lead-LA-Strategie.
 * `lead_la_strategie_per_wg` = JSON {wg_code: strategie}. Greift im Resolver VOR
 * der globalen Team-Strategie — „für Fleisch Stamm, für Gemüse günstigster". Die
 * Stamm-Matrix war schon WG-gekoppelt, die Strategie-Wahl bisher nur global (V-27).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'lead_la_strategie_per_wg')) {
                $table->json('lead_la_strategie_per_wg')->nullable()->after('lead_la_strategie');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->dropColumn('lead_la_strategie_per_wg');
        });
    }
};
