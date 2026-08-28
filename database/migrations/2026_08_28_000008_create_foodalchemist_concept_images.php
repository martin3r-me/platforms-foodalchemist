<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 43 (Bild-Epic) — kleine Concept-Galerie: das Titelbild lebt weiter auf
 * foodalchemist_concepts (image_*), zusätzliche Bilder hier. In der Präsentation
 * rendert das Kapitel-Band Titelbild + erstes Galeriebild als 2er-Band.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_concept_images')) {
            return;
        }
        Schema::create('foodalchemist_concept_images', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('concept_id')->constrained('foodalchemist_concepts')->cascadeOnDelete();
            $table->unsignedBigInteger('context_file_id')->nullable();
            $table->string('path')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['concept_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_concept_images');
    }
};
