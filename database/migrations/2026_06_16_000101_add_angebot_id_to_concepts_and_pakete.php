<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #380 — Ownership-Flag für die geteilte Bau-Substanz.
 *
 * Concepts & Pakete sind die Bau-Bausteine. Mit `angebot_id` unterscheiden wir,
 * OHNE zweite Tabelle:
 *   - angebot_id IS NULL  → STANDARDISIERT (Concepter-Katalog, wiederverwendbar)
 *   - angebot_id IS NOT NULL → ANGEBOTS-LOKALER Entwurf (spekulativ, „mal eben schnell")
 *
 * „Promote to Concepter / live gehen" = Flip: angebot_id auf NULL setzen (vom
 * Angebot lösen → wird standardisiert). Der Concepter-Browser filtert auf
 * angebot_id IS NULL (Filter-Disziplin = Test-Pflichtfall, sonst lecken
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
            $table->unsignedBigInteger('angebot_id')->nullable()->index();
        });

        Schema::table('foodalchemist_pakete', function (Blueprint $table) {
            $table->unsignedBigInteger('angebot_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            $table->dropColumn('angebot_id');
        });

        Schema::table('foodalchemist_pakete', function (Blueprint $table) {
            $table->dropColumn('angebot_id');
        });
    }
};
