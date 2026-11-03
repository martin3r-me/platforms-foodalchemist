<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 94b · Stufe 7c: Angebots-Blocktyp `position` (Menge × Satz, pauschal) braucht den EK je Einheit — `ek_value` an
 * foodalchemist_offer_blocks. Menge, Einheit und Satz nutzen die vorhandenen Spalten quantity, unit_vocab_id, price_value.
 * Additiv, nullable; down() entfernt nur die Spalte.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_offer_blocks', 'ek_value')) {
            Schema::table('foodalchemist_offer_blocks', function (Blueprint $table) {
                $table->decimal('ek_value', 10, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_offer_blocks', 'ek_value')) {
            Schema::table('foodalchemist_offer_blocks', fn (Blueprint $t) => $t->dropColumn('ek_value'));
        }
    }
};
