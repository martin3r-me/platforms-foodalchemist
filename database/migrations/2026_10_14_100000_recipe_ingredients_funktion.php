<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Funktion einer Zutatenzeile (Dominique 11.10.): wofür die Zeile im Rezept da ist — basis, farbe, bindung, würze,
 * textur, aroma, säure, fett, garnitur (Vokabular = Regel `rezeptzeile.funktion`). Vorher stand die Plan-Funktion nur
 * als Text in der Notiz („Komponente laut Plan (basis)“). Die Teller-Rolle (`role`) im Gericht bleibt unberührt;
 * am Basisrezept gibt es keine feste Rolle. Kein Backfill — neue Rezepte aus Plan/Generator, alte bei Überarbeitung.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_recipe_ingredients', 'funktion')) {
            Schema::table('foodalchemist_recipe_ingredients', function (Blueprint $table) {
                $table->string('funktion', 30)->nullable()->after('role');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_recipe_ingredients', 'funktion')) {
            Schema::table('foodalchemist_recipe_ingredients', fn (Blueprint $table) => $table->dropColumn('funktion'));
        }
    }
};
