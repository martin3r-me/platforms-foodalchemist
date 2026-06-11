<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M4-11: GL-07-Lineage-Trios für die KI-Felder beschreibung + kategorie —
 * die Quelle führte ki_beschreibung implizit als KI-Feld ohne Trio; im Ziel
 * bekommt jedes KI-Feld sein eigenes (GL-07-Pattern, wie zustand bei M3-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->string('beschreibung_quelle', 16)->nullable()->comment('manual|ki|auto (GL-07)');
            $table->decimal('beschreibung_ai_confidence', 4, 3)->nullable();
            $table->string('kategorie_quelle', 16)->nullable();
            $table->decimal('kategorie_ai_confidence', 4, 3)->nullable();
            $table->text('kategorie_ai_begruendung')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropColumn(['beschreibung_quelle', 'beschreibung_ai_confidence', 'kategorie_quelle', 'kategorie_ai_confidence', 'kategorie_ai_begruendung']);
        });
    }
};
