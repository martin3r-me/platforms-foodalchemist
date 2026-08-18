<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_production_orders', function (Blueprint $table) {
            $table->dateTime('procurement_released_at')->nullable()->after('handover_targets_hash');
            $table->unsignedBigInteger('procurement_released_by')->nullable()->after('procurement_released_at');
            $table->string('procurement_targets_hash', 64)->nullable()->after('procurement_released_by');
            $table->json('procurement_targets_snapshot')->nullable()->after('procurement_targets_hash');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_production_orders', function (Blueprint $table) {
            $table->dropColumn([
                'procurement_released_at',
                'procurement_released_by',
                'procurement_targets_hash',
                'procurement_targets_snapshot',
            ]);
        });
    }
};
