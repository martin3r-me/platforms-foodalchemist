<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M3-10: GL-07-Lineage-Trio für das KI-Feld `condition` — das generische
 * ai_confidence/ai_reasoning gehört zur Status-/Klassifikations-Kuratierung,
 * condition braucht (wie tag_/allergene_/food_domain_) sein eigenes Trio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_gps', function (Blueprint $table) {
            $table->string('condition_source', 16)->nullable()->comment('manual|ki|auto (GL-07)');
            $table->decimal('condition_ai_confidence', 4, 3)->nullable();
            $table->text('condition_ai_reasoning')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_gps', function (Blueprint $table) {
            $table->dropColumn(['condition_source', 'condition_ai_confidence', 'condition_ai_reasoning']);
        });
    }
};
