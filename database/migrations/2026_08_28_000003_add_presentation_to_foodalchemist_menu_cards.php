<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 43 (Phase 2) — Präsentations-Spalten am Speisekarten-Kopf (foodalchemist_menu_cards),
 * identisch zu den Foodbook-Spalten. Pflicht-Datum wird beim Publish erzwungen (Schema nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_menu_cards')) {
            return;
        }
        Schema::table('foodalchemist_menu_cards', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_menu_cards', 'presentation_enabled')) {
                $table->boolean('presentation_enabled')->default(false)->after('footer_text');
            }
            if (! Schema::hasColumn('foodalchemist_menu_cards', 'presentation_token')) {
                $table->string('presentation_token', 64)->nullable()->unique()->after('presentation_enabled');
            }
            if (! Schema::hasColumn('foodalchemist_menu_cards', 'presentation_design')) {
                $table->string('presentation_design', 48)->default('menu')->after('presentation_token');
            }
            if (! Schema::hasColumn('foodalchemist_menu_cards', 'presentation_published_at')) {
                $table->timestamp('presentation_published_at')->nullable()->after('presentation_design');
            }
            if (! Schema::hasColumn('foodalchemist_menu_cards', 'presentation_published_by')) {
                $table->unsignedBigInteger('presentation_published_by')->nullable()->after('presentation_published_at');
            }
            if (! Schema::hasColumn('foodalchemist_menu_cards', 'presentation_expires_at')) {
                $table->timestamp('presentation_expires_at')->nullable()->after('presentation_published_by');
            }
            if (! Schema::hasColumn('foodalchemist_menu_cards', 'presentation_snapshot_json')) {
                $table->json('presentation_snapshot_json')->nullable()->after('presentation_expires_at');
            }
            if (! Schema::hasColumn('foodalchemist_menu_cards', 'presentation_settings_json')) {
                $table->json('presentation_settings_json')->nullable()->after('presentation_snapshot_json');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_menu_cards')) {
            return;
        }
        Schema::table('foodalchemist_menu_cards', function (Blueprint $table) {
            foreach ([
                'presentation_enabled', 'presentation_token', 'presentation_design',
                'presentation_published_at', 'presentation_published_by', 'presentation_expires_at',
                'presentation_snapshot_json', 'presentation_settings_json',
            ] as $col) {
                if (Schema::hasColumn('foodalchemist_menu_cards', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
