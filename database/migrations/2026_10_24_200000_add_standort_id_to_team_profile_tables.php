<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §9 (Schritt 9, PR 2): Standortprofil. Dieselben drei Profil-Tabellen tragen auch das Profil eines KUNDEN-STANDORTS
 * (foodalchemist_projekt_standorte, Agentur-Akte im Master-Team): `standort_id` = 0 heißt Teamprofil des Teams (wie bisher),
 * > 0 ist der Kunden-Standort. Bewusst NOT NULL DEFAULT 0 statt NULL: ein Unique-Index über (team_id, standort_id) lässt bei NULL
 * Dubletten durch (MySQL), mit 0 bleibt „ein Profil je Team“ vom Index garantiert. Bestandszeilen bekommen 0 und ändern sich nicht.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['foodalchemist_team_profiles', 'foodalchemist_team_profile_equipment', 'foodalchemist_team_profile_areas'] as $tabelle) {
            Schema::table($tabelle, function (Blueprint $table) {
                $table->unsignedBigInteger('standort_id')->default(0)->after('team_id')->comment('0 = Teamprofil, sonst Kunden-Standort (projekt_standorte)');
            });
        }
        Schema::table('foodalchemist_team_profiles', function (Blueprint $table) {
            $table->dropUnique(['team_id']);
            $table->unique(['team_id', 'standort_id'], 'fa_tp_team_standort_uq');
        });
        Schema::table('foodalchemist_team_profile_equipment', function (Blueprint $table) {
            $table->dropUnique('fa_tpe_team_equipment_uq');
            $table->unique(['team_id', 'standort_id', 'equipment_id'], 'fa_tpe_team_standort_equipment_uq');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_profile_equipment', function (Blueprint $table) {
            $table->dropUnique('fa_tpe_team_standort_equipment_uq');
        });
        Schema::table('foodalchemist_team_profiles', function (Blueprint $table) {
            $table->dropUnique('fa_tp_team_standort_uq');
        });
        // Kunden-Standort-Zeilen würden die alten Unique-Indizes verletzen
        foreach (['foodalchemist_team_profiles', 'foodalchemist_team_profile_equipment', 'foodalchemist_team_profile_areas'] as $tabelle) {
            DB::table($tabelle)->where('standort_id', '>', 0)->delete();
        }
        Schema::table('foodalchemist_team_profiles', function (Blueprint $table) {
            $table->unique('team_id');
        });
        Schema::table('foodalchemist_team_profile_equipment', function (Blueprint $table) {
            $table->unique(['team_id', 'equipment_id'], 'fa_tpe_team_equipment_uq');
        });
        foreach (['foodalchemist_team_profiles', 'foodalchemist_team_profile_equipment', 'foodalchemist_team_profile_areas'] as $tabelle) {
            Schema::table($tabelle, fn (Blueprint $table) => $table->dropColumn('standort_id'));
        }
    }
};
