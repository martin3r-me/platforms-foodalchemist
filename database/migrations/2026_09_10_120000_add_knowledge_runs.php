<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('foodalchemist_knowledge_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('team_id')->index();
            $table->char('snapshot_hash', 64);
            $table->json('snapshot');
            $table->timestamp('created_at');
        });
        Schema::table('foodalchemist_recipes', fn (Blueprint $table) => $table->uuid('knowledge_run_id')->nullable()->index());
        Schema::table('foodalchemist_ai_call_log', function (Blueprint $table) {
            $table->uuid('knowledge_run_id')->nullable()->index();
            $table->char('knowledge_snapshot_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_ai_call_log', function (Blueprint $table) {
            $table->dropIndex(['knowledge_run_id']);
            $table->dropColumn(['knowledge_run_id', 'knowledge_snapshot_hash']);
        });
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropIndex(['knowledge_run_id']);
            $table->dropColumn('knowledge_run_id');
        });
        Schema::dropIfExists('foodalchemist_knowledge_runs');
    }
};
