<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ebene 2: Küchen-Rollen-Sätze (€/Std je Rolle) je Betrieb überschreibbar.
 * Map {kitchen_role_id: stundensatz_eur}; fehlender Key = erbt den Team-Rollen-Satz,
 * fehlender Team-Satz = flacher Stundensatz (StationLaborRateService). JSON statt eigener
 * Tabelle → kein neues Model (PolicyTest), konsistent zu calculation_schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_outlet_settings', function (Blueprint $table) {
            $table->json('outlet_role_rates')->nullable()->after('labor_cost_source');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_outlet_settings', function (Blueprint $table) {
            $table->dropColumn('outlet_role_rates');
        });
    }
};
