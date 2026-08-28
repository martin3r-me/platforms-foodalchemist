<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 43 — Präsentation als digitales, konfigurierbares Kundenbuch.
 * Additive Public-Share-/Snapshot-Spalten am Foodbook-Kopf. Semantik gespiegelt aus
 * platforms-core CorePublicFormLink (token + enabled + expires_at) + der Snapshot-
 * Präzedenz preview_snapshot_json. Pflicht-Datum wird beim Publish erzwungen, nicht
 * im Schema (expires_at bleibt nullable für Entwurf/zurückgezogen).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_foodbooks')) {
            return;
        }
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'presentation_enabled')) {
                $table->boolean('presentation_enabled')->default(false)->after('preview_snapshot_at');
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'presentation_token')) {
                $table->string('presentation_token', 64)->nullable()->unique()->after('presentation_enabled');
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'presentation_design')) {
                $table->string('presentation_design', 48)->default('editorial')->after('presentation_token');
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'presentation_published_at')) {
                $table->timestamp('presentation_published_at')->nullable()->after('presentation_design');
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'presentation_published_by')) {
                $table->unsignedBigInteger('presentation_published_by')->nullable()->after('presentation_published_at');
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'presentation_expires_at')) {
                $table->timestamp('presentation_expires_at')->nullable()->after('presentation_published_by');
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'presentation_snapshot_json')) {
                $table->json('presentation_snapshot_json')->nullable()->after('presentation_expires_at');
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'presentation_settings_json')) {
                $table->json('presentation_settings_json')->nullable()->after('presentation_snapshot_json');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_foodbooks')) {
            return;
        }
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            foreach ([
                'presentation_enabled',
                'presentation_token',
                'presentation_design',
                'presentation_published_at',
                'presentation_published_by',
                'presentation_expires_at',
                'presentation_snapshot_json',
                'presentation_settings_json',
            ] as $col) {
                if (Schema::hasColumn('foodalchemist_foodbooks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
