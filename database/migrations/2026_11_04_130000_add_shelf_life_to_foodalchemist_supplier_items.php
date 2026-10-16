<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Haltbarkeit (MHD) am Lieferantenartikel, je Lagerart in Tagen — Ziel für den Produktpass. Bisher gab es sie nur am
 * Rezept (`shelf_life_*_days`). Necta liefert diese Werte nicht, der Abgleich berührt die Spalten nicht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_supplier_items', function (Blueprint $table) {
            foreach (['shelf_life_chilled_days', 'shelf_life_frozen_days', 'shelf_life_dry_days'] as $spalte) {
                if (! Schema::hasColumn('foodalchemist_supplier_items', $spalte)) {
                    $table->unsignedSmallInteger($spalte)->nullable()->comment('Haltbarkeit/MHD in Tagen je Lagerart (Quelle: Produktpass)');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_supplier_items', function (Blueprint $table) {
            foreach (['shelf_life_chilled_days', 'shelf_life_frozen_days', 'shelf_life_dry_days'] as $spalte) {
                if (Schema::hasColumn('foodalchemist_supplier_items', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
    }
};
