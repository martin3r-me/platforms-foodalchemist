<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 57 · Paket 2 + 9: die Menü-Linie wird zur Ausgabestelle, der Plan kennt seine
 * Öffnungstage. Alles additiv und nullable — bestehende Pläne verhalten sich wie vorher
 * (keine Rolle, Team-Ziel als Wareneinsatz-Band, Preis vom Gericht, Mo–Fr, alle Mahlzeiten).
 *
 * - role: Suppe/Hauptgang/Salat/Beilage/Dessert/Sonstiges — trägt Tagesfuß (Gäste = Hauptgänge),
 *   Budget je Gast und später Aushang/Mengenplanung.
 * - plu: Kassen-/PLU-Nummer der Ausgabe (nur Anzeige/Export, keine Kassenanbindung).
 * - price_mode/price_value: wie die Speisekarte-Position. `auto` = VK des Gerichts (Standard),
 *   `manuell` = Linienpreis (netto) für Anzeige und Kennzahlen. Das Gericht behält seinen VK.
 * - target_wes_min_pct/target_wes_max_pct: Zielband Wareneinsatz. Leer = Team-/Betriebs-Ziel.
 * - default_pax: Standard-Essen je Tag dieser Linie (vor dem Plan-Default).
 * - is_standing: Dauerangebot (Salatbar …) — zählt nicht für die Wiederholungsregel.
 * - meal: Linie gilt nur für diese Mahlzeit. Leer = für alle Mahlzeiten (Altverhalten).
 * - menu_plans.opening_days: ISO-Wochentage (1=Mo … 7=So). Leer = Mo–Fr.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_menu_plan_lines', function (Blueprint $table) {
            $table->string('role', 20)->nullable()->after('is_vegetarian');
            $table->string('plu', 32)->nullable()->after('role');
            $table->string('price_mode', 10)->default('auto')->after('plu');
            $table->decimal('price_value', 8, 2)->nullable()->after('price_mode');
            $table->decimal('target_wes_min_pct', 5, 2)->nullable()->after('price_value');
            $table->decimal('target_wes_max_pct', 5, 2)->nullable()->after('target_wes_min_pct');
            $table->unsignedInteger('default_pax')->nullable()->after('target_wes_max_pct');
            $table->boolean('is_standing')->default(false)->after('default_pax');
            $table->string('meal', 20)->nullable()->after('is_standing');
        });

        Schema::table('foodalchemist_menu_plans', function (Blueprint $table) {
            $table->json('opening_days')->nullable()->after('min_abstand_tage');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_menu_plan_lines', function (Blueprint $table) {
            $table->dropColumn(['role', 'plu', 'price_mode', 'price_value', 'target_wes_min_pct', 'target_wes_max_pct', 'default_pax', 'is_standing', 'meal']);
        });

        Schema::table('foodalchemist_menu_plans', function (Blueprint $table) {
            $table->dropColumn('opening_days');
        });
    }
};
