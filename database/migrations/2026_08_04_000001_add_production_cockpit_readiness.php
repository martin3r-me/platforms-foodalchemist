<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
            $table->string('blocked_reason', 80)->nullable();
            $table->text('blocked_note')->nullable();
            $table->string('skipped_reason', 80)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->unsignedBigInteger('started_by')->nullable();
        });

        Schema::create('foodalchemist_production_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('line_id')->nullable();
            $table->string('event_type', 60);
            $table->string('from_state', 40)->nullable();
            $table->string('to_state', 40)->nullable();
            $table->string('reason_code', 80)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['team_id', 'created_at'], 'fa_prod_events_team_created_idx');
            $table->index(['order_id', 'created_at'], 'fa_prod_events_order_created_idx');
            $table->index(['line_id', 'created_at'], 'fa_prod_events_line_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_production_events');
        Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
            $table->dropColumn(['blocked_reason', 'blocked_note', 'skipped_reason', 'started_at', 'started_by']);
        });
    }
};
