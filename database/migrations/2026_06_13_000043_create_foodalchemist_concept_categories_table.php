<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M10c-B (Dominique 2026-06-13): Concept-Kategorien als BAUM — zum Organisieren
 * vieler Concepts (Sammlungen/Linien/Anlässe), self-FK parent_id (beliebige Tiefe).
 * Organisatorische Hierarchie ÜBER den Concepts — NICHT die Komposition im Concept
 * (Slots bleiben flach, D-CON-3). team-eigen (BelongsToTeamHierarchy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_concept_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('parent_id')->nullable()->constrained('foodalchemist_concept_categories')->nullOnDelete();
            $table->string('name');
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('niveau')
                ->constrained('foodalchemist_concept_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::dropIfExists('foodalchemist_concept_categories');
    }
};
