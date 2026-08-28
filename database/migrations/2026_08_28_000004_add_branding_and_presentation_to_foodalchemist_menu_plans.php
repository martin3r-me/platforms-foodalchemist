<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 43 (Phase 3) — Speiseplan (foodalchemist_menu_plans) bekommt BEIDES neu: Branding
 * (fehlte bisher ganz, gespiegelt aus foodbooks) + die presentation_*-Spalten. GV-Aushang
 * = Kiosk-Default. Pflicht-Datum wird beim Publish erzwungen (Schema nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_menu_plans')) {
            return;
        }
        Schema::table('foodalchemist_menu_plans', function (Blueprint $table) {
            // Branding (neu für Speiseplan)
            if (! Schema::hasColumn('foodalchemist_menu_plans', 'brand_color')) {
                $table->string('brand_color', 9)->default('#6d28d9')->after('note');
            }
            if (! Schema::hasColumn('foodalchemist_menu_plans', 'band_color')) {
                $table->string('band_color', 9)->nullable()->after('brand_color');
            }
            if (! Schema::hasColumn('foodalchemist_menu_plans', 'logo_path')) {
                $table->string('logo_path')->nullable()->after('band_color');
            }
            if (! Schema::hasColumn('foodalchemist_menu_plans', 'cover_image_path')) {
                $table->string('cover_image_path')->nullable()->after('logo_path');
            }
            if (! Schema::hasColumn('foodalchemist_menu_plans', 'footer_text')) {
                $table->text('footer_text')->nullable()->after('cover_image_path');
            }
            if (! Schema::hasColumn('foodalchemist_menu_plans', 'logo_context_file_id')) {
                $table->unsignedBigInteger('logo_context_file_id')->nullable()->after('footer_text');
            }
            if (! Schema::hasColumn('foodalchemist_menu_plans', 'cover_context_file_id')) {
                $table->unsignedBigInteger('cover_context_file_id')->nullable()->after('logo_context_file_id');
            }
            // Präsentation
            if (! Schema::hasColumn('foodalchemist_menu_plans', 'presentation_enabled')) {
                $table->boolean('presentation_enabled')->default(false)->after('cover_context_file_id');
            }
            if (! Schema::hasColumn('foodalchemist_menu_plans', 'presentation_token')) {
                $table->string('presentation_token', 64)->nullable()->unique()->after('presentation_enabled');
            }
            if (! Schema::hasColumn('foodalchemist_menu_plans', 'presentation_design')) {
                $table->string('presentation_design', 48)->default('kiosk')->after('presentation_token');
            }
            if (! Schema::hasColumn('foodalchemist_menu_plans', 'presentation_published_at')) {
                $table->timestamp('presentation_published_at')->nullable()->after('presentation_design');
            }
            if (! Schema::hasColumn('foodalchemist_menu_plans', 'presentation_published_by')) {
                $table->unsignedBigInteger('presentation_published_by')->nullable()->after('presentation_published_at');
            }
            if (! Schema::hasColumn('foodalchemist_menu_plans', 'presentation_expires_at')) {
                $table->timestamp('presentation_expires_at')->nullable()->after('presentation_published_by');
            }
            if (! Schema::hasColumn('foodalchemist_menu_plans', 'presentation_snapshot_json')) {
                $table->json('presentation_snapshot_json')->nullable()->after('presentation_expires_at');
            }
            if (! Schema::hasColumn('foodalchemist_menu_plans', 'presentation_settings_json')) {
                $table->json('presentation_settings_json')->nullable()->after('presentation_snapshot_json');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_menu_plans')) {
            return;
        }
        Schema::table('foodalchemist_menu_plans', function (Blueprint $table) {
            foreach ([
                'brand_color', 'band_color', 'logo_path', 'cover_image_path', 'footer_text',
                'logo_context_file_id', 'cover_context_file_id',
                'presentation_enabled', 'presentation_token', 'presentation_design',
                'presentation_published_at', 'presentation_published_by', 'presentation_expires_at',
                'presentation_snapshot_json', 'presentation_settings_json',
            ] as $col) {
                if (Schema::hasColumn('foodalchemist_menu_plans', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
