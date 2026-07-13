<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R6.1: interne Notiz am Concept-Slot — der Konzept-Generator hinterlegt hier die
 * Leer-Begründung („kein VK-Gericht erfüllt: …"), damit sie IM Editor steht und
 * nicht nur im flüchtigen Generator-Protokoll (DoD: Slot ohne Treffer bleibt leer
 * MIT Begründung). Generell nützlich für interne Slot-Anmerkungen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_concept_slots', 'note')) {
            Schema::table('foodalchemist_concept_slots', function (Blueprint $table) {
                $table->text('note')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_concept_slots', 'note')) {
            Schema::table('foodalchemist_concept_slots', function (Blueprint $table) {
                $table->dropColumn('note');
            });
        }
    }
};
