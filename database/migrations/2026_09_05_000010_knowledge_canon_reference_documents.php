<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 50 Strang III (Dominique, 2026-09-05): der KANON zeigt auf DOSSIERS, nicht auf
 * Abschnitte.
 *
 * Warum der Umbau: Gemessen auf dev sind 386 von 452 Wissens-Dossiers länger als das
 * Embedding-Fenster (2000 Z.); auf demo (Truth, 825) sind 622 länger. Der geplante Weg —
 * Sectionizer + Chunks, Kanon auf `knowledge_section_id` — hätte Anker wie `§6.1|abs-3`
 * erzeugt, die beim nächsten Edit verrutschen: Regel ändern = Kanon reparieren. Dominiques
 * Praxis auf demo ist längst die andere: ein Dossier = ein Thema (47 §-Dossiers regelwerk
 * aktiv, die Monolithen inaktiv). Dann ist das DOSSIER die Einheit für Suche, Kanon und
 * Pflege — kein Anker, kein Chunk, Regel ändern = ein Dossier editieren.
 *
 * Diese Migration tauscht die FK-Spalte. Die Tabelle war bis hier bewusst LEER
 * (000003: „ohne Zeilen ändert sich nichts") — enthält sie doch Zeilen, bricht die
 * Migration ab, statt Bindungen still zu verlieren. `_sections`/`_chunks` bleiben
 * ungenutzt stehen (additiv, leer; Drop ist eine eigene Entscheidung).
 *
 * Invariante (im Service/PUT geprüft, nicht im Schema erzwingbar): eine Kanon-Zeile mit
 * team_id NULL darf nur Dossiers mit team_id NULL referenzieren — sonst zöge ein globaler
 * Kanon team-eigenes Wissen in fremde Prompts. Team-Zeilen dürfen globale + eigene.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_knowledge_canon')) {
            return;
        }
        if (Schema::hasColumn('foodalchemist_knowledge_canon', 'knowledge_document_id')) {
            return;                                                  // schon umgebaut
        }
        if (DB::table('foodalchemist_knowledge_canon')->exists()) {
            throw new RuntimeException(
                'foodalchemist_knowledge_canon enthält Zeilen — der FK-Tausch auf Dokumente würde '
                . 'Bindungen verlieren. Erst leeren (oder manuell umhängen), dann erneut migrieren.'
            );
        }

        // Leer + FK-Tausch inkl. Unique-Index: SQLite kann Spalten mit FK nicht droppen,
        // MySQL braucht die Constraint-Reihenfolge. Neu anlegen ist auf beiden der sichere Weg.
        Schema::drop('foodalchemist_knowledge_canon');

        Schema::create('foodalchemist_knowledge_canon', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('scope', 12)->comment('feature | prompt_key');
            $table->string('scope_key', 64);
            $table->string('role', 8)->default('root')->comment('root | child');
            $table->unsignedInteger('ord')->default(0);
            $table->foreignId('knowledge_document_id')
                ->constrained('foodalchemist_knowledge_documents')
                ->cascadeOnDelete();
            $table->string('mode', 12)->default('pflicht')->comment('pflicht | wenn_platz');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'scope', 'scope_key', 'role', 'knowledge_document_id'], 'fa_know_canon_uq');
            $table->index(['scope', 'scope_key', 'role', 'active'], 'fa_know_canon_lookup_idx');
        });
    }

    public function down(): void
    {
        // Rückweg = Schema aus 000003 (Section-FK). Nur sinnvoll, solange die Tabelle leer ist.
        if (! Schema::hasTable('foodalchemist_knowledge_canon') || ! Schema::hasTable('foodalchemist_knowledge_sections')) {
            return;
        }
        Schema::drop('foodalchemist_knowledge_canon');
        Schema::create('foodalchemist_knowledge_canon', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('scope', 12);
            $table->string('scope_key', 64);
            $table->string('role', 8)->default('root');
            $table->unsignedInteger('ord')->default(0);
            $table->foreignId('knowledge_section_id')->constrained('foodalchemist_knowledge_sections')->cascadeOnDelete();
            $table->string('mode', 12)->default('pflicht');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['team_id', 'scope', 'scope_key', 'role', 'knowledge_section_id'], 'fa_know_canon_uq');
            $table->index(['scope', 'scope_key', 'role', 'active'], 'fa_know_canon_lookup_idx');
        });
    }
};
