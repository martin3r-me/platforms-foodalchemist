<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GP-Regelwerk v3.10.0 (Entscheid Dominique 13.11.2026), neue GP-Felder für den Namen und die Prüfliste:
 * - `herkunft_land` (Regel 94 Nr. 4, 823): Länder-Klammer „/ (Argentinien)“ — nur rohe Fleisch-Teilstücke aus Ländern außerhalb der EU. NULL = keine Klammer.
 * - `namens_msc`, `namens_asc` (Regel 94 Nr. 6): Siegel-Klammer „/ (MSC)“ bzw. „/ (ASC)“, nur mit Beleg im Artikel. Wie `namens_vegan` ein
 *   Namensentscheid, getrennt von Artikel-Tags.
 * - `angabe_status` + `angabe_grund` (Regel 91, 820): internes Kennzeichen einer Pflichtangabe ohne Beleg — `angenommen` (üblicher Wert aus der
 *   Startliste angenommen) oder `offen` (kein üblicher Wert, Angabe fehlt; nie mit einem belegten GP zusammenführen). Grund = Prüflisten-Text
 *   („Konservierungsart nicht belegt (§8.20)“). Steht nie im Namen.
 * Bestand: NULL/false. Befüllung durch den GP-Lauf. Idempotent (hasColumn je Spalte).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_gps')) {
            return;
        }
        $spalten = [
            'herkunft_land' => fn (Blueprint $t) => $t->string('herkunft_land', 64)->nullable()->comment('Regel 94/823: Länder-Klammer „/ (Land)“, nur rohe Nicht-EU-Teilstücke'),
            'namens_msc' => fn (Blueprint $t) => $t->boolean('namens_msc')->default(false)->comment('Regel 94: Siegel-Klammer „/ (MSC)“'),
            'namens_asc' => fn (Blueprint $t) => $t->boolean('namens_asc')->default(false)->comment('Regel 94: Siegel-Klammer „/ (ASC)“'),
            'angabe_status' => fn (Blueprint $t) => $t->string('angabe_status', 16)->nullable()->comment('Regel 91: angenommen | offen (Pflichtangabe ohne Beleg); nie im Namen'),
            'angabe_grund' => fn (Blueprint $t) => $t->string('angabe_grund')->nullable()->comment('Regel 91: Prüflisten-Grund zum Kennzeichen'),
        ];
        foreach ($spalten as $spalte => $def) {
            if (! Schema::hasColumn('foodalchemist_gps', $spalte)) {
                Schema::table('foodalchemist_gps', $def);
            }
        }
    }

    public function down(): void
    {
        foreach (['herkunft_land', 'namens_msc', 'namens_asc', 'angabe_status', 'angabe_grund'] as $spalte) {
            if (Schema::hasTable('foodalchemist_gps') && Schema::hasColumn('foodalchemist_gps', $spalte)) {
                Schema::table('foodalchemist_gps', fn (Blueprint $t) => $t->dropColumn($spalte));
            }
        }
    }
};
