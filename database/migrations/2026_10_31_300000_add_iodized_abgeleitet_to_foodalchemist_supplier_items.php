<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jod aus der Zutatenliste abgeleitet (15.10.2026, {@see \Platform\FoodAlchemist\Support\JodAusZutaten}): eigene Spalte am Artikel,
 * NICHT in der Deklaration. So bleibt der Necta-Rohwert `item_declarations.iodized` unberührt und der wöchentliche Vollvergleich
 * erzeugt keinen Prüffall; die Spalte gibt es auch für Artikel ohne Deklarationszeile. Die Aggregation nimmt
 * max(Necta-Flag, abgeleitet). Werte wie die Deklaration: 3 = ja, 1 = nein (ausdrücklich unjodiert), NULL = keine Aussage.
 * Keine Daten: Bestand füllt `foodalchemist:jod-ableiten`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_supplier_items', 'iodized_abgeleitet')) {
            Schema::table('foodalchemist_supplier_items', function (Blueprint $table) {
                $table->unsignedTinyInteger('iodized_abgeleitet')->nullable()->after('ingredients_supplier')
                    ->comment('Jod aus ingredients_supplier abgeleitet: 3=ja 1=nein NULL=keine Aussage');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_supplier_items', 'iodized_abgeleitet')) {
            Schema::table('foodalchemist_supplier_items', fn (Blueprint $table) => $table->dropColumn('iodized_abgeleitet'));
        }
    }
};
