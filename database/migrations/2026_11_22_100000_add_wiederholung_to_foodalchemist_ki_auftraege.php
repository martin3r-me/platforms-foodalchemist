<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1002 (P2a): Auftrag trägt die Wiederholungs-Nutzlast eines Job-Laufs (Job-Klasse + Argumente + Versuch), damit
 * ki_auftraege.ANTWORT den Lauf selbst neu anstoßen kann. `lauf_key` = ein laufender Lauf je Ziel (kein zweiter).
 * Additiv und nullable: Aufträge des Backfills und der Tool-Hülle (P1) bleiben unverändert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_ki_auftraege', function (Blueprint $table) {
            $table->json('wiederholung')->nullable();
            $table->string('lauf_key', 191)->nullable();
            $table->index(['team_id', 'lauf_key', 'status'], 'fa_ki_auftraege_lauf_idx');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_ki_auftraege', function (Blueprint $table) {
            $table->dropIndex('fa_ki_auftraege_lauf_idx');
            $table->dropColumn(['wiederholung', 'lauf_key']);
        });
    }
};
