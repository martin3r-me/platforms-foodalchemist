<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7-01 / 06_KI §5: KI-Audit — jede Gateway-Antwort schreibt VOR Rückgabe
 * genau eine Zeile, AUCH der Fehlerpfad (try/finally). prompt_hash statt
 * Volltext (kein Leak), knowledge_used schließt die GL-13-§6-Lücke
 * (files_used ging bisher nach dem Call verloren).
 *
 * Spec-Abweichung dokumentiert: team_id/user_id NULLABLE statt NOT NULL —
 * CLI-/Sandbox-Calls (Import, Tinker) laufen ohne Auth; Plattform-Calls
 * tragen den Verursacher immer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_ai_call_log', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('feature')->index();                       // Prompt-Key der Registry
            $table->string('tier', 2)->nullable();
            $table->string('model')->nullable();                      // tatsächlich genutzt (nach Fallback)
            $table->json('layers_used')->nullable();                  // GL-06 Inv. 7 (Hüllen kommen mit M7-05)
            $table->json('knowledge_used')->nullable();               // GL-13 §6: [{slug@version}]
            $table->string('prompt_hash', 64)->nullable();            // SHA-256 — Dedup ohne Volltext-Leak
            $table->string('response_summary', 200)->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->string('target_table')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_ai_call_log');
    }
};
