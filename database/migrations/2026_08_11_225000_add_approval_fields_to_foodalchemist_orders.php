<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_orders', 'approval_status')) {
                $table->string('approval_status', 24)->nullable()->after('payment_note');
            }
            if (! Schema::hasColumn('foodalchemist_orders', 'approval_requested_at')) {
                $table->dateTime('approval_requested_at')->nullable()->after('approval_status');
            }
            if (! Schema::hasColumn('foodalchemist_orders', 'approved_at')) {
                $table->dateTime('approved_at')->nullable()->after('approval_requested_at');
            }
            if (! Schema::hasColumn('foodalchemist_orders', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('foodalchemist_orders', 'approval_note')) {
                $table->text('approval_note')->nullable()->after('approved_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_orders', function (Blueprint $table) {
            foreach (['approval_note', 'approved_by', 'approved_at', 'approval_requested_at', 'approval_status'] as $column) {
                if (Schema::hasColumn('foodalchemist_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
