<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 94c · Stufe 8a: „enthält Alkohol“ am Rezept, abgeleitet wie spec_contains_pork (Warn-Regel: ja, sobald eine Zutat
 * Alkohol enthält). Quelle ist der Lieferantenartikel (is_alcohol), hochgereicht über GP und Sub-Rezepte im Recompute.
 * NULL = unbekannt bzw. noch nicht neu berechnet. Additiv; ein Bestandslauf (Recompute) nur mit Ansage.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_recipes', 'spec_contains_alcohol')) {
            Schema::table('foodalchemist_recipes', function (Blueprint $table) {
                $table->boolean('spec_contains_alcohol')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_recipes', 'spec_contains_alcohol')) {
            Schema::table('foodalchemist_recipes', fn (Blueprint $t) => $t->dropColumn('spec_contains_alcohol'));
        }
    }
};
