<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mietgeschirr als normaler Artikel (Dominique 2026-10-16): Pfand und Mietdauer hängen am LIEFERANTENARTIKEL (sie hängen vom Lieferanten ab),
 * die Maße am GRUNDPRODUKT (Planung und Slot zielen auf Typ und Größe; weicht ein Lieferant ab, ist das ein anderer GP). Maße in mm
 * gespeichert, Anzeige und Name in cm bzw. ml/l. Der Mietpreis selbst ist der normale LA-Preis; das Pfand fließt in keinen EK.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_supplier_items', 'deposit')) {
            Schema::table('foodalchemist_supplier_items', function (Blueprint $table) {
                $table->decimal('deposit', 10, 2)->nullable()->comment('Pfand netto je Stück (€) — nur Anzeige/Beleg, nie in EK oder Zubehör-Kosten');
                $table->string('rental_period', 16)->nullable()->comment('tag | veranstaltung — worauf sich der Mietpreis bezieht');
            });
        }
        if (! Schema::hasColumn('foodalchemist_gps', 'diameter_mm')) {
            Schema::table('foodalchemist_gps', function (Blueprint $table) {
                $table->decimal('diameter_mm', 8, 1)->nullable()->comment('Ø in mm (nur Warengruppe 16)');
                $table->decimal('length_mm', 8, 1)->nullable();
                $table->decimal('width_mm', 8, 1)->nullable();
                $table->decimal('height_mm', 8, 1)->nullable();
                $table->decimal('volume_ml', 8, 1)->nullable()->comment('Volumen in ml (Gläser, Schalen)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_gps', 'diameter_mm')) {
            Schema::table('foodalchemist_gps', fn (Blueprint $t) => $t->dropColumn(['diameter_mm', 'length_mm', 'width_mm', 'height_mm', 'volume_ml']));
        }
        if (Schema::hasColumn('foodalchemist_supplier_items', 'deposit')) {
            Schema::table('foodalchemist_supplier_items', fn (Blueprint $t) => $t->dropColumn(['deposit', 'rental_period']));
        }
    }
};
