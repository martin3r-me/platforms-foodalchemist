<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'default_markup_class_id')) {
                $table->foreignId('default_markup_class_id')->nullable()
                    ->constrained('foodalchemist_markup_classes')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_team_settings', 'default_markup_class_id')) {
                $table->dropConstrainedForeignId('default_markup_class_id');
            }
        });
    }
};
