<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Format-Umbau F2a: format_slots — die Aufbau-Ebene des Formats („Conceptor eine Ebene
 * höher"). Spiegelt concept_slots, aber schlanker: ein Slot referenziert ein ganzes
 * Concept (type=concept, concept_id) ODER ist ein Struktur-Block (header/text/spacer).
 * Referenz statt Besitz — ein Concept kann so in mehreren Formaten liegen (Entscheid
 * 2026-08-24). Additiv + guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_format_slots')) {
            return;
        }
        Schema::create('foodalchemist_format_slots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('format_id')->constrained('foodalchemist_formats')->cascadeOnDelete();
            $table->string('type', 16)->default('concept');                 // concept | header | text | spacer
            $table->foreignId('concept_id')->nullable()                       // Referenz (type=concept)
                ->constrained('foodalchemist_concepts')->nullOnDelete();
            $table->string('title')->nullable();                              // header / optionaler Label-Override
            $table->text('text_content')->nullable();                         // text-Block
            $table->string('height', 16)->nullable();                         // spacer (klein|mittel|gross)
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['format_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_format_slots');
    }
};
