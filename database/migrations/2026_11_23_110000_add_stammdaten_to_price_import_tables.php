<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stammdaten-Import (Kurator 29.10.2026, Stufe 1a): EINE Pipeline für Preislisten und Artikel-Stammdaten.
 *
 * Ein Lauf bekommt eine Art (preisliste | stammdaten) und eine Herkunft (user | lieferanten_link, Stufe 2). Eine Stammdaten-Zeile trägt die
 * normalisierten Datei-Werte (`daten`), das Prüfergebnis (`aktion`: neu | aendert | unveraendert | konflikt | fehler), den Soll/Ist-Vergleich
 * (`diff`), Hinweise (Allergen-/Nährwert-Prüfung) und die Entscheidung des Prüfers. „Entwurf“ ist die Zeile selbst: in den Bestand kommt nichts,
 * bevor jemand sie übernimmt (kein Entwurfsstatus am Artikel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_price_import_runs', function (Blueprint $table) {
            $table->string('art', 16)->default('preisliste')->after('status')->comment('preisliste | stammdaten');
            $table->string('herkunft', 24)->default('user')->after('art')->comment('user | lieferanten_link');
            $table->unsignedBigInteger('link_id')->nullable()->after('herkunft')->comment('Lieferanten-Link (Stufe 2)');
        });

        Schema::table('foodalchemist_price_import_lines', function (Blueprint $table) {
            $table->json('daten')->nullable()->after('reason')->comment('Stammdaten: normalisierte Datei-Werte je Spalte');
            $table->string('aktion', 16)->nullable()->after('daten')->comment('neu | aendert | unveraendert | konflikt | fehler');
            $table->json('diff')->nullable()->after('aktion')->comment('alt → neu je Feld/Block');
            $table->json('hinweise')->nullable()->after('diff')->comment('Prüfhinweise (Allergen, Nährwert, Preis)');
            $table->string('entscheidung', 12)->default('offen')->after('hinweise')->comment('offen | uebernehmen | verwerfen');
            $table->boolean('allergene_bestaetigt')->default(false)->after('entscheidung');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_price_import_lines', function (Blueprint $table) {
            $table->dropColumn(['daten', 'aktion', 'diff', 'hinweise', 'entscheidung', 'allergene_bestaetigt']);
        });
        Schema::table('foodalchemist_price_import_runs', function (Blueprint $table) {
            $table->dropColumn(['art', 'herkunft', 'link_id']);
        });
    }
};
