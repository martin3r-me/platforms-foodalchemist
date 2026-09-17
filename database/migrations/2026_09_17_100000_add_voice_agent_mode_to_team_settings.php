<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 53 / Paket F: Agenten-Modus des Sprachbefehls (fragen|auto_sicher|nur_lesen) — analog
 * zum Claude-Code-Mode-Switcher. NULL = Default `fragen` (heutiges GL-07-Verhalten unverändert).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'voice_agent_mode')) {
                $table->string('voice_agent_mode', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->dropColumn('voice_agent_mode');
        });
    }
};
