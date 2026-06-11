<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M3-10: GL-07-Lineage-Trio für das KI-Feld `zustand` — das generische
 * ai_confidence/ai_begruendung gehört zur Status-/Klassifikations-Kuratierung,
 * zustand braucht (wie tag_/allergene_/food_domain_) sein eigenes Trio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_gps', function (Blueprint $table) {
            $table->string('zustand_quelle', 16)->nullable()->comment('manual|ki|auto (GL-07)');
            $table->decimal('zustand_ai_confidence', 4, 3)->nullable();
            $table->text('zustand_ai_begruendung')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_gps', function (Blueprint $table) {
            $table->dropColumn(['zustand_quelle', 'zustand_ai_confidence', 'zustand_ai_begruendung']);
        });
    }
};
