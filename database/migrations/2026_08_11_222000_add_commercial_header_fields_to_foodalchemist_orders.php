<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_orders', 'supplier_order_number')) {
                $table->string('supplier_order_number')->nullable()->after('confirmed_at');
            }
            if (! Schema::hasColumn('foodalchemist_orders', 'confirmed_delivery_date')) {
                $table->date('confirmed_delivery_date')->nullable()->after('supplier_order_number');
            }
            if (! Schema::hasColumn('foodalchemist_orders', 'supplier_confirmation_note')) {
                $table->text('supplier_confirmation_note')->nullable()->after('confirmed_delivery_date');
            }
            if (! Schema::hasColumn('foodalchemist_orders', 'invoice_number')) {
                $table->string('invoice_number')->nullable()->after('delivered_at');
            }
            if (! Schema::hasColumn('foodalchemist_orders', 'invoice_date')) {
                $table->date('invoice_date')->nullable()->after('invoice_number');
            }
            if (! Schema::hasColumn('foodalchemist_orders', 'invoice_note')) {
                $table->text('invoice_note')->nullable()->after('invoice_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_orders', function (Blueprint $table) {
            foreach (['invoice_note', 'invoice_date', 'invoice_number', 'supplier_confirmation_note', 'confirmed_delivery_date', 'supplier_order_number'] as $column) {
                if (Schema::hasColumn('foodalchemist_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
