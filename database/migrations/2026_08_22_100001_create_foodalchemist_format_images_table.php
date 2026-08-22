<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Format-Modul (Phase A): Marketing-Bildwelt eines Formats (Hero + Galerie).
 * Spiegelt `foodalchemist_recipe_step_photos` — Bildfeld-Paar `path` (legacy) +
 * `context_file_id` (core ContextFile: WebP/Varianten/signierte URLs). Genau eins
 * je Format kann `is_hero` sein (Marketing-Hero). Datei via FoodAlchemistMediaService.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_format_images')) {
            return;
        }

        Schema::create('foodalchemist_format_images', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('format_id')->constrained('foodalchemist_formats')->cascadeOnDelete();
            $table->foreignId('context_file_id')->nullable()->constrained('context_files')->nullOnDelete();
            $table->string('path')->nullable();              // Legacy-Pfad (Fallback); primär context_file_id
            $table->string('caption')->nullable();
            $table->boolean('is_hero')->default(false);      // Marketing-Hero (max. 1 je Format)
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['format_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_format_images');
    }
};
