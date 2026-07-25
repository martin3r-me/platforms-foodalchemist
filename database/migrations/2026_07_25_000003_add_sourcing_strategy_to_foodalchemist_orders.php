<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 20 · E3 — „Neu quellen": Override der Lead-LA-Preisstrategie PRO Bestellschiene.
 * NULL = Team-Haupteinstellung (TeamSettingsService::leadLaStrategie), sonst einer der
 * LeadLaStrategie-Werte (guenstigster_preis|stamm_lieferant|prioritaets_kette).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_orders', 'sourcing_strategy')) {
                $table->string('sourcing_strategy')->nullable()->after('desired_delivery_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_orders', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_orders', 'sourcing_strategy')) {
                $table->dropColumn('sourcing_strategy');
            }
        });
    }
};
