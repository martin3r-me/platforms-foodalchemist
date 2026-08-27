<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #7 (Dominique 2026-08-27): Brutto-Rundung für die Speisekarte-Ausgabe. Ergänzt den bestehenden
 * netto/brutto-Schalter (`preis_anzeige_brutto`) um einen Rundungs-Modus für die BRUTTO-Anzeige:
 *   keine   → auf den Cent (bisheriges Verhalten)
 *   auf_10  → auf 0,10 €
 *   auf_50  → auf 0,50 €
 *   auf_90  → aufgerundet auf die nächste X,90 (Gastro-Psychologie)
 * Wirkt NUR auf die Anzeige (dokumentDaten), nicht auf die gespeicherten Netto-Preise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_menu_cards', function (Blueprint $table) {
            $table->string('preis_rundung', 12)->default('keine')->after('preis_anzeige_brutto');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_menu_cards', function (Blueprint $table) {
            $table->dropColumn('preis_rundung');
        });
    }
};
