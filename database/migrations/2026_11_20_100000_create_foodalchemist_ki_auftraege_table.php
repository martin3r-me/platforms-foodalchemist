<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1002 (P1): KI-Aufträge für den externen Weg. Das Gateway legt hier am Provider-Punkt
 * (chatMitBackoff) statt eines OpenAI-Calls einen Auftrag an; ein MCP-Client beantwortet ihn
 * (ki_auftraege.ANTWORT), der Wiederholungslauf des Aufrufers liest die Antwort über
 * messages_hash zurück und durchläuft dieselbe Prüfung/Nachverarbeitung wie der Plattformweg.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_ki_auftraege', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('feature');                                 // Prompt-Key der Registry
            $table->string('messages_hash', 64);                       // SHA-256 über Messages — Schutz vor stiller Abweichung
            $table->json('messages');                                  // voller Prompt inkl. Kanon/Kontext (Snapshot)
            $table->json('optionen')->nullable();                      // ohne Closures (structural_retry etc.)
            $table->string('target_table')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->unsignedBigInteger('knowledge_run_id')->nullable();
            $table->string('status', 20)->default('offen');           // offen | beantwortet | verbraucht | abgelaufen
            $table->longText('antwort')->nullable();                   // rohe Modellantwort (JSON-Text)
            $table->string('anbieter')->nullable();                    // provider laut Antwort (z. B. anthropic)
            $table->string('modell')->nullable();                      // tatsächliches Modell laut Antwort
            $table->timestamp('beantwortet_at')->nullable();
            $table->timestamp('verbraucht_at')->nullable();
            $table->timestamp('laeuft_ab_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'feature', 'messages_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_ki_auftraege');
    }
};
