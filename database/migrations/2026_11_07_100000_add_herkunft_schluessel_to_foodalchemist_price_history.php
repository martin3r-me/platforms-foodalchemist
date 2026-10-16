<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aktionspreise (Entscheid Dominique 16.10.2026): `foodalchemist_price_history` ist die PREIS-ZEITACHSE eines Artikels —
 * unsere Stände vor jedem Update (quelle necta_abgleich), die Necta-Historie (necta_historie) und Aktionen (status 'A', quelle
 * necta_historie | datei | manuell). Keine eigene Aktions-Tabelle, damit es EINE Quelle gibt.
 *
 * `herkunft_schluessel`: Idempotenz für Datei und Handeingabe (Necta hat necta_historie_id). Bewusst NICHT unique: Ersetzt eine
 * Datei den Preis derselben Aktion, bleiben die ausgeblendete alte und die neue Zeile mit demselben Schlüssel stehen; einen
 * partiellen Unique-Index („nur nicht ausgeblendete“) kann MySQL nicht. Die Eindeutigkeit prüft der AktionspreisService in einer
 * Transaktion mit Sperre auf den Artikel. Zusätzlich Index für „aktive Aktion jetzt“.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_price_history', 'herkunft_schluessel')) {
            Schema::table('foodalchemist_price_history', function (Blueprint $table) {
                $table->string('herkunft_schluessel', 64)->nullable()->after('necta_historie_id')
                    ->comment('datei: sha1(datei|item|team|von|bis) · manuell: manuell|uuid');
                $table->index('herkunft_schluessel', 'fa_price_history_herkunft_idx');
                $table->index(['supplier_item_id', 'status', 'valid_to'], 'fa_price_history_aktion_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_price_history', 'herkunft_schluessel')) {
            Schema::table('foodalchemist_price_history', function (Blueprint $table) {
                $table->dropIndex('fa_price_history_aktion_idx');
                $table->dropIndex('fa_price_history_herkunft_idx');
                $table->dropColumn('herkunft_schluessel');
            });
        }
    }
};
