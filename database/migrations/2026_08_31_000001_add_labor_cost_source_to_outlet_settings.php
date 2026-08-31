<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ebene 2: Lohnquelle (team_flat|station_roles) je Betrieb überschreibbar.
 * Bisher nur team-weit (team_settings.labor_cost_source). Nullable = erbt vom Team;
 * Auflösung Outlet → Team → 'team_flat' (TeamSettingsService::laborCostSource(?outlet)).
 * Der flache Stundensatz (stundensatz_eur) ist schon je Betrieb überschreibbar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_outlet_settings', function (Blueprint $table) {
            $table->string('labor_cost_source', 32)->nullable()->after('labor_overhead_pct');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_outlet_settings', function (Blueprint $table) {
            $table->dropColumn('labor_cost_source');
        });
    }
};
