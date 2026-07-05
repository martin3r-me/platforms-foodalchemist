<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M4-01 / D-5 §2.3: Satelliten — Equipment-Vokabular (40) + M:N (836, Nachtrag
 * 13_REFERENZ: Equipment-Chips sind Teil des Editors) + Eignungs-Zeilen
 * (Niveau 637 / Sektor 1.644; Slug-basiert wie Quelle — vocab_niveau/_sektor
 * folgen mit V-20 als pflegbare Lookups).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_vocab_kitchen_equipment', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global (D1)');
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('group_name')->nullable();
            $table->unsignedInteger('sort_order')->default(100);
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('foodalchemist_recipe_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('foodalchemist_vocab_kitchen_equipment')->cascadeOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['recipe_id', 'equipment_id']);
        });

        foreach (['foodalchemist_recipe_level_suitability' => 'level_slug', 'foodalchemist_recipe_sector_suitability' => 'sector_slug'] as $tabelle => $slugSpalte) {
            Schema::create($tabelle, function (Blueprint $table) use ($slugSpalte) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('legacy_id')->nullable()->unique()->comment('Quell-PK eignung_id');
                $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();
                $table->string($slugSpalte)->index();
                $table->string('source', 16)->default('ai_inferred')->comment('manual|ai_inferred (GL-07)');
                $table->decimal('ai_confidence', 4, 3)->nullable();
                $table->text('ai_reasoning')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['recipe_id', $slugSpalte]);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_sector_suitability');
        Schema::dropIfExists('foodalchemist_recipe_level_suitability');
        Schema::dropIfExists('foodalchemist_recipe_equipment');
        Schema::dropIfExists('foodalchemist_vocab_kitchen_equipment');
    }
};
