<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R4.4 Zutaten-Tausch im Concepter: konzept-lokale SLOT-VARIANTE statt stillem
 * Global-Edit. Ein Tausch dupliziert das VK-Gericht und hängt die Kopie an den
 * Slot — das Quell-Gericht (in N anderen Konzepten/Foodbooks) bleibt unangetastet.
 *
 * - recipes.variant_source_recipe_id: Lineage der Kopie aufs Quell-Gericht.
 *   Doppelrolle: Katalog-Filter (Varianten erscheinen NICHT in Browser/Pickern).
 * - concept_slots.variant_source_recipe_id: Marker „variiert" am Slot (Original-
 *   Gericht-ID) — Rücksetzen stellt sales_recipe_id daraus wieder her.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_recipes', 'variant_source_recipe_id')) {
            Schema::table('foodalchemist_recipes', function (Blueprint $table) {
                $table->unsignedBigInteger('variant_source_recipe_id')->nullable()->index();
            });
        }
        if (! Schema::hasColumn('foodalchemist_concept_slots', 'variant_source_recipe_id')) {
            Schema::table('foodalchemist_concept_slots', function (Blueprint $table) {
                $table->unsignedBigInteger('variant_source_recipe_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['foodalchemist_recipes', 'foodalchemist_concept_slots'] as $tabelle) {
            if (Schema::hasColumn($tabelle, 'variant_source_recipe_id')) {
                Schema::table($tabelle, function (Blueprint $table) {
                    $table->dropColumn('variant_source_recipe_id');
                });
            }
        }
    }
};
