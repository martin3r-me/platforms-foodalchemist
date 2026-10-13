<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §13 B4: plattformweite FA-Einstellungen ohne team_id (zuerst: das Master-Team).
 * Schlüssel/Wert. ENV `FOODALCHEMIST_MASTER_TEAM_ID` bleibt Fallback, solange hier kein Wert steht.
 * Nur Plattform-Admin schreibt (MasterTeamService), jede Änderung geht ins activity-log (PlattformProtokoll).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_plattform_einstellungen', function (Blueprint $table) {
            $table->id();
            $table->string('schluessel', 64)->unique();
            $table->text('wert')->nullable();
            $table->unsignedBigInteger('gesetzt_von')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_plattform_einstellungen');
    }
};
