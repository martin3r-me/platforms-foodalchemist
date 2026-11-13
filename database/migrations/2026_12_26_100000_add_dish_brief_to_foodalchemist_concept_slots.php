<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gericht-Vorgabe je Concept-Position (Dominique 13.11.): „das sind meine Gerichte, baue genau diese". Eine LEERE
 * Position trägt den gewünschten Gerichtstitel (`dish_brief`) und eine optionale Notiz (`dish_brief_note`). Der
 * Fan-out des Concepts macht daraus 1:1 die Skizze, statt frei zu erfinden ({@see \Platform\FoodAlchemist\Services\ConceptVorgabenService}).
 *
 * Eigene Spalten, nicht `title`: der Titel ist das Anzeige-Label der Position und wird bei Hand-Anlage mit dem
 * Gangnamen gefüllt — als Vorgabe gelesen, entstünde ein Gericht namens „Vorspeise". Additiv, kein Backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_concept_slots', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'dish_brief')) {
                $table->string('dish_brief')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'dish_brief_note')) {
                $table->text('dish_brief_note')->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['dish_brief', 'dish_brief_note'] as $spalte) {
            if (Schema::hasColumn('foodalchemist_concept_slots', $spalte)) {
                Schema::table('foodalchemist_concept_slots', fn (Blueprint $table) => $table->dropColumn($spalte));
            }
        }
    }
};
