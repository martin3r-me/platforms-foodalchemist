<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_ai_call_log', function (Blueprint $table) {
            $table->unsignedInteger('tokens_cached')->nullable()->after('tokens_in');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_ai_call_log', function (Blueprint $table) {
            $table->dropColumn('tokens_cached');
        });
    }
};
