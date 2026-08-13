<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_order_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_order_lines', 'claim_status')) {
                $table->string('claim_status', 24)->nullable()->after('invoice_note');
            }
            if (! Schema::hasColumn('foodalchemist_order_lines', 'claim_qty_packs')) {
                $table->decimal('claim_qty_packs', 10, 2)->nullable()->after('claim_status');
            }
            if (! Schema::hasColumn('foodalchemist_order_lines', 'credit_expected_net')) {
                $table->decimal('credit_expected_net', 12, 2)->nullable()->after('claim_qty_packs');
            }
            if (! Schema::hasColumn('foodalchemist_order_lines', 'claim_note')) {
                $table->string('claim_note')->nullable()->after('credit_expected_net');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_order_lines', function (Blueprint $table) {
            foreach (['claim_note', 'credit_expected_net', 'claim_qty_packs', 'claim_status'] as $column) {
                if (Schema::hasColumn('foodalchemist_order_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
