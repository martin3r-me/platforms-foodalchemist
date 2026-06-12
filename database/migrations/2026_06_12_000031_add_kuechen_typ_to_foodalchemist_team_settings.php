<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7-07 / D-5 §4.3-Nachtrag: Küchen-Profil als TEAM-Einstellung (Ist: globales
 * app_settings.kuechen_typ) — Soft-Default-Schicht des Generators; explizite
 * Hooks haben Vorrang. Multi-Tenancy-Gewinn: jeder Caterer sein Typ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->string('kuechen_typ', 32)->nullable();            // restaurant|grosskueche|catering|hotel|boutique_patisserie
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->dropColumn('kuechen_typ');
        });
    }
};
