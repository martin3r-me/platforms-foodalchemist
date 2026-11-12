<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abtropfgewicht am Lieferantenartikel (Entscheid Dominique 12.11.2026). Beide Mengen in der Kalkulationseinheit des Artikels,
 * bezogen auf dieselbe Bestelleinheit wie `qty` (Preis je Bestelleinheit). Ist `abtropfgewicht` gesetzt (> 0, Einheit kg/l),
 * rechnet der Preis je kg damit ({@see \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::preisMengeVon}).
 * Keine Daten: Bestand füllt `foodalchemist:abtropf-backfill --apply` nach Freigabe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_supplier_items', 'abtropfgewicht')) {
            Schema::table('foodalchemist_supplier_items', function (Blueprint $table) {
                $table->decimal('fuellgewicht', 12, 4)->nullable()->after('qty_korrektur_quelle')->comment('Füllgewicht je Bestelleinheit in der Kalkulationseinheit');
                $table->decimal('abtropfgewicht', 12, 4)->nullable()->after('fuellgewicht')->comment('Abtropfgewicht je Bestelleinheit; gesetzt = Menge für den Preis je kg');
                $table->string('abtropf_quelle', 16)->nullable()->after('abtropfgewicht')->comment('text | necta | manuell');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_supplier_items', 'abtropfgewicht')) {
            Schema::table('foodalchemist_supplier_items', fn (Blueprint $table) => $table->dropColumn(['fuellgewicht', 'abtropfgewicht', 'abtropf_quelle']));
        }
    }
};
