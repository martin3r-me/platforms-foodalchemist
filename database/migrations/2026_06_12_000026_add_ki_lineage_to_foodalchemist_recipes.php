<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M4-11: GL-07-Lineage-Trios für die KI-Felder description + kategorie —
 * die Quelle führte ki_beschreibung implizit als KI-Feld ohne Trio; im Ziel
 * bekommt jedes KI-Feld sein eigenes (GL-07-Pattern, wie condition bei M3-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->string('description_source', 16)->nullable()->comment('manual|ki|auto (GL-07)');
            $table->decimal('description_ai_confidence', 4, 3)->nullable();
            $table->string('kategorie_source', 16)->nullable();
            $table->decimal('kategorie_ai_confidence', 4, 3)->nullable();
            $table->text('kategorie_ai_begruendung')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropColumn(['description_source', 'description_ai_confidence', 'kategorie_source', 'kategorie_ai_confidence', 'kategorie_ai_begruendung']);
        });
    }
};
