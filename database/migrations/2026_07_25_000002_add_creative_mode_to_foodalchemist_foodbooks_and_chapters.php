<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 19 „Foodbook-Leitstelle A–Z" — M6 (E9.1): Kreativ-Modus.
 *
 * Die Kreativ-Phase bekommt einen Modus-Schalter (voll_kreativ | hybrid | datenbank),
 * pro Kapitel wählbar mit Foodbook-Default; aufgelöst über die bekannte Kaskade
 * Kapitel → Foodbook → Code-Default ('hybrid'). KANONISCHE Model-Const `CREATIVE_MODES`
 * (Vokabular-Pflicht, kein Freitext).
 *
 *   - foodbooks.creative_mode_default → Default für alle Kapitel ohne eigene Wahl
 *   - foodbook_chapters.creative_mode  → Kapitel-Override (NULL ⇒ erbt vom Foodbook)
 *
 * Additiv, rückwärtskompatibel (NULL ⇒ Default 'hybrid' im Resolver). ALTER auf
 * Bestandstabellen; idempotent via hasColumn-Guards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'creative_mode_default')) {
                $table->string('creative_mode_default', 16)->nullable();   // voll_kreativ|hybrid|datenbank (Model-Const)
            }
        });

        Schema::table('foodalchemist_foodbook_chapters', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'creative_mode')) {
                $table->string('creative_mode', 16)->nullable();           // NULL ⇒ erbt Foodbook-Default
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_foodbooks', 'creative_mode_default')) {
                $table->dropColumn('creative_mode_default');
            }
        });
        Schema::table('foodalchemist_foodbook_chapters', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_foodbook_chapters', 'creative_mode')) {
                $table->dropColumn('creative_mode');
            }
        });
    }
};
