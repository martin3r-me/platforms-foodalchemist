<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 69 · Eigenproduktion im Lager: Rezept als Lagerartikel (Basisrezept in g/Stk, Gericht in
 * Portionen) mit Chargen (Herstell-/Einfrierdatum, verbrauchen bis, Rest). Rein additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_inventory_batches')) {
            Schema::create('foodalchemist_inventory_batches', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('inventory_location_id')->index();
                $table->unsignedBigInteger('stock_id')->nullable()->index();
                $table->unsignedBigInteger('recipe_id')->index();
                $table->string('charge', 24);
                $table->date('produced_at');
                $table->date('frozen_at')->nullable();
                $table->date('best_before')->nullable()->index();
                $table->string('storage_type', 16)->default('gekuehlt');     // gekuehlt | tiefgekuehlt | trocken
                $table->decimal('qty_initial', 16, 4);
                $table->decimal('qty_rest', 16, 4);
                $table->string('base_unit', 16)->default('g');               // g | Stk | Port
                $table->decimal('price_per_base', 14, 6)->nullable();        // Rezept-EK beim Einlagern
                $table->unsignedBigInteger('production_order_line_id')->nullable()->index();
                $table->string('note', 255)->nullable();
                $table->dateTime('closed_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['team_id', 'charge'], 'fa_inv_batches_team_charge_unique');
            });
        }
        foreach (['foodalchemist_inventory_stocks', 'foodalchemist_inventory_movements', 'foodalchemist_inventory_count_lines'] as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                if (! Schema::hasColumn($t, 'recipe_id')) {
                    $table->unsignedBigInteger('recipe_id')->nullable()->index();
                }
            });
        }
        Schema::table('foodalchemist_inventory_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_inventory_movements', 'batch_id')) {
                $table->unsignedBigInteger('batch_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_inventory_movements', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropColumn('batch_id');
        });
        foreach (['foodalchemist_inventory_stocks', 'foodalchemist_inventory_movements', 'foodalchemist_inventory_count_lines'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropIndex(['recipe_id']);
                $table->dropColumn('recipe_id');
            });
        }
        Schema::dropIfExists('foodalchemist_inventory_batches');
    }
};
