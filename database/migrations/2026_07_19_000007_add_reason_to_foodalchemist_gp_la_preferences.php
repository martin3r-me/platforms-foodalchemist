<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R9.2 (E5) — Begründung für einen manuellen Lead-LA-Override. Sitzt auf
 * gp_la_preferences (trägt LogsActivity) → die Override-Historie fällt automatisch
 * über das Activity-Log ab, ohne eigene History-Tabelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_gp_la_preferences', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_gp_la_preferences', 'reason')) {
                $table->string('reason')->nullable()->comment('Begründung des manuellen Lead-Overrides (R9.2 E5)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_gp_la_preferences', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_gp_la_preferences', 'reason')) {
                $table->dropColumn('reason');
            }
        });
    }
};
