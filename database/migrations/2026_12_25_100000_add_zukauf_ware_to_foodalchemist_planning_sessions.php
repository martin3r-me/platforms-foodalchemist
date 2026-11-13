<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zukauf-Basisrezept aus der Planung (Dominique 13.11.): eine Session kann für genau eine gekaufte Ware (GP) ein
 * Zukauf-Basisrezept planen. `source_gp_id` = Herkunfts-GP (loser Zeiger, kein FK — GPs werden gemerged/gelöscht),
 * `zukauf_typ` = Basisrezept-Typ (§1.2), vorbelegt aus der Regel basisrezept.zukauf.typ oder im Reiter gewählt.
 * Nicht in generation_params: der Go überschreibt die Leitplanken mit den Reglern des Tabs. Additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_planning_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_planning_sessions', 'source_gp_id')) {
                $table->unsignedBigInteger('source_gp_id')->nullable()->index('fa_plan_sess_source_gp_ix');
            }
            if (! Schema::hasColumn('foodalchemist_planning_sessions', 'zukauf_typ')) {
                $table->string('zukauf_typ', 80)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_planning_sessions', 'source_gp_id')) {
            Schema::table('foodalchemist_planning_sessions', function (Blueprint $table) {
                $table->dropIndex('fa_plan_sess_source_gp_ix');
                $table->dropColumn('source_gp_id');
            });
        }
        if (Schema::hasColumn('foodalchemist_planning_sessions', 'zukauf_typ')) {
            Schema::table('foodalchemist_planning_sessions', fn (Blueprint $table) => $table->dropColumn('zukauf_typ'));
        }
    }
};
