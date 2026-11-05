<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 94c · Stufe 8b: Getränkepakete als `rolle = getraenk` an der Konfigurator-Konzept-Zeile (keine neue Tabelle). Kommerzielles
 * steht an der Zeile, der Inhalt im Concept: Abrechnung (8b nur pauschale, verbrauch folgt in 8c), Dauer (event | stunde) und optional
 * eine Staffel (Grundpreis für grund_stunden, je weitere Stunde folgestunde_satz). Am Konfigurator getraenke_pflicht. Additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_konfigurator_konzepte', 'getraenk_abrechnung')) {
            Schema::table('foodalchemist_konfigurator_konzepte', function (Blueprint $table) {
                $table->string('getraenk_abrechnung', 12)->nullable();
                $table->string('getraenk_dauer', 8)->nullable();
                $table->decimal('grund_stunden', 5, 2)->nullable();
                $table->decimal('folgestunde_satz', 10, 2)->nullable();
            });
        }
        if (! Schema::hasColumn('foodalchemist_konfiguratoren', 'getraenke_pflicht')) {
            Schema::table('foodalchemist_konfiguratoren', fn (Blueprint $t) => $t->boolean('getraenke_pflicht')->default(false));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_konfigurator_konzepte', 'getraenk_abrechnung')) {
            Schema::table('foodalchemist_konfigurator_konzepte', fn (Blueprint $t) => $t->dropColumn(['getraenk_abrechnung', 'getraenk_dauer', 'grund_stunden', 'folgestunde_satz']));
        }
        if (Schema::hasColumn('foodalchemist_konfiguratoren', 'getraenke_pflicht')) {
            Schema::table('foodalchemist_konfiguratoren', fn (Blueprint $t) => $t->dropColumn('getraenke_pflicht'));
        }
    }
};
