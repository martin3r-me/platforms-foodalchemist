<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GP: „Name aus Feldern“ vollständig machen (Entscheid Dominique 25.10.2026). `pflichtangabe` (§8, z. B. mini, 3-fach sortiert, Größe 8/12) und `portion`
 * (Portionsgrammatur „100 g“, der Name zeigt „100 g pro Stück“) waren bisher nur Builder-Eingaben ohne Spalte — der Name war deshalb Text, aus den
 * gespeicherten Feldern nicht rekonstruierbar. Gleiche Mechanik wie flavor/zusatz; Bestand bleibt NULL (Befüllung per Stufe-1-Lauf aus den Namen).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_gps')) {
            return;
        }
        if (! Schema::hasColumn('foodalchemist_gps', 'pflichtangabe')) {
            Schema::table('foodalchemist_gps', function (Blueprint $table) {
                $col = $table->string('pflichtangabe', 255)->nullable()->comment('Pflichtangabe nach §8 (Freitext, Teil des Namens): mini, 3-fach sortiert, Größe 8/12 …');
                if (Schema::hasColumn('foodalchemist_gps', 'zusatz')) {
                    $col->after('zusatz');
                }
            });
        }
        if (! Schema::hasColumn('foodalchemist_gps', 'portion')) {
            Schema::table('foodalchemist_gps', function (Blueprint $table) {
                $col = $table->string('portion', 32)->nullable()->comment('Portionsgrammatur je Stück („100 g“); der Name zeigt „100 g pro Stück“');
                if (Schema::hasColumn('foodalchemist_gps', 'pflichtangabe')) {
                    $col->after('pflichtangabe');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['portion', 'pflichtangabe'] as $spalte) {
            if (Schema::hasTable('foodalchemist_gps') && Schema::hasColumn('foodalchemist_gps', $spalte)) {
                Schema::table('foodalchemist_gps', fn (Blueprint $table) => $table->dropColumn($spalte));
            }
        }
    }
};
