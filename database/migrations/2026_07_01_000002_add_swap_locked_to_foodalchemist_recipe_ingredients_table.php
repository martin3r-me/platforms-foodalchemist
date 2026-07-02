<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rezept-Override für die Ersatz-Rezept-Logik: swap_locked fixiert die in DIESEM
 * Rezept gewählte Realisierung (Fertig ODER Selbst) gegen eine Bulk-Umschaltung.
 * Der eigentliche Override IST die Zutat-FK (gp_id XOR referenced_recipe_id) — dieses
 * Flag schützt sie nur vor globalen Swaps ("dieses Gericht IMMER selbstgemacht").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipe_ingredients', function (Blueprint $table) {
            $table->boolean('swap_locked')->default(false)->after('match_method');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipe_ingredients', function (Blueprint $table) {
            $table->dropColumn('swap_locked');
        });
    }
};
