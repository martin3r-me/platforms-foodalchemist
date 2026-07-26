<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 21 · S5a — der Stempel, der den Batch bezahlbar macht.
 *
 * Ohne ihn hätte der Tranche-B-Lauf nur zwei Möglichkeiten: den ganzen Bestand bei
 * JEDEM Lauf durch den Provider schicken (Egress je Lauf statt je Änderung — genau
 * die Kosten-Falle aus V-031), oder die Arbeitsmenge aus den Befund-Zeilen ableiten
 * — was ein sauberes Rezept ohne Befunde für immer als „nie geprüft" führt und es
 * damit in jedem Lauf erneut kostet.
 *
 * Mit `ai_reviewed_at` ist die Arbeitsmenge change-driven:
 *   `ai_reviewed_at IS NULL OR ai_reviewed_at < updated_at`
 * — nie geprüft, oder seit der Prüfung angefasst. Ein unveränderten Rezept wird
 * nicht zweimal bezahlt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('foodalchemist_recipes', 'ai_reviewed_at')) {
            return;
        }
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->timestamp('ai_reviewed_at')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('foodalchemist_recipes', 'ai_reviewed_at')) {
            return;
        }
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropColumn('ai_reviewed_at');
        });
    }
};
