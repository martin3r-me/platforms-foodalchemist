<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 53 / Paket F (3): Sprachausgabe (TTS) im Konversations-Modus — pro Team AN/AUS
 * + Stimmen-Wahl. Default AUS = heutiges Verhalten (nur Text-Antwort, kein Audio).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'voice_tts_vorlesen')) {
                $table->boolean('voice_tts_vorlesen')->default(false);
            }
            if (! Schema::hasColumn('foodalchemist_team_settings', 'voice_tts_stimme')) {
                $table->string('voice_tts_stimme', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->dropColumn(['voice_tts_vorlesen', 'voice_tts_stimme']);
        });
    }
};
