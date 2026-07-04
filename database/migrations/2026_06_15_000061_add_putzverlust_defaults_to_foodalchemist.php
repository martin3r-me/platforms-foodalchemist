<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (Settings-Audit 2026-06-15): Putzverlust-Defaults — Pendant zu den
 * bereits vorhandenen Garverlust-Defaults. Schaltet zusammen mit der Recompute-
 * Kaskade (Zutat → GP-Default → Team-WG-Default → 0) den „Verlust ist hart"-
 * Blindgänger scharf. GL-02.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_gps', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_gps', 'trimming_loss_default_pct')) {
                $table->decimal('trimming_loss_default_pct', 5, 2)->nullable()->after('cooking_loss_default_pct');
            }
        });

        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'trimming_loss_defaults')) {
                $table->json('trimming_loss_defaults')->nullable()->after('cooking_loss_defaults');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_gps', function (Blueprint $table) {
            $table->dropColumn('trimming_loss_default_pct');
        });
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->dropColumn('trimming_loss_defaults');
        });
    }
};
