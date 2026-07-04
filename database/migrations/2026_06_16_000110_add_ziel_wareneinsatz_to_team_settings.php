<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #379+ (Controlling-Zentrum): Ziel-Wareneinsatzquote (Food-Cost-%) als pflegbares
 * Team-Ziel. Treibt die Cockpit-Kennzahl, den Break-even (Σ Fixkosten ÷ (1 − WE-Quote))
 * und das Signal „Wareneinsatzquote über Ziel". Engine-agnostisch (kein ->after / FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'target_food_cost_pct')) {
                $table->decimal('target_food_cost_pct', 5, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->dropColumn('target_food_cost_pct');
        });
    }
};
