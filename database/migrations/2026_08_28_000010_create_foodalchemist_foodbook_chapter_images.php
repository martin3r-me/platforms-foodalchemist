<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 43 (Bild-Epic) — kleine Kapitel-Galerie: das Kapitel-Bild lebt weiter auf
 * foodalchemist_foodbook_chapters (image_*), zusätzliche Bilder hier. Analog zur
 * Concept-Galerie. Kapitel-Bilder haben Vorrang vor den Concept-Bildern im Band.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_foodbook_chapter_images')) {
            return;
        }
        Schema::create('foodalchemist_foodbook_chapter_images', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('chapter_id')->constrained('foodalchemist_foodbook_chapters')->cascadeOnDelete();
            $table->unsignedBigInteger('context_file_id')->nullable();
            $table->string('path')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['chapter_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_foodbook_chapter_images');
    }
};
