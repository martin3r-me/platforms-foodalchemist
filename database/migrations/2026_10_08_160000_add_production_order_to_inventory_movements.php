<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 72 · Produktion bucht aus dem Lager: Bezug Bewegung → Produktionsauftrag (Nachweis + Idempotenz).
 * Rein additiv, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_inventory_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_inventory_movements', 'production_order_id')) {
                $table->unsignedBigInteger('production_order_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_inventory_movements', function (Blueprint $table) {
            $table->dropIndex(['production_order_id']);
            $table->dropColumn('production_order_id');
        });
    }
};
