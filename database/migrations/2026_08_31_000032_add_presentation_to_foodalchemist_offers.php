<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #380 Composer / Spec 43 — Präsentation + Branding fürs Angebot (digitales Kundenbuch,
 * wie Foodbook/Speisekarte/Speiseplan). Additiv/nullable, rückwärtskompatibel. presentation_*
 * spiegelt 2026_08_28_000001 (foodbooks), Branding spiegelt 2026_07_21_000001 + context_file_ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_offers', function (Blueprint $table) {
            // ── Präsentation (Public-Link + Snapshot + Freigabe) ──
            if (! Schema::hasColumn('foodalchemist_offers', 'presentation_enabled')) {
                $table->boolean('presentation_enabled')->default(false);
                $table->string('presentation_token', 64)->nullable()->unique();
                $table->string('presentation_slug')->nullable()->unique('foodalchemist_offers_pres_slug_unique');
                $table->string('presentation_design', 48)->default('editorial');
                $table->timestamp('presentation_published_at')->nullable();
                $table->unsignedBigInteger('presentation_published_by')->nullable();
                $table->timestamp('presentation_expires_at')->nullable();
                $table->json('presentation_snapshot_json')->nullable();
                $table->json('presentation_settings_json')->nullable();
            }
            // ── Branding (fürs Kundenbuch + die Angebots-Karte) ──
            if (! Schema::hasColumn('foodalchemist_offers', 'brand_color')) {
                $table->string('brand_color')->default('#6d28d9');
                $table->string('band_color')->nullable();
                $table->string('logo_path')->nullable();
                $table->string('cover_image_path')->nullable();
                $table->string('footer_text')->nullable();
                $table->unsignedBigInteger('logo_context_file_id')->nullable();
                $table->unsignedBigInteger('cover_context_file_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_offers', function (Blueprint $table) {
            foreach ([
                'presentation_enabled', 'presentation_token', 'presentation_slug', 'presentation_design',
                'presentation_published_at', 'presentation_published_by', 'presentation_expires_at',
                'presentation_snapshot_json', 'presentation_settings_json',
                'brand_color', 'band_color', 'logo_path', 'cover_image_path', 'footer_text',
                'logo_context_file_id', 'cover_context_file_id',
            ] as $col) {
                if (Schema::hasColumn('foodalchemist_offers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
