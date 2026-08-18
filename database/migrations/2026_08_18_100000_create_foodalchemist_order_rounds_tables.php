<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_order_rounds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->string('label')->nullable();
            $table->date('desired_delivery_date')->nullable();
            $table->string('sourcing_strategy', 32)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('foodalchemist_order_round_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('round_id')->constrained('foodalchemist_order_rounds')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('foodalchemist_orders')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['round_id', 'order_id'], 'fa_round_order_unique');
            $table->index('order_id', 'fa_round_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_order_round_links');
        Schema::dropIfExists('foodalchemist_order_rounds');
    }
};
