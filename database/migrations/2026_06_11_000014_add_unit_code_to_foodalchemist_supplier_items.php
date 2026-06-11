<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2-04/05 (GL-11): Kalkulationseinheit des LA — Quelle supplier_items.unit_id →
 * lookup_unit.code ∈ {kg, l, Stk} (97.602/27.358/94.569, 44.986 NULL).
 * Der Slice-Import hatte die Spalte verworfen; Backfill-Phase `unit_codes`
 * im ImportSliceCommand. Denormalisiert als Code (kein FK) — GL-11 §3.2
 * braucht genau diese drei Werte für die Vergleichspreis-Normalisierung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_supplier_items', function (Blueprint $table) {
            $table->string('unit_code', 8)->nullable()->index()->comment('kg|l|Stk (GL-11 Kalkulationseinheit)');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_supplier_items', function (Blueprint $table) {
            $table->dropColumn('unit_code');
        });
    }
};
