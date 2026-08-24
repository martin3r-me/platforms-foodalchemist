<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geschirr je Paket-Posten (2026-08-24) — spiegelt #388 (concept_slots) auf die Pakete.
 *
 * Ein Paket ist im Grunde ein Concept mit eigenem Preis; damit der Paket-Editor den
 * Conceptor spiegelt, dockt hier dasselbe Geschirr an: tableware_item_id (Haupt) +
 * tableware_alt_item_id (Alternative) je Paket-Posten (foodalchemist_package_dishes).
 *
 * Migrations-Falle (CLAUDE.md): additive Spalten, KEINE ALTER-add-FK (SQLite kann das nicht),
 * stattdessen unsignedBigInteger nullable+index; hasColumn-Guards = idempotent; kein ->after().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_package_dishes', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_package_dishes', 'tableware_item_id')) {
                $table->unsignedBigInteger('tableware_item_id')->nullable()->index();
            }
            if (! Schema::hasColumn('foodalchemist_package_dishes', 'tableware_alt_item_id')) {
                $table->unsignedBigInteger('tableware_alt_item_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        $cols = array_values(array_filter(
            ['tableware_item_id', 'tableware_alt_item_id'],
            fn ($c) => Schema::hasColumn('foodalchemist_package_dishes', $c)
        ));
        if ($cols !== []) {
            Schema::table('foodalchemist_package_dishes', function (Blueprint $table) use ($cols) {
                $table->dropColumn($cols);
            });
        }
    }
};
