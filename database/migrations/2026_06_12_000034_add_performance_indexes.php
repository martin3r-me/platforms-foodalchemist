<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M8-04: Performance-Indizes — `constrained()` legt (anders als MySQL-InnoDB)
 * in SQLite/Postgres KEINEN impliziten FK-Index an; der Lieferanten-Browser
 * zählte 264k Artikel per Full-Scan (403 ms). Plus gp_id-Pfad der
 * Verwendungs-Zählung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_supplier_items', function (Blueprint $table) {
            $table->index(['supplier_id', 'deleted_at']);
        });
        Schema::table('foodalchemist_recipe_ingredients', function (Blueprint $table) {
            $table->index(['gp_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_supplier_items', function (Blueprint $table) {
            $table->dropIndex(['supplier_id', 'deleted_at']);
        });
        Schema::table('foodalchemist_recipe_ingredients', function (Blueprint $table) {
            $table->dropIndex(['gp_id', 'deleted_at']);
        });
    }
};
