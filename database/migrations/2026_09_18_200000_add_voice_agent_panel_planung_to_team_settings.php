<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 55: neuer, eigener Schlüssel für „Agenten-Panel in der Planungs-Leitstelle anzeigen".
 * NICHT `voice_agent_dauerhaft_aktiv` wiederverwendet — der alte Schlüssel trägt echte
 * Team-Entscheidungen zum alten Feature (schwebendes Element auf jeder Seite, das es mit
 * Spec 55 nicht mehr gibt); ein Backfill/Umdeuten hätte diese Entscheidungen unter der Hand
 * gegen eine andere Bedeutung getauscht. Nullable, `null` = Default AN (siehe
 * TeamSettingsService::voiceAgentPanelPlanung()) — kein Team hat je etwas zum NEUEN Panel
 * entschieden, ein DB-Default `true` hätte das bei einer künftigen Boolean-Cast-Änderung
 * verdeckt; `null` bleibt ehrlich „nie gesetzt".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'voice_agent_panel_planung')) {
                $table->boolean('voice_agent_panel_planung')->nullable()->default(null);
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_team_settings', 'voice_agent_panel_planung')) {
                $table->dropColumn('voice_agent_panel_planung');
            }
        });
    }
};
