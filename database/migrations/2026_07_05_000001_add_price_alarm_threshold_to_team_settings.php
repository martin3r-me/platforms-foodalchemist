<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R2.1 (Preis-Alarm + Marge-Impact): team-konfigurierbare Schwelle, ab welcher
 * relativer Änderung eines Lead-LA-Preises ein Signal (SignalTyp::PreisSprungMargeImpact)
 * erzeugt wird. NULL = Code-Default (TeamSettingsService::PREIS_ALARM_SCHWELLE_DEFAULT).
 * Engine-agnostisch (kein ->after / FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'price_alarm_threshold_pct')) {
                $table->decimal('price_alarm_threshold_pct', 5, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->dropColumn('price_alarm_threshold_pct');
        });
    }
};
