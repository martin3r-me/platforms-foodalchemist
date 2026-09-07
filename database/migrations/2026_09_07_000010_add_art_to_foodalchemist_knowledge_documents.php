<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 52 · H1 — die WISSENSART als eigenes Feld.
 *
 * Die Kategorie sagt, *worum* es geht (`regelwerk`, `kueche`, `workflow`). Sie sagt nicht,
 * *wie* das Wissen benutzt werden darf — und genau daran ist die Steuerung bisher
 * gescheitert:
 *
 *   · `workflow` mischt zwei Arten: Handwerkswissen, das in den Generator-Prompt gehört
 *     (`workflow.basisrezept_erstellungs_dossier` — Mutterstruktur einer Sauce), und
 *     Agenten-Anleitungen, die dort **nichts** verloren haben (`workflow.basisrezept_regeln` —
 *     „Regel 1: alles ist Entwurf", `primaer=lieferantenartikel_waehlen`). Ein Routing auf die
 *     Kategorie hätte beides in jeden Prompt gezogen. Deshalb hat `workflow` bis heute gar
 *     kein Routing — die Kategorie ist als Steuergrösse schlicht zu grob.
 *   · `cross_cutting` mischt Nachschlagewerke (`mengen_defaults` — eine Tabelle, die man über
 *     Gang × Rolle × Portion **auflöst**) mit echtem Suchmaterial (`geschmacksbalance`).
 *
 * Fünf Arten, bewusst als **Code-Konstante** statt als pflegbares Vokabular
 * ({@see \Platform\FoodAlchemist\Services\Knowledge\Wissensart}): der Code trifft
 * Entscheidungen anhand dieser Werte (`ablauf` darf nie in einen Prompt). Wäre die Liste zur
 * Laufzeit änderbar, könnte er sich nicht darauf verlassen. Die Kategorie bleibt pflegbar,
 * die Art nicht.
 *
 * **Nullable und ohne Backfill.** Der Korpus wird gerade inhaltlich neu aufgebaut — Bestand zu
 * markieren, der ersetzt wird, wäre Arbeit für die Tonne. Die neuen Dossiers tragen das Feld
 * von Anfang an; `null` heisst „noch nicht eingeordnet" und wird als Hinweis gemeldet, nicht
 * als Fehler.
 *
 * Der Vault-Import überschreibt nur `content_md`/`title`/`version`/Hashes/`char_count`/
 * `source_path` — `art` überlebt eine Reconciliation (geprüft an `KnowledgeImportCommand:172`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_knowledge_documents')
            || Schema::hasColumn('foodalchemist_knowledge_documents', 'art')) {
            return;
        }

        Schema::table('foodalchemist_knowledge_documents', function (Blueprint $table) {
            $table->string('art', 16)->nullable()->after('category');
            // Der Prompt-Bau filtert gleich beim Laden auf die Art (`ablauf` fliegt raus),
            // und der Bericht gruppiert danach — beides über den ganzen Korpus.
            $table->index(['art', 'active'], 'fa_know_doc_art_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('foodalchemist_knowledge_documents', 'art')) {
            return;
        }

        Schema::table('foodalchemist_knowledge_documents', function (Blueprint $table) {
            $table->dropIndex('fa_know_doc_art_idx');
            $table->dropColumn('art');
        });
    }
};
