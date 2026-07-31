<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 27 Nachtrag — Endprodukt-Bild: das eine Foto, das zeigt, wie das Gericht
 * FERTIG aussehen soll („so soll es auf den Teller kommen").
 *
 * Bewusst ein Flag am bestehenden Foto und KEIN eigenes Feld am Rezept: das Bild
 * liegt sowieso im Media-Pool, ein separates `hero_pfad` am Rezept würde die Datei
 * doppelt führen und beim Löschen auseinanderlaufen. Als Flag ist es außerdem
 * gleichzeitig als Schritt-Foto verwendbar (letzter Schritt = Anrichten).
 *
 * GENAU EINES je Rezept — erzwungen im RecipeStepService (nicht per DB-Constraint:
 * ein partielles Unique auf (recipe_id) WHERE is_result=1 kann MySQL nicht).
 *
 * Weil Basisrezepte und Gerichte dasselbe Model sind, trägt das Feld ohne Umbau
 * auch für Verkaufs-Gerichte (Foodbook/Angebot) — dort wird es nur noch nicht gezeigt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_recipe_step_photos')
            || Schema::hasColumn('foodalchemist_recipe_step_photos', 'is_result')) {
            return;
        }

        Schema::table('foodalchemist_recipe_step_photos', function (Blueprint $table) {
            $table->boolean('is_result')->default(false)
                ->comment('Endprodukt-Bild: so soll das fertige Gericht aussehen (max. 1 je Rezept)');
            $table->index(['recipe_id', 'is_result'], 'fa_step_photos_result_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_recipe_step_photos', 'is_result')) {
            Schema::table('foodalchemist_recipe_step_photos', function (Blueprint $table) {
                $table->dropIndex('fa_step_photos_result_idx');
                $table->dropColumn('is_result');
            });
        }
    }
};
