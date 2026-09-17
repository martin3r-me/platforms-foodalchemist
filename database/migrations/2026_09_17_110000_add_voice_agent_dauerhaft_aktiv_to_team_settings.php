<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 53 / Paket F, Stufe 2: „Sprachbefehl dauerhaft aktivieren" — pro Team AN/AUS (kein
 * Per-User-Setting-Mechanismus im FA-Modul vorhanden, siehe Recherche 2026-09-17: nur
 * team-scoped GpLaPreference + eine user+team „active outlet"-Tabelle, kein generischer
 * Store). Client-seitig spiegelt localStorage den Wert für sofortige Anzeige ohne Re-Render.
 * Default false = heutiges Verhalten (Sidebar-Knopf, kein schwebendes Element).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'voice_agent_dauerhaft_aktiv')) {
                $table->boolean('voice_agent_dauerhaft_aktiv')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->dropColumn('voice_agent_dauerhaft_aktiv');
        });
    }
};
