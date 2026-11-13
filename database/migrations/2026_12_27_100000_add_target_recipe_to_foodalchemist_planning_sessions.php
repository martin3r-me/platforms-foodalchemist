<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Rezeptur erstellen" für ein vorhandenes Gericht (Dominique 13.11.): eine Planung kann für genau ein Gericht ohne
 * Komponenten (Hülle) die Rezeptur bauen. `target_recipe_id` = Ziel-Gericht (loser Zeiger, kein FK — Gerichte werden
 * gelöscht/zusammengeführt). Der Bau schreibt in dieses Gericht statt ein neues anzulegen
 * ({@see \Platform\FoodAlchemist\Services\GerichtHuelleService}). Additiv, kein Backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_planning_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_planning_sessions', 'target_recipe_id')) {
                $table->unsignedBigInteger('target_recipe_id')->nullable()->index('fa_plan_sess_target_recipe_ix');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_planning_sessions', 'target_recipe_id')) {
            Schema::table('foodalchemist_planning_sessions', function (Blueprint $table) {
                $table->dropIndex('fa_plan_sess_target_recipe_ix');
                $table->dropColumn('target_recipe_id');
            });
        }
    }
};
