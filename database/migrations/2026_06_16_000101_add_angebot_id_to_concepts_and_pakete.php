<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #380 — Ownership-Flag für die geteilte Bau-Substanz.
 *
 * Concepts & Pakete sind die Bau-Bausteine. Mit `offer_id` unterscheiden wir,
 * OHNE zweite Tabelle:
 *   - offer_id IS NULL  → STANDARDISIERT (Concepter-Katalog, wiederverwendbar)
 *   - offer_id IS NOT NULL → ANGEBOTS-LOKALER Entwurf (spekulativ, „mal eben schnell")
 *
 * „Promote to Concepter / live gehen" = Flip: offer_id auf NULL setzen (vom
 * Angebot lösen → wird standardisiert). Der Concepter-Browser filtert auf
 * offer_id IS NULL (Filter-Disziplin = Test-Pflichtfall, sonst lecken
 * Entwürfe in den Katalog).
 *
 * Engine-agnostisch (Martins 7-Punkte-Liste): KEIN ->after() (MySQL-only),
 * KEIN ALTER-add-FK (bricht SQLite) → schlichte indexierte Spalte; die Relation
 * lebt im Model (FoodAlchemistAngebot::concepts/pakete), Integrität app-seitig.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            $table->unsignedBigInteger('offer_id')->nullable()->index();
        });

        Schema::table('foodalchemist_packages', function (Blueprint $table) {
            $table->unsignedBigInteger('offer_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            $table->dropColumn('offer_id');
        });

        Schema::table('foodalchemist_packages', function (Blueprint $table) {
            $table->dropColumn('offer_id');
        });
    }
};
