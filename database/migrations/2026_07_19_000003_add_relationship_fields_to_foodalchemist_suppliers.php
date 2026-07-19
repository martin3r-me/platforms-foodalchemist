<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R9.1 (E1 + E4) — kommerzielle Beziehungs-Ebene am Lieferanten:
 * Status (aktiv/zweitquelle/gesperrt; feiner als is_inactive) + Konditionen
 * (Rückvergütung %, Zahlungsziel, Mindestbestellwert, Frei-Haus-Grenze).
 * Konditions-Spalten sind mit [13]/Q2 (Preis-/Katalog-Ingest) GETEILT — EINE Migration.
 */
return new class extends Migration
{
    private array $conditionCols = ['rebate_pct', 'payment_term_days', 'min_order_value', 'free_shipping_threshold'];

    public function up(): void
    {
        Schema::table('foodalchemist_suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_suppliers', 'status')) {
                $table->string('status', 16)->default('aktiv')->index()->comment('SupplierStatus: aktiv|zweitquelle|gesperrt');
            }
            if (! Schema::hasColumn('foodalchemist_suppliers', 'rebate_pct')) {
                $table->decimal('rebate_pct', 5, 2)->nullable()->comment('Rückvergütung/Bonus %');
            }
            if (! Schema::hasColumn('foodalchemist_suppliers', 'payment_term_days')) {
                $table->unsignedInteger('payment_term_days')->nullable()->comment('Zahlungsziel (Tage)');
            }
            if (! Schema::hasColumn('foodalchemist_suppliers', 'min_order_value')) {
                $table->decimal('min_order_value', 12, 2)->nullable()->comment('Mindestbestellwert netto');
            }
            if (! Schema::hasColumn('foodalchemist_suppliers', 'free_shipping_threshold')) {
                $table->decimal('free_shipping_threshold', 12, 2)->nullable()->comment('Frei-Haus-Grenze netto');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_suppliers', function (Blueprint $table) {
            foreach (array_merge(['status'], $this->conditionCols) as $col) {
                if (Schema::hasColumn('foodalchemist_suppliers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
