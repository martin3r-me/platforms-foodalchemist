<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_order_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_order_lines', 'received_qty_packs')) {
                $table->decimal('received_qty_packs', 10, 2)->nullable()->after('line_total');
            }
            if (! Schema::hasColumn('foodalchemist_order_lines', 'received_at')) {
                $table->dateTime('received_at')->nullable()->after('received_qty_packs');
            }
            if (! Schema::hasColumn('foodalchemist_order_lines', 'received_note')) {
                $table->string('received_note')->nullable()->after('received_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_order_lines', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_order_lines', 'received_note')) {
                $table->dropColumn('received_note');
            }
            if (Schema::hasColumn('foodalchemist_order_lines', 'received_at')) {
                $table->dropColumn('received_at');
            }
            if (Schema::hasColumn('foodalchemist_order_lines', 'received_qty_packs')) {
                $table->dropColumn('received_qty_packs');
            }
        });
    }
};
