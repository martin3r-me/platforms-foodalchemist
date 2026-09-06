<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lineage-Paar fürs Servier-Vehikel (Spec 50 · B-1).
 *
 * `serving_vehicle_vocab_id` hatte als einziges KI-befülltes VK-Feld kein `_source`. Damit war
 * weder Override-First möglich (ein von Hand gewähltes Vehikel sähe für die Anreicherung aus wie
 * ein KI-Wert) noch die Provenienz ehrlich (ein KI-Vehikel stünde da wie eine Entscheidung). Das
 * Muster ist das der anderen VK-Felder: `<feld>_source` + `<feld>_ai_confidence` (GL-07).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $t) {
            if (! Schema::hasColumn('foodalchemist_recipes', 'serving_vehicle_source')) {
                $t->string('serving_vehicle_source', 16)->nullable()
                    ->comment('Lineage GL-07: manual gewinnt gegen ki');
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'serving_vehicle_ai_confidence')) {
                $t->decimal('serving_vehicle_ai_confidence', 4, 3)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $t) {
            foreach (['serving_vehicle_source', 'serving_vehicle_ai_confidence'] as $c) {
                if (Schema::hasColumn('foodalchemist_recipes', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
