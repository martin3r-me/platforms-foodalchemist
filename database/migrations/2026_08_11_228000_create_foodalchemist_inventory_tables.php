<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WaWi light: Lagerbestand und idempotente Lagerbewegungen aus dem Wareneingang.
 * Bestand entsteht erst bei gebuchtem Wareneingang, nicht bei Bestellerfassung.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_inventory_locations')) {
            Schema::create('foodalchemist_inventory_locations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->foreignId('team_id')->index();
                $table->string('name');
                $table->string('code')->nullable();
                $table->string('type', 32)->default('warehouse')->index();
                $table->boolean('is_default')->default(false)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->text('note')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['team_id', 'is_default']);
            });
        }

        if (! Schema::hasTable('foodalchemist_inventory_stocks')) {
            Schema::create('foodalchemist_inventory_stocks', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->foreignId('team_id')->index();
                $table->foreignId('inventory_location_id')->nullable()->index();
                $table->foreignId('gp_id')->nullable()->index();
                $table->foreignId('supplier_item_id')->nullable()->index();
                $table->decimal('qty_base', 16, 4)->default(0);
                $table->string('base_unit', 16)->default('g');
                $table->string('storage_location')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['team_id', 'inventory_location_id', 'gp_id', 'base_unit']);
                $table->index(['team_id', 'inventory_location_id', 'supplier_item_id', 'base_unit']);
            });
        }

        if (! Schema::hasTable('foodalchemist_inventory_movements')) {
            Schema::create('foodalchemist_inventory_movements', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->foreignId('team_id')->index();
                $table->foreignId('stock_id')->nullable()->index();
                $table->foreignId('inventory_location_id')->nullable()->index();
                $table->foreignId('gp_id')->nullable()->index();
                $table->foreignId('supplier_item_id')->nullable()->index();
                $table->foreignId('order_id')->nullable()->index();
                $table->foreignId('order_line_id')->nullable()->index();
                $table->string('direction', 16)->default('in')->index();
                $table->decimal('qty_base', 16, 4)->default(0);
                $table->string('base_unit', 16)->default('g');
                $table->decimal('qty_packs', 12, 2)->nullable();
                $table->string('source', 64)->default('wareneingang')->index();
                $table->string('source_hash', 80)->unique();
                $table->dateTime('moved_at')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_inventory_movements');
        Schema::dropIfExists('foodalchemist_inventory_stocks');
        Schema::dropIfExists('foodalchemist_inventory_locations');
    }
};
