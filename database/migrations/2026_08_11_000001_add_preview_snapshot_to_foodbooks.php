<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            $table->json('preview_snapshot_json')->nullable()->after('note');
            $table->timestamp('preview_snapshot_at')->nullable()->after('preview_snapshot_json');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            $table->dropColumn(['preview_snapshot_json', 'preview_snapshot_at']);
        });
    }
};
