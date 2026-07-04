<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Basisrezept-Ertrag in Stück (kg ↔ Stück): „20 kg ergeben 50 Törtchen".
 * Mit yield_kg ergibt sich 1 Stück = yield_kg / yield_pieces kg. Additiv, nullable.
 * (Die Verrechnung im Concept als Stück-Einheit folgt separat — berührt das EK-Modell.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_recipes', 'yield_pieces')) {
                $table->decimal('yield_pieces', 10, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_recipes', 'yield_pieces')) {
                $table->dropColumn('yield_pieces');
            }
        });
    }
};
