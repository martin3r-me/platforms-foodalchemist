<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M5-04 / GL-10: Prozess-/Kocharomen pro Rezept (303 — röstaromen/karamell/
 * rauch/ferment), Volltext-klassifiziert. Fließen NUR bei Sub-Rezept-Komponenten
 * zusätzlich in die Kohäsion (Tabelle 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_recipe_prozess_anker', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();
            $table->foreignId('anker_id')->constrained('foodalchemist_vocab_pairing_ankers')->cascadeOnDelete();
            $table->string('quelle', 16)->nullable();
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->text('ai_begruendung')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['recipe_id', 'anker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_prozess_anker');
    }
};
