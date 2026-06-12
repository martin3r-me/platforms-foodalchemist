<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M9-01g: Plating & Service (Ist-App: Teller-Aufbau, Mengenverteilung,
 * Service — keine Produktion) — Markdown-Text mit GL-07-Lineage-Trio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->text('plating_text')->nullable();
            $table->string('plating_quelle', 16)->nullable();
            $table->decimal('plating_ai_confidence', 4, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropColumn(['plating_text', 'plating_quelle', 'plating_ai_confidence']);
        });
    }
};
