<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mengen-Korrektur aus der Bezeichnung (Entscheid Dominique 16.10.2026, {@see \Platform\FoodAlchemist\Support\MengeAusBezeichnung}).
 * Eigene Spalten am Artikel, der Necta-Rohwert `qty` bleibt: Der Vollvergleich vergleicht nur Mapper-Felder, also kein Prüffall.
 * Alle Mengen-Rechnungen lesen `qty_korrigiert ?? qty`. Keine Daten: Bestand füllt `foodalchemist:menge-korrigieren`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_supplier_items', 'qty_korrigiert')) {
            Schema::table('foodalchemist_supplier_items', function (Blueprint $table) {
                $table->decimal('qty_korrigiert', 12, 4)->nullable()->after('qty')->comment('korrigierte Menge je Bestelleinheit (Necta-qty bleibt)');
                $table->string('qty_korrektur_quelle', 32)->nullable()->after('qty_korrigiert')->comment('bezeichnung_edi');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_supplier_items', 'qty_korrigiert')) {
            Schema::table('foodalchemist_supplier_items', fn (Blueprint $table) => $table->dropColumn(['qty_korrigiert', 'qty_korrektur_quelle']));
        }
    }
};
