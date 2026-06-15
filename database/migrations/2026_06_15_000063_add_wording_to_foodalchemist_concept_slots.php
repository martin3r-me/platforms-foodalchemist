<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Concept-übergreifendes Wording: pro Position ein Brand-Voice-Anzeigename
 * (Variante des neutralen vk_wording_standard, im Concept-Schreibstil erzeugt).
 * Leer = Standardname des Gerichts. Additiv, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_concept_slots', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'wording')) {
                $table->text('wording')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_concept_slots', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_concept_slots', 'wording')) {
                $table->dropColumn('wording');
            }
        });
    }
};
