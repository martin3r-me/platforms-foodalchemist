<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_orders', 'payment_status')) {
                $table->string('payment_status', 24)->nullable()->after('invoice_note');
            }
            if (! Schema::hasColumn('foodalchemist_orders', 'invoice_paid_at')) {
                $table->date('invoice_paid_at')->nullable()->after('payment_status');
            }
            if (! Schema::hasColumn('foodalchemist_orders', 'payment_note')) {
                $table->text('payment_note')->nullable()->after('invoice_paid_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_orders', function (Blueprint $table) {
            foreach (['payment_note', 'invoice_paid_at', 'payment_status'] as $column) {
                if (Schema::hasColumn('foodalchemist_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
