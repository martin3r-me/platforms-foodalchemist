<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VK-Taxonomie Modell A (Regelwerk_Verkaufsgerichte v1.1): Der Aufschlag ist HG-konstant
 * (variiert nie nach Diät). Da die Klasse künftig nur Diät trägt, wandert der
 * Aufschlags-Default von der Klasse auf die Hauptgruppe. Ein neu angelegtes Gericht
 * erbt den Aufschlag von seiner HG (RecipeGeneratorService).
 * Engine-agnostisch (kein ->after / FK-Constraint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_dish_main_groups', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_dish_main_groups', 'default_markup_class_id')) {
                $table->unsignedBigInteger('default_markup_class_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_dish_main_groups', function (Blueprint $table) {
            $table->dropColumn('default_markup_class_id');
        });
    }
};
