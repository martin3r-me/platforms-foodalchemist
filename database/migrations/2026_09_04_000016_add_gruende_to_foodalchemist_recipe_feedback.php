<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tipp-Gründe am Küchen-Feedback (Wandmonitor).
 *
 * ★ Eigene Spalte statt Text im Kommentar. Freier Text lässt sich nicht zählen: wenn zwölf
 * Leute „Menge stimmt nicht" auf zwölf Arten formulieren, sieht die Auswertung davon nichts.
 * Als Slug-Liste wird daraus eine Häufigkeit — und die ist der eigentliche Wert des
 * Küchen-Feedbacks. Der Kommentar bleibt daneben für den Fall, den keine Kachel trifft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipe_feedback', function (Blueprint $t) {
            if (! Schema::hasColumn('foodalchemist_recipe_feedback', 'gruende')) {
                $t->json('gruende')->nullable()->after('comment')
                    ->comment('Tipp-Gründe (Slugs aus config foodalchemist.feedback_gruende) — zählbar, im Gegensatz zum Kommentar');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipe_feedback', function (Blueprint $t) {
            if (Schema::hasColumn('foodalchemist_recipe_feedback', 'gruende')) {
                $t->dropColumn('gruende');
            }
        });
    }
};
