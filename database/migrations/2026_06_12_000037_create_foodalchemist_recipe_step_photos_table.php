<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R6 (Dominique): Step-by-Step-Fotos — an die Zubereitung gekoppelt über
 * `schritt_nr` (1-basiert; 0 = allgemeines Rezept-Foto). Dateien liegen auf
 * dem public-Disk unter foodalchemist/rezepte/<recipe_id>/.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_recipe_step_photos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();
            $table->unsignedSmallInteger('schritt_nr')->default(0);
            $table->string('pfad');
            $table->string('caption')->nullable();
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['recipe_id', 'schritt_nr']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_step_photos');
    }
};
