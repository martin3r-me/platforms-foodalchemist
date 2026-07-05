<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Concept-VK manuell hinterlegbar (z. B. Lunchbuffet: Preis auf Basis des EK).
 * Default 'auto' = VK/Person = Σ der Positionen (Ist-Verhalten); 'manuell' = fixer
 * Wert aus preis_pro_person_manuell überschreibt die Summe. EK bleibt aus den Positionen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_concepts', 'price_mode')) {
                $table->string('price_mode', 16)->default('auto');   // auto | manuell
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'price_per_person_manual')) {
                $table->decimal('price_per_person_manual', 10, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            foreach (['price_mode', 'price_per_person_manual'] as $col) {
                if (Schema::hasColumn('foodalchemist_concepts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
