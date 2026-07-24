<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 19 „Foodbook-Leitstelle A–Z" — M5 (Präsentations-Hälfte): expliziter
 * Darreichungs-Override am Foodbook-Block (`recipe_ref`-Einzel-Pfad).
 *
 * Scharnier zu `DarreichungResolver::fuerBlock(Block, ?$servingFormId)`:
 *   1. block.presentation_id (diese Spalte) → 2. Gericht-Darreichung zur
 *   Kapitel-/Foodbook-Servierform → 3. standardFuer().
 * Solange die Spalte NULL bleibt und keine Servierform greift, verhält sich die
 * Preis-Auflösung bit-identisch zu heute (recipes.sales_net; keine sichtbaren
 * Preisänderungen im Bestand — Top-Risiko #5).
 *
 * Additiv, idempotent. ALTER auf Bestandstabelle mit `unsignedBigInteger + index`
 * statt harter FK (Live-Daten, Spec-Regel; loser Zeiger auf
 * foodalchemist_recipe_darreichungen).
 *
 * Die zweite M5-Hälfte (Outlets-Vokabular + chapters.outlet_id) wurde bereits in
 * E3.6 als eigene Migration gefahren (abgekoppelt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_foodbook_blocks', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_foodbook_blocks', 'presentation_id')) {
                $table->unsignedBigInteger('presentation_id')->nullable()->index()->after('sales_recipe_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_foodbook_blocks', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_foodbook_blocks', 'presentation_id')) {
                $table->dropColumn('presentation_id');
            }
        });
    }
};
