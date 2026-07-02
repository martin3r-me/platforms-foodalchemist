<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #388 Geschirr-Datenbank — Verlinkung „direkter Geschirr-Artikel + Alternative je Gericht".
 *
 * Ein „Gericht" im Concepter ist ein concept_slot mit type ∈ gericht|basisrezept.
 * Hier dockt das Geschirr an: geschirr_item_id (Haupt) + geschirr_alt_item_id (Alternative,
 * z. B. vom anderen Leih-Caterer). Pro Gericht, nicht pro Konzept (Dominique 2026-06-17).
 *
 * Migrations-Falle (CLAUDE.md): additive Spalten, KEINE ALTER-add-FK (SQLite kann das nicht),
 * stattdessen unsignedBigInteger nullable+index; hasColumn-Guards = idempotent; kein ->after().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_concept_slots', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'geschirr_item_id')) {
                $table->unsignedBigInteger('geschirr_item_id')->nullable()->index();
            }
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'geschirr_alt_item_id')) {
                $table->unsignedBigInteger('geschirr_alt_item_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        $cols = array_values(array_filter(
            ['geschirr_item_id', 'geschirr_alt_item_id'],
            fn ($c) => Schema::hasColumn('foodalchemist_concept_slots', $c)
        ));
        if ($cols !== []) {
            Schema::table('foodalchemist_concept_slots', function (Blueprint $table) use ($cols) {
                $table->dropColumn($cols);
            });
        }
    }
};
