<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §6 / Schritt 5: Teamprofil — was die Küche kann (Fakten, keine DNA).
 *
 * Strikt team-eigen (kein Erben). Dreiwertig: Eine Zeile in team_profile_equipment sagt vorhanden oder
 * nicht_vorhanden, KEINE Zeile heißt unbekannt (Spec 82 §12 E). Bewusst KEIN Fremdschlüssel auf equipment_id:
 * ImportSliceCommand leert die Vokabular-Tabellen per delete, ein cascadeOnDelete würde Profile mitlöschen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_team_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->unique();
            $table->unsignedInteger('max_pax_parallel')->nullable()->comment('max. Gäste parallel zum Tagesgeschäft');
            $table->string('convenience_grad', 24)->nullable()->comment('from_scratch | teil_convenience | voll_convenience');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('foodalchemist_team_profile_equipment', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('equipment_id')->comment('Vokabular-ID, bewusst ohne FK (Import leert das Vokabular)');
            $table->string('status', 16)->comment('vorhanden | nicht_vorhanden; keine Zeile = unbekannt');
            $table->unsignedSmallInteger('anzahl')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'equipment_id'], 'fa_tpe_team_equipment_uq');
        });

        Schema::create('foodalchemist_team_profile_areas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->string('name', 120);
            $table->unsignedInteger('max_pax')->nullable();
            $table->unsignedTinyInteger('saison_von')->nullable()->comment('Monat 1–12');
            $table->unsignedTinyInteger('saison_bis')->nullable()->comment('Monat 1–12');
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_team_profile_areas');
        Schema::dropIfExists('foodalchemist_team_profile_equipment');
        Schema::dropIfExists('foodalchemist_team_profiles');
    }
};
