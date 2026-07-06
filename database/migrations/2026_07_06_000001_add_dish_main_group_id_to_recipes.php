<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VK-Taxonomie Modell A (Regelwerk_Verkaufsgerichte v1.1): Die Hauptgruppe ist die
 * Kategorie und wird DIREKT am Rezept geführt (bisher nur über die Klasse ableitbar).
 * Nötig, weil die Klasse künftig nur noch die Diätform trägt (HG-unabhängig, flach).
 * Engine-agnostisch (kein ->after / FK-Constraint), nur Index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_recipes', 'dish_main_group_id')) {
                $table->unsignedBigInteger('dish_main_group_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropColumn('dish_main_group_id');
        });
    }
};
