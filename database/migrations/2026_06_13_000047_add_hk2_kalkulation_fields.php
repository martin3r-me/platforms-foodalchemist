<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M12-01 / Doc 15 §M12 (D-HK-1, entschieden): HK2 als Zuschlagskalkulation.
 *   HK1 = Wareneinsatz, verlustkorrigiert (existiert: recipes.ek_total_eur, GL-02).
 *   HK2 = HK1 × (1 + Gemeinkosten-Zuschlag%) + Energie-/Nebenkosten.
 * - team_settings.hk2_surcharge_pct: EIN Pauschal-Zuschlagssatz (anfangs grob; später
 *   differenziert nach Garmethode → M15).
 * - recipes.additional_costs_eur: Energie-/Nebenkosten je Charge (grob geschätzt; null=0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->decimal('hk2_surcharge_pct', 7, 2)->default(0)->after('rundungsregeln');
        });
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->decimal('additional_costs_eur', 10, 4)->nullable();          // Energie/Nebenkosten je Charge (HK2)
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->dropColumn('hk2_surcharge_pct');
        });
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropColumn('additional_costs_eur');
        });
    }
};
