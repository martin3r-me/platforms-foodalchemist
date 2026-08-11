<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_recipe_step_photos')
            && ! Schema::hasColumn('foodalchemist_recipe_step_photos', 'context_file_id')) {
            Schema::table('foodalchemist_recipe_step_photos', function (Blueprint $table) {
                $table->foreignId('context_file_id')->nullable()->after('pfad')
                    ->constrained('context_files')->nullOnDelete();
            });
        }

        if (Schema::hasTable('foodalchemist_foodbooks')) {
            Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
                if (! Schema::hasColumn('foodalchemist_foodbooks', 'logo_context_file_id')) {
                    $table->foreignId('logo_context_file_id')->nullable()->after('logo_path')
                        ->constrained('context_files')->nullOnDelete();
                }
                if (! Schema::hasColumn('foodalchemist_foodbooks', 'cover_context_file_id')) {
                    $table->foreignId('cover_context_file_id')->nullable()->after('cover_image_path')
                        ->constrained('context_files')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('foodalchemist_menu_cards')) {
            Schema::table('foodalchemist_menu_cards', function (Blueprint $table) {
                if (! Schema::hasColumn('foodalchemist_menu_cards', 'logo_context_file_id')) {
                    $table->foreignId('logo_context_file_id')->nullable()->after('logo_path')
                        ->constrained('context_files')->nullOnDelete();
                }
                if (! Schema::hasColumn('foodalchemist_menu_cards', 'cover_context_file_id')) {
                    $table->foreignId('cover_context_file_id')->nullable()->after('cover_image_path')
                        ->constrained('context_files')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('foodalchemist_menu_cards')) {
            Schema::table('foodalchemist_menu_cards', function (Blueprint $table) {
                if (Schema::hasColumn('foodalchemist_menu_cards', 'cover_context_file_id')) {
                    $table->dropConstrainedForeignId('cover_context_file_id');
                }
                if (Schema::hasColumn('foodalchemist_menu_cards', 'logo_context_file_id')) {
                    $table->dropConstrainedForeignId('logo_context_file_id');
                }
            });
        }

        if (Schema::hasTable('foodalchemist_foodbooks')) {
            Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
                if (Schema::hasColumn('foodalchemist_foodbooks', 'cover_context_file_id')) {
                    $table->dropConstrainedForeignId('cover_context_file_id');
                }
                if (Schema::hasColumn('foodalchemist_foodbooks', 'logo_context_file_id')) {
                    $table->dropConstrainedForeignId('logo_context_file_id');
                }
            });
        }

        if (Schema::hasTable('foodalchemist_recipe_step_photos')
            && Schema::hasColumn('foodalchemist_recipe_step_photos', 'context_file_id')) {
            Schema::table('foodalchemist_recipe_step_photos', function (Blueprint $table) {
                $table->dropConstrainedForeignId('context_file_id');
            });
        }
    }
};
