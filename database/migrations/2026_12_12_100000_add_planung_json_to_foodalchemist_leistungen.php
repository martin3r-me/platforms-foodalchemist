<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 94b · Stufe 7c Teil 2: Planungsdetails (`planung_json`: version, komponenten, attribute, notiz_intern) an Leistungs-Vorlage und
 * Konfigurator-Leistung (Kopie). Übergabe-Struktur für OLIVIA, nie im Kundenlink. Additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['foodalchemist_leistung_vorlagen', 'foodalchemist_konfigurator_leistungen'] as $tabelle) {
            if (! Schema::hasColumn($tabelle, 'planung_json')) {
                Schema::table($tabelle, fn (Blueprint $t) => $t->json('planung_json')->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (['foodalchemist_leistung_vorlagen', 'foodalchemist_konfigurator_leistungen'] as $tabelle) {
            if (Schema::hasColumn($tabelle, 'planung_json')) {
                Schema::table($tabelle, fn (Blueprint $t) => $t->dropColumn('planung_json'));
            }
        }
    }
};
