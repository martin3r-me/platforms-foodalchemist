<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 52 · H6 — typisierte Verbindungen zwischen Dossiers.
 *
 * **Der Anlass ist der bevorstehende Korpus-Umbau.** Themen werden neu geschnitten: aus einem
 * alten Dossier werden zwei, aus dreien eins. Das ist kein Bearbeiten mehr, sondern ein
 * Neuschnitt — und dabei geht Information verloren, die sich hinterher **nicht rekonstruieren**
 * lässt: welches neue Dossier hat welches alte abgelöst.
 *
 * Anders als bei der Wissensart (`H1`) reicht hier kein Feld am Dokument: die Aussage verbindet
 * ZWEI Dossiers, oft mehrere in beide Richtungen (drei alte → ein neues).
 *
 * **Warum eine eigene Tabelle, obwohl diese Spec gegen neue Tabellen argumentiert:** die drei
 * vorhandenen Steuertabellen tragen alle die Form `Schlüssel → Dossier`
 * (`feature`/`prompt_key`/`achse` → doc, `target_key` → doc, `kategorie` → doc). Eine Kante
 * `Dossier → Dossier` passt in keine davon. `knowledge_aliases` ist Begriff → Dossier, also
 * ebenfalls nicht. Wiederverwendung wäre hier ein Formfehler, keine Sparsamkeit.
 *
 * `art` ist bewusst eine Code-Konstante ({@see \Platform\FoodAlchemist\Services\Knowledge\Wissensverbindung}),
 * kein pflegbares Vokabular: `ersetzt` steuert Verhalten (die Nachfolger-Empfehlung im
 * Integritäts-Bericht), und darauf muss sich der Code verlassen können.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_knowledge_links')) {
            return;
        }

        Schema::create('foodalchemist_knowledge_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Wie überall im Modul: NULL = global (Master-Kuration), sonst Team-eigen.
            $table->unsignedBigInteger('team_id')->nullable()->index();

            $table->unsignedBigInteger('von_document_id');
            $table->unsignedBigInteger('nach_document_id');
            $table->string('art', 16);
            $table->string('notiz', 255)->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('von_document_id', 'fa_know_link_von_fk')
                ->references('id')->on('foodalchemist_knowledge_documents')->cascadeOnDelete();
            $table->foreign('nach_document_id', 'fa_know_link_nach_fk')
                ->references('id')->on('foodalchemist_knowledge_documents')->cascadeOnDelete();

            // Dieselbe Aussage nur einmal — aber dasselbe Paar darf mehrere ARTEN tragen
            // (ein Dossier kann ein anderes verfeinern UND ihm widersprechen).
            $table->unique(['team_id', 'von_document_id', 'nach_document_id', 'art'], 'fa_know_link_uq');
            // Der Bericht fragt „was ersetzt dieses Dossier" — also von der Zielseite her.
            $table->index(['nach_document_id', 'art', 'active'], 'fa_know_link_nach_idx');
        });

        $this->trageDenSchonPassiertenSplitNach();
    }

    /**
     * Die erste Nachfolge, die schon stattgefunden hat — festgehalten, solange die Zuordnung
     * noch bekannt ist.
     *
     * `2026_09_07_000001_split_global_workflow_dossiers` hat zwei Monolithen stillgelegt und
     * durch je vier Ein-Thema-Dossiers ersetzt. Die Zuordnung stand danach nur im Docblock
     * jener Migration — genau die Sorte Information, die diese Tabelle halten soll. Ohne diesen
     * Nachtrag melden beide Alt-Dossiers auf Dauer „abgelöst, ohne dass jemand gesagt hat, was
     * an ihre Stelle tritt", und der Bericht könnte bei einer toten Kanon-Zeile keinen
     * Nachfolger nennen.
     *
     * Überspringt fehlende Slugs still: auf demo ist `workflow.rezept_anlegen_mcp` inzwischen
     * gelöscht, und eine Migration darf daran nicht scheitern.
     */
    private function trageDenSchonPassiertenSplitNach(): void
    {
        $nachfolge = [
            'workflow.rezept_anlegen_mcp' => [
                'workflow.basisrezept_regeln', 'workflow.basisrezept_erzeugen',
                'workflow.basisrezept_komponenten', 'workflow.basisrezept_abschluss',
            ],
            'workflow.gericht_anlegen_mcp' => [
                'workflow.verkaufsgericht_anlegen_mcp', 'workflow.gericht_weg_a_leitstelle',
                'workflow.gericht_weg_b_eigenregie', 'workflow.gericht_abschluss',
            ],
        ];

        foreach ($nachfolge as $altSlug => $neueSlugs) {
            $alt = DB::table('foodalchemist_knowledge_documents')
                ->whereNull('deleted_at')->where('slug', $altSlug)->first(['id']);
            if ($alt === null) {
                continue;
            }

            foreach ($neueSlugs as $neuSlug) {
                $neu = DB::table('foodalchemist_knowledge_documents')
                    ->whereNull('deleted_at')->where('slug', $neuSlug)->first(['id', 'team_id']);
                if ($neu === null) {
                    continue;
                }

                DB::table('foodalchemist_knowledge_links')->insertOrIgnore([
                    'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
                    'team_id' => $neu->team_id,          // die Kante gehoert dem Nachfolger
                    'von_document_id' => $neu->id,
                    'nach_document_id' => $alt->id,
                    'art' => 'ersetzt',
                    'notiz' => 'Split 2026-09-07 (Migration split_global_workflow_dossiers)',
                    'active' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_knowledge_links');
    }
};
