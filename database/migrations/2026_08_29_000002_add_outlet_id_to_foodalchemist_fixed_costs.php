<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ebene 2: Betriebs-scopte Fixkosten. `outlet_id` NULL = Team-Fixkosten (heute, unverändert);
 * gesetzt = Override je Betrieb (Per-Block-Replace in FixkostenService::summeJeBlock).
 * Weicher Verweis (kein harter FK — konform zum Outlet-Referenz-Muster, SQLite-Alter-safe).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_fixed_costs', 'outlet_id')) {
            Schema::table('foodalchemist_fixed_costs', function (Blueprint $table) {
                $table->unsignedBigInteger('outlet_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_fixed_costs', 'outlet_id')) {
            Schema::table('foodalchemist_fixed_costs', function (Blueprint $table) {
                $table->dropColumn('outlet_id');
            });
        }
    }
};
