<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 75c · Herkunft eines Belegs: `beleg` (auf der Wareneingang-Seite erfasst), `editor` (Kurzweg
 * aus dem Bestell-Editor bzw. den alten MCP-Wegen) oder `altbestand` (aus den Feldern der
 * Bestellzeile übernommen). So bleibt die Bestellzeile immer die Summe ihrer Belege. Rein additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['foodalchemist_delivery_notes', 'foodalchemist_supplier_invoices'] as $tabelle) {
            if (Schema::hasTable($tabelle) && ! Schema::hasColumn($tabelle, 'source')) {
                Schema::table($tabelle, function (Blueprint $table) {
                    $table->string('source', 16)->default('beleg');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['foodalchemist_delivery_notes', 'foodalchemist_supplier_invoices'] as $tabelle) {
            if (Schema::hasTable($tabelle) && Schema::hasColumn($tabelle, 'source')) {
                Schema::table($tabelle, function (Blueprint $table) {
                    $table->dropColumn('source');
                });
            }
        }
    }
};
