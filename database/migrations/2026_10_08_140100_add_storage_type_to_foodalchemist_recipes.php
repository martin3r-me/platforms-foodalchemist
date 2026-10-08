<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 70 · Übliche Lagerart am Rezept (gekuehlt | tiefgekuehlt | trocken) — Vorgabe für Etikett
 * und Einlagerung; die Haltbarkeit je Lagerart liegt in shelf_life_*_days. Rein additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_recipes', 'storage_type')) {
                $table->string('storage_type', 16)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropColumn('storage_type');
        });
    }
};
