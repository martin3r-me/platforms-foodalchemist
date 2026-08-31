<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A1 (Angebot-Fork) — Enrichment-Schema für Foodbook-Parität des Angebot-Composers.
 * Spiegelt die über mehrere Foodbook-Migrationen gewachsenen Kapitel-/Block-Felder
 * (Struktur, Fortschritt, Bild, Serviermoment/Schreibstil, Kreativ-/Preis-Anker) und
 * legt die kleine Kapitel-Galerie foodalchemist_offer_chapter_images an (analog
 * foodalchemist_foodbook_chapter_images). Idempotent per hasColumn/hasTable-Guards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_offer_chapters')) {
            Schema::table('foodalchemist_offer_chapters', function (Blueprint $table) {
                if (! Schema::hasColumn('foodalchemist_offer_chapters', 'is_struktur')) {
                    $table->boolean('is_struktur')->default(false);
                }
                if (! Schema::hasColumn('foodalchemist_offer_chapters', 'fortschritt')) {
                    $table->string('fortschritt', 12)->default('offen');
                }
                if (! Schema::hasColumn('foodalchemist_offer_chapters', 'image_context_file_id')) {
                    $table->unsignedBigInteger('image_context_file_id')->nullable();
                }
                if (! Schema::hasColumn('foodalchemist_offer_chapters', 'image_path')) {
                    $table->string('image_path')->nullable();
                }
                if (! Schema::hasColumn('foodalchemist_offer_chapters', 'service_moment_id')) {
                    $table->unsignedBigInteger('service_moment_id')->nullable()->index();
                }
                if (! Schema::hasColumn('foodalchemist_offer_chapters', 'writing_style_id')) {
                    $table->unsignedBigInteger('writing_style_id')->nullable()->index();
                }
                if (! Schema::hasColumn('foodalchemist_offer_chapters', 'target_count')) {
                    $table->integer('target_count')->nullable();
                }
                if (! Schema::hasColumn('foodalchemist_offer_chapters', 'price_anchor')) {
                    $table->decimal('price_anchor', 10, 2)->nullable();
                }
                if (! Schema::hasColumn('foodalchemist_offer_chapters', 'price_min')) {
                    $table->decimal('price_min', 10, 2)->nullable();
                }
                if (! Schema::hasColumn('foodalchemist_offer_chapters', 'price_max')) {
                    $table->decimal('price_max', 10, 2)->nullable();
                }
                if (! Schema::hasColumn('foodalchemist_offer_chapters', 'target_food_cost_pct')) {
                    $table->decimal('target_food_cost_pct', 5, 2)->nullable();
                }
                if (! Schema::hasColumn('foodalchemist_offer_chapters', 'creative_mode')) {
                    $table->string('creative_mode', 16)->nullable();
                }
            });
        }

        if (Schema::hasTable('foodalchemist_offer_blocks')) {
            Schema::table('foodalchemist_offer_blocks', function (Blueprint $table) {
                if (! Schema::hasColumn('foodalchemist_offer_blocks', 'variant_group_id')) {
                    $table->unsignedInteger('variant_group_id')->nullable();
                }
                if (! Schema::hasColumn('foodalchemist_offer_blocks', 'header_source')) {
                    $table->string('header_source', 48)->nullable();
                }
            });
        }

        if (! Schema::hasTable('foodalchemist_offer_chapter_images')) {
            Schema::create('foodalchemist_offer_chapter_images', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->foreignId('chapter_id')->constrained('foodalchemist_offer_chapters')->cascadeOnDelete();
                $table->unsignedBigInteger('context_file_id')->nullable();
                $table->string('path')->nullable();
                $table->string('caption')->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['chapter_id', 'sort_order']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_offer_chapter_images');

        if (Schema::hasTable('foodalchemist_offer_blocks')) {
            Schema::table('foodalchemist_offer_blocks', function (Blueprint $table) {
                foreach (['variant_group_id', 'header_source'] as $col) {
                    if (Schema::hasColumn('foodalchemist_offer_blocks', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('foodalchemist_offer_chapters')) {
            Schema::table('foodalchemist_offer_chapters', function (Blueprint $table) {
                foreach ([
                    'is_struktur', 'fortschritt', 'image_context_file_id', 'image_path',
                    'service_moment_id', 'writing_style_id', 'target_count', 'price_anchor',
                    'price_min', 'price_max', 'target_food_cost_pct', 'creative_mode',
                ] as $col) {
                    if (Schema::hasColumn('foodalchemist_offer_chapters', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
