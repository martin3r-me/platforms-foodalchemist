<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M14-01 / Doc 15 §M14: Speiseplan — zweite Ausgabeform neben dem Foodbook.
 * Dieselben Bausteine über eine ZEITACHSE (Tag × Mahlzeit, Wochen-Zyklus) statt
 * nach Anlass. Slot = Zeitpunkt, Inhalt = austauschbarer Baustein:
 *   Eintrag belegt (woche × wochentag × mahlzeit) mit GENAU EINEM:
 *     concept_id (ganzes Concept) ODER package_id ODER sales_recipe_id (Gericht) — D-PLAN-1.
 *
 * `zyklus_wochen` = rotierender Plan (z. B. 4-Wochen). `min_abstand_tage` = Wiederholungs-
 * regel (0 = keine): dasselbe Gericht/Concept nicht in engerem Abstand (GV-Anforderung).
 *
 * Idempotent: `hasTable`/`hasIndex` schützen vor halb-gelaufener Migration (MySQL hat kein
 * transaktionales DDL — der gescheiterte ALTER nach erfolgreichem CREATE hinterlässt die Tabelle).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_menu_plans')) {
            Schema::create('foodalchemist_menu_plans', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->string('name');
                $table->date('start_date')->nullable();
                $table->unsignedInteger('cycle_weeks')->default(1);
                $table->unsignedInteger('min_abstand_tage')->default(0);  // Wiederholungsregel (0 = aus)
                $table->string('status', 16)->default('draft');
                $table->text('description')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('foodalchemist_menu_plan_entries')) {
            Schema::create('foodalchemist_menu_plan_entries', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->foreignId('menu_plan_id')->constrained('foodalchemist_menu_plans')->cascadeOnDelete();
                $table->unsignedInteger('week')->default(1);              // 1..cycle_weeks
                $table->unsignedTinyInteger('weekday')->default(1);       // 1=Mo … 7=So
                $table->string('meal', 24)->default('mittag');            // fruehstueck|mittag|abend|snack (frei)
                $table->integer('position')->default(0);
                // Belegung: genau EINES (Service-validiert)
                $table->foreignId('concept_id')->nullable()->constrained('foodalchemist_concepts')->nullOnDelete();
                $table->foreignId('package_id')->nullable()->constrained('foodalchemist_packages')->nullOnDelete();
                $table->foreignId('sales_recipe_id')->nullable()->constrained('foodalchemist_recipes')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Index separat: deckt sowohl Frisch-Anlage als auch halb-gelaufenen Vorlauf ab
        // (Tabelle vom failed run da, aber ALTER für Index war es, was scheiterte — Auto-Name
        // mit 4 Spalten wurde 79 Zeichen lang, MySQL-Limit 64).
        if (! $this->hasIndex('foodalchemist_menu_plan_entries', 'fa_speiseplan_eintr_plan_woche_tag_mahlz_idx')) {
            Schema::table('foodalchemist_menu_plan_entries', function (Blueprint $table) {
                $table->index(
                    ['menu_plan_id', 'week', 'weekday', 'meal'],
                    'fa_speiseplan_eintr_plan_woche_tag_mahlz_idx',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_menu_plan_entries');
        Schema::dropIfExists('foodalchemist_menu_plans');
    }

    private function hasIndex(string $tabelle, string $indexName): bool
    {
        return collect(Schema::getIndexes($tabelle))
            ->pluck('name')
            ->contains($indexName);
    }
};
