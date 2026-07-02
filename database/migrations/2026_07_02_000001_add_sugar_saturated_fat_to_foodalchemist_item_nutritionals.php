<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EU-Pflicht-Label-Unterzeilen „davon Zucker" + „davon gesättigte Fettsäuren".
 *
 * Beim Erst-Import wurden aus dem Necta-Nährwert-Label nur Energie/Fett/KH/Eiweiß
 * gemappt — die drei Unterzeilen (Salz, Zucker, ges. Fettsäuren) fielen in raw_json.
 * Salz hatte eine Spalte (sodium) und wird per Backfill gefüllt; Zucker + ges.
 * Fettsäuren fehlten als Spalte ganz. Diese Migration ergänzt sie (1:1 Quellfelder
 * TotalSugar / SaturatedFattyAcids, g/100g Rohmasse, GL-08). Backfill aus raw_json
 * via WaWi-Skript 214.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_item_nutritionals', function (Blueprint $table) {
            $table->decimal('sugar', 12, 4)->nullable()->after('carbs_absorbable')
                ->comment('davon Zucker g/100g (Quelle TotalSugar)');
            $table->decimal('saturated_fat', 12, 4)->nullable()->after('fat')
                ->comment('davon ges. Fettsäuren g/100g (Quelle SaturatedFattyAcids)');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_item_nutritionals', function (Blueprint $table) {
            $table->dropColumn(['sugar', 'saturated_fat']);
        });
    }
};
