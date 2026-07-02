<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #383 — Pax-getriebene Angebots-Kalkulation: Preis-Modus am Angebot.
 *   auto    = Gesamtpreis = Σ(Menü-€/Person) × Pax (aus den Concepts, ConceptService/KalkulationService)
 *   manuell = gesamtpreis ist ein fixer Override (Verkäufer setzt den Preis)
 *
 * Engine-agnostisch (kein ->after()/CHECK; Wert-Domäne im PHP-Layer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_angebote', function (Blueprint $table) {
            $table->string('preis_modus', 12)->default('auto');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_angebote', function (Blueprint $table) {
            $table->dropColumn('preis_modus');
        });
    }
};
