<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_order_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_order_lines', 'invoice_qty_packs')) {
                $table->decimal('invoice_qty_packs', 10, 2)->nullable()->after('received_note');
            }
            if (! Schema::hasColumn('foodalchemist_order_lines', 'invoice_pack_price')) {
                $table->decimal('invoice_pack_price', 12, 4)->nullable()->after('invoice_qty_packs');
            }
            if (! Schema::hasColumn('foodalchemist_order_lines', 'invoice_checked_at')) {
                $table->dateTime('invoice_checked_at')->nullable()->after('invoice_pack_price');
            }
            if (! Schema::hasColumn('foodalchemist_order_lines', 'invoice_note')) {
                $table->string('invoice_note')->nullable()->after('invoice_checked_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_order_lines', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_order_lines', 'invoice_note')) {
                $table->dropColumn('invoice_note');
            }
            if (Schema::hasColumn('foodalchemist_order_lines', 'invoice_checked_at')) {
                $table->dropColumn('invoice_checked_at');
            }
            if (Schema::hasColumn('foodalchemist_order_lines', 'invoice_pack_price')) {
                $table->dropColumn('invoice_pack_price');
            }
            if (Schema::hasColumn('foodalchemist_order_lines', 'invoice_qty_packs')) {
                $table->dropColumn('invoice_qty_packs');
            }
        });
    }
};
