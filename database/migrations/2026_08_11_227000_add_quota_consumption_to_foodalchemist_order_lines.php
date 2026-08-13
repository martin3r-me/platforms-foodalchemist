<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WaWi light: idempotenter Kontingentverbrauch je Bestellzeile.
 * Der Verbrauch wird aus Wareneingang/Nachlieferung gesteuert, nicht aus dem Draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_order_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_order_lines', 'quota_consumed_packs')) {
                $table->decimal('quota_consumed_packs', 12, 2)->nullable()->after('credit_expected_net');
            }
            if (! Schema::hasColumn('foodalchemist_order_lines', 'quota_consumed_at')) {
                $table->dateTime('quota_consumed_at')->nullable()->after('quota_consumed_packs');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_order_lines', function (Blueprint $table) {
            foreach (['quota_consumed_at', 'quota_consumed_packs'] as $column) {
                if (Schema::hasColumn('foodalchemist_order_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
