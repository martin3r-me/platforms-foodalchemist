<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 21 · S5b-2 — der zweite Prüf-Stempel: Bauart statt Rezeptur.
 *
 * `ai_reviewed_at` (S5a) trägt den Copilot-Pass. Der Bauart-Pass („ist das ein
 * Gericht oder eine Komponente?", 269er-Logik) ist ein ANDERER Provider-Call mit
 * einem anderen Prompt — würde er sich denselben Stempel teilen, starrten sich die
 * beiden Läufe gegenseitig aus: wer zuerst stempelt, nimmt dem anderen die
 * Fälligkeit weg, und das Rezept käme nie durch beide Pässe.
 *
 * Die Alternative wäre gewesen, die Fälligkeit aus den Befund-Zeilen abzuleiten
 * (`last_seen_at` je kind). Das scheitert am Regelfall: ein Rezept, dessen Bauart
 * stimmt, erzeugt gar keine Zeile — es bliebe für immer „nie geprüft" und würde in
 * jedem Lauf erneut bezahlt. Genau die Kosten-Falle, gegen die S5a den Stempel
 * eingeführt hat; hier gilt sie ein zweites Mal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('foodalchemist_recipes', 'structure_reviewed_at')) {
            return;
        }
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->timestamp('structure_reviewed_at')->nullable()->after('ai_reviewed_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('foodalchemist_recipes', 'structure_reviewed_at')) {
            return;
        }
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropColumn('structure_reviewed_at');
        });
    }
};
