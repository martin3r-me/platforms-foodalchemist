<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M14-01 / Doc 15 §M14: Speiseplan — zweite Ausgabeform neben dem Foodbook.
 * Dieselben Bausteine über eine ZEITACHSE (Tag × Mahlzeit, Wochen-Zyklus) statt
 * nach Anlass. Slot = Zeitpunkt, Inhalt = austauschbarer Baustein:
 *   Eintrag belegt (woche × wochentag × mahlzeit) mit GENAU EINEM:
 *     concept_id (ganzes Concept) ODER paket_id ODER vk_recipe_id (Gericht) — D-PLAN-1.
 *
 * `zyklus_wochen` = rotierender Plan (z. B. 4-Wochen). `min_abstand_tage` = Wiederholungs-
 * regel (0 = keine): dasselbe Gericht/Concept nicht in engerem Abstand (GV-Anforderung).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_speiseplaene', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('name');
            $table->date('start_datum')->nullable();
            $table->unsignedInteger('zyklus_wochen')->default(1);
            $table->unsignedInteger('min_abstand_tage')->default(0);  // Wiederholungsregel (0 = aus)
            $table->string('status', 16)->default('draft');
            $table->text('beschreibung')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('foodalchemist_speiseplan_eintraege', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('speiseplan_id')->constrained('foodalchemist_speiseplaene')->cascadeOnDelete();
            $table->unsignedInteger('woche')->default(1);             // 1..zyklus_wochen
            $table->unsignedTinyInteger('wochentag')->default(1);     // 1=Mo … 7=So
            $table->string('mahlzeit', 24)->default('mittag');        // fruehstueck|mittag|abend|snack (frei)
            $table->integer('position')->default(0);
            // Belegung: genau EINES (Service-validiert)
            $table->foreignId('concept_id')->nullable()->constrained('foodalchemist_concepts')->nullOnDelete();
            $table->foreignId('paket_id')->nullable()->constrained('foodalchemist_pakete')->nullOnDelete();
            $table->foreignId('vk_recipe_id')->nullable()->constrained('foodalchemist_recipes')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['speiseplan_id', 'woche', 'wochentag', 'mahlzeit']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_speiseplan_eintraege');
        Schema::dropIfExists('foodalchemist_speiseplaene');
    }
};
