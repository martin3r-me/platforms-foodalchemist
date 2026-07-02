<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rezept-Aggregat für „davon Zucker" + „davon gesättigte Fettsäuren" (g/100g).
 *
 * Ergänzt den bestehenden nutri_*-Block (kcal/protein/fat/carbs/salt) um die zwei
 * EU-Label-Unterzeilen. Aggregation analog zu Protein/Fett (direkte g-Werte, KEINE
 * Ableitung wie Salz) — siehe RecipeRecomputeService::naehrwerte + KERNWERTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->decimal('nutri_sugar_g_per_100g', 8, 2)->nullable()->after('nutri_carbs_g_per_100g');
            $table->decimal('nutri_saturated_fat_g_per_100g', 8, 2)->nullable()->after('nutri_fat_g_per_100g');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropColumn(['nutri_sugar_g_per_100g', 'nutri_saturated_fat_g_per_100g']);
        });
    }
};
