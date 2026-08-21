<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Realistische Produktionszeit — passive Gar-/Standzeit als eigene Zeit-Kategorie.
 *
 *  - foodalchemist_recipes.standzeit_min            : passive Gar-/Standzeit je Lauf (Köcheln,
 *    Ziehen, Kühlen). Zählt zur DURCHLAUFZEIT, bindet aber KEINEN Posten/keine Kapazität. Bewusst
 *    1× je Lauf (nicht je Batch — mehrere Töpfe köcheln unbeaufsichtigt/überlappend). NULL/0 = keine.
 *  - foodalchemist_production_order_lines.standzeit_min : Snapshot analog `arbeitszeit_min`, friert
 *    bei der Explosion ein.
 *
 * Ergänzt Stufe 3 P3.2: aktive Belegzeit (`arbeitszeit_min`) + Standzeit = Durchlaufzeit.
 * Defaults (NULL) verändern das heutige Verhalten nicht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_recipes', 'standzeit_min')) {
                $table->unsignedInteger('standzeit_min')->nullable()
                    ->comment('Passive Gar-/Standzeit je Lauf (Köcheln/Ziehen/Kühlen). Zählt zur Durchlaufzeit, bindet keinen Posten. NULL/0 = keine.');
            }
        });

        Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_production_order_lines', 'standzeit_min')) {
                $table->unsignedInteger('standzeit_min')->nullable()
                    ->comment('Snapshot: recipe.standzeit_min (1× je Lauf, mengenunabhängig).');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_recipes', 'standzeit_min')) {
                $table->dropColumn('standzeit_min');
            }
        });
        Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_production_order_lines', 'standzeit_min')) {
                $table->dropColumn('standzeit_min');
            }
        });
    }
};
