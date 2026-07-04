<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #379+ (Controlling-Zentrum): Lohnnebenkosten-Zuschlag % — Arbeitgeber-/Sozialabgaben
 * auf den Produktions-Stundenlohn (z. B. +22 %), damit der ECHTE Personalkostensatz
 * statt nur des Brutto-Lohns in HK2 fließt. Default 0 = rückwärtskompatibel.
 * Engine-agnostisch (kein ->after / FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'labor_overhead_pct')) {
                $table->decimal('labor_overhead_pct', 5, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->dropColumn('labor_overhead_pct');
        });
    }
};
