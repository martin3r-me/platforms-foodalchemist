<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 22 · H2d — V-014, Money-Path-Hälfte: „bepreist" bekommt eine Herkunft.
 *
 * Bis hier gibt es kein Feld, das „über den gewählten Artikel bepreist" von „über einen
 * Lieferanten-Durchschnitt bepreist" unterscheidet — `ek_total_eur` sieht in beiden Fällen
 * gleich aus, und der Durchschnitt ist damit eine unsichtbare Schätzung in jeder Marge,
 * jedem Aufschlag, jedem Angebot. Wertevorrat + Aggregations-Regel stehen im PHP-Enum
 * `Platform\FoodAlchemist\Enums\EkPriceBasis` (nicht in diesem Kommentar — Lehre aus V-020).
 *
 * Bewusst KEIN Backfill: die Basis fällt nur aus der T3-Kaskade selbst ab, sie ist aus dem
 * gespeicherten Zustand nicht rekonstruierbar. NULL heißt darum entweder „kein EK" oder
 * „vor diesem Feld gerechnet"; beide Lagen löst der nächste Recompute auf
 * (`foodalchemist:recompute-recipes`), und bis dahin deckelt die Vererbung ein Eltern-Rezept
 * ehrlich auf `unknown` statt eine Basis zu erfinden.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('foodalchemist_recipes', 'ek_price_basis')) {
            return;
        }
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->string('ek_price_basis', 16)->nullable()->after('ek_per_kg_eur');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('foodalchemist_recipes', 'ek_price_basis')) {
            return;
        }
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropColumn('ek_price_basis');
        });
    }
};
