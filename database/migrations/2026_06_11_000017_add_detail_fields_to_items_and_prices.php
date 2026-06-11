<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2-16 (Screen-3-Abgleich + V-29): im Slice verworfene Quell-Felder nachziehen.
 * Vorbestellzeiten (is_preorder/preorder_days, 5.340 Artikel real) = Datenbasis
 * für die V-29-Logik (Verortung offen, s. Roadmap-Entscheide-Tabelle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_supplier_items', function (Blueprint $table) {
            $table->text('zusatztext')->nullable()->comment('Quelle: text');
            $table->decimal('vat', 5, 2)->nullable()->comment('MwSt % (Anzeige, GL-11 I1: Preise netto)');
            $table->string('origin_country', 64)->nullable()->comment('aus origin_country_id → lookup_country.name_de');
            $table->string('organic_control_number', 64)->nullable();
            $table->boolean('is_halal')->nullable();
            $table->boolean('is_gmo_free')->nullable();
            $table->boolean('is_preorder')->nullable()->index();
            $table->unsignedInteger('preorder_days')->nullable()->comment('V-29 Vorbestellzeit in Tagen');
            $table->text('ingredients_lieferant')->nullable()->comment('Zutatenliste vom Lieferanten (Quelle: ingredients)');
        });

        Schema::table('foodalchemist_prices', function (Blueprint $table) {
            $table->text('note')->nullable()->comment('Quelle: prices.note (62 Zeilen real)');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_supplier_items', function (Blueprint $table) {
            $table->dropColumn(['zusatztext', 'vat', 'origin_country', 'organic_control_number', 'is_halal', 'is_gmo_free', 'is_preorder', 'preorder_days', 'ingredients_lieferant']);
        });
        Schema::table('foodalchemist_prices', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
