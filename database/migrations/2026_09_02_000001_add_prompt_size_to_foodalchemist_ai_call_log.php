<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Welle 0 / W0-0 — Messsonde für die Prompt-Größe.
 *
 * `tokens_in` kommt post-hoc aus der Provider-Antwort und sagt nichts darüber, WOHER
 * die Bytes kamen. Ohne diese Zerlegung sind alle Token-Aussagen der folgenden Wellen
 * Schätzungen: die 27.687 Tk/Call bei `recipe.generator` bestehen zu ~44 % aus einem
 * Restposten (avg_in × 3,0 minus der bekannten Blöcke), und ein Budget-Schnitt lässt
 * sich nicht von „Wissen fehlt jetzt" unterscheiden.
 *
 * `prompt_chars` = mb_strlen des gesamten gesendeten Prompts (alle Messages).
 * `prompt_parts` = Zeichen je Topf: kanon, retrieval, bound, task, kontext, huelle, dropped.
 *
 * Additiv, nullable, kein Backfill — Bestandszeilen bleiben NULL (= „vor der Sonde").
 * Der Schreibpfad ist über Schema::hasColumn abgesichert (Muster: tokens_cached), die
 * Spalten dürfen also fehlen, ohne den KI-Pfad zu brechen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_ai_call_log', function (Blueprint $table) {
            $table->unsignedInteger('prompt_chars')->nullable()->after('tokens_cached');
            $table->json('prompt_parts')->nullable()->after('prompt_chars');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_ai_call_log', function (Blueprint $table) {
            $table->dropColumn(['prompt_chars', 'prompt_parts']);
        });
    }
};
