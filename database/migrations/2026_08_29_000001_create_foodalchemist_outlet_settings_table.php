<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ebene 2 (Betriebs-/Kunden-Kalkulation): Outlet-Override der Kalkulations-Skalare.
 * Eine Zeile je Betrieb (Outlet), ALLE Spalten nullable = „erben vom Team". Spaltennamen
 * 1:1 wie foodalchemist_team_settings, damit der Resolver spaltenweise projizieren kann.
 * Auflösung: Outlet → Team → Code-Default (TeamSettingsService::skalar). Additiv/reversibel —
 * ohne Zeile / ohne aktiven Betrieb rechnet alles wie heute (Team-Ebene).
 * Preisklassen bleiben team-geteilt: hier bewusst NICHT default_markup_class_id/vat/rundung.
 * Weicher outlet_id-Verweis (kein harter FK — konform zum Outlet-Referenz-Muster).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_outlet_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('outlet_id')->unique();       // genau EINE Override-Zeile je Betrieb
            $table->unsignedBigInteger('team_id')->index();          // denormalisiert für den Tenancy-Guard
            $table->decimal('margin_pct', 6, 2)->nullable();
            $table->decimal('target_food_cost_pct', 6, 2)->nullable();
            $table->decimal('stundensatz_eur', 10, 2)->nullable();
            $table->json('calculation_schema')->nullable();           // ganzes Schema ersetzt Team-Schema
            $table->json('calculation_reference_bases')->nullable();
            $table->decimal('hk2_surcharge_pct', 6, 2)->nullable();
            $table->decimal('labor_overhead_pct', 6, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_outlet_settings');
    }
};
