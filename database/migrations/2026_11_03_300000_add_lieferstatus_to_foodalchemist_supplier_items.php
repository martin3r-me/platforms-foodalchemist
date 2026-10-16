<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lieferstatus aus dem Necta-Preis-Lebenszyklus (16.10.2026, {@see \Platform\FoodAlchemist\Enums\PriceCategory}): 'auslaufend'
 * (status 2 mit Preis) bzw. 'eingestellt' (status 2 ohne Preis = nicht mehr bestellbar), seit statusValidFrom, Quelle 'necta'.
 * Eigenes Feldpaar statt is_discontinued, damit Handpflege („Ausgelistet“) und Necta-Ableitung sich nicht überschreiben.
 * Keine Daten: Bestand füllt `foodalchemist:necta-lieferstatus`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_supplier_items', 'lieferstatus')) {
            Schema::table('foodalchemist_supplier_items', function (Blueprint $table) {
                $table->string('lieferstatus', 16)->nullable()->after('is_discontinued')->comment('auslaufend | eingestellt (NULL = gelistet)');
                $table->timestamp('lieferstatus_seit')->nullable()->after('lieferstatus');
                $table->string('lieferstatus_quelle', 16)->nullable()->after('lieferstatus_seit')->comment('necta');
                $table->index('lieferstatus', 'fa_si_lieferstatus_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_supplier_items', 'lieferstatus')) {
            Schema::table('foodalchemist_supplier_items', function (Blueprint $table) {
                $table->dropIndex('fa_si_lieferstatus_idx');
                $table->dropColumn(['lieferstatus', 'lieferstatus_seit', 'lieferstatus_quelle']);
            });
        }
    }
};
