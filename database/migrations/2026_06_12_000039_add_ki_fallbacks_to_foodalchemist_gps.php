<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R10 (Jarvis-Feature): KI-Fallback am GP, wenn keine LA-Daten vorhanden —
 * Nährwerte als eigene Fallback-Schicht (NUR Panel-Anzeige, NICHT die
 * GL-08-Rezept-Aggregation) + Lineage für KI-geschätzte Allergen-Overrides
 * (die Werte selbst leben im bestehenden Override-Layer `allergen_*`, GL-01 Prio 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_gps', function (Blueprint $table) {
            foreach (['nutri_kcal_per_100g', 'nutri_protein_g_per_100g', 'nutri_fat_g_per_100g', 'nutri_carbs_g_per_100g', 'nutri_salt_g_per_100g'] as $feld) {
                $table->decimal($feld, 8, 2)->nullable();
            }
            $table->string('nutri_source', 16)->nullable();          // ki | manual
            $table->decimal('nutri_ai_confidence', 4, 3)->nullable();
            $table->decimal('allergene_ki_confidence', 4, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_gps', function (Blueprint $table) {
            $table->dropColumn([
                'nutri_kcal_per_100g', 'nutri_protein_g_per_100g', 'nutri_fat_g_per_100g',
                'nutri_carbs_g_per_100g', 'nutri_salt_g_per_100g',
                'nutri_source', 'nutri_ai_confidence', 'allergene_ki_confidence',
            ]);
        });
    }
};
