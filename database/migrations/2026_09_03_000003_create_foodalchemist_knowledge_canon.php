<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W2-2-Vorbereitung: der KANON — welche Abschnitte ein Feature/Prompt-Key verbindlich
 * mitbekommt, statt „ganzes Dossier oder nichts".
 *
 * Die Tabelle wird angelegt, aber NICHT befüllt: die §-Auswahl kehrt eine dokumentierte
 * Spec-41-Entscheidung um (gepinnt in RegelwerkKnowledgeRoutingTest) und braucht fachliche
 * Abnahme. Ohne Zeilen ändert sich nichts — die heutigen `always`-Bindings bleiben der Weg.
 *
 * `scope` unterscheidet Feature (Routing-Ebene) von Prompt-Key (fein). `role` unterscheidet
 * den Start-Aufruf vom Kaskaden-Kind, das weniger braucht. `mode`: pflicht kommt immer mit,
 * wenn_platz nur im Restbudget.
 *
 * Invariante (im PUT zu prüfen, nicht im Schema erzwingbar): eine Zeile mit team_id NULL
 * darf nur Abschnitte mit team_id NULL referenzieren — sonst zöge ein globaler Kanon
 * team-eigenes Wissen in fremde Prompts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_knowledge_canon')) {
            return;
        }

        Schema::create('foodalchemist_knowledge_canon', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('scope', 12)->comment('feature | prompt_key');
            $table->string('scope_key', 64);
            $table->string('role', 8)->default('root')->comment('root | child');
            $table->unsignedInteger('ord')->default(0);
            $table->foreignId('knowledge_section_id')
                ->constrained('foodalchemist_knowledge_sections')
                ->cascadeOnDelete();
            $table->string('mode', 12)->default('pflicht')->comment('pflicht | wenn_platz');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'scope', 'scope_key', 'role', 'knowledge_section_id'], 'fa_know_canon_uq');
            $table->index(['scope', 'scope_key', 'role', 'active'], 'fa_know_canon_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_knowledge_canon');
    }
};
