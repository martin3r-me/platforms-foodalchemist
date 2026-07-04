<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UI-Audit M4-06-Nachtrag: ✨-Zubereitung im Rezept-Modal (D-5 §4.2.5)
 * braucht das GL-07-Lineage-Trio — description/kategorie hatten es, die
 * Zubereitung noch nicht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->string('preparation_source', 16)->nullable();
            $table->decimal('preparation_ai_confidence', 4, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropColumn(['preparation_source', 'preparation_ai_confidence']);
        });
    }
};
