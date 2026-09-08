<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 52 · F5 — `_sections` und `_chunks` droppen: ein Schema, das nie einen Leser hatte.
 *
 * Die Geschichte in drei Sätzen. W1-4/W3-3 planten die Retrieval-Einheit vom DOSSIER auf den
 * ABSCHNITT umzustellen: `knowledge_sections` + `knowledge_chunks`, der Kanon sollte auf
 * `knowledge_section_id` zeigen. Gebaut wurden Tabellen, ein Sectionizer, ein Chunker und ein
 * Kommando mit `--verify`. Bevor der erste Leser entstand, entschied Dominique am 2026-09-05
 * den anderen Weg (Spec 50 Strang III): **ein Dossier = ein Thema** — dann ist das Dossier die
 * Einheit für Suche, Kanon und Pflege, und Anker wie `§6.1|abs-3` verrutschen beim nächsten
 * Edit nicht mehr.
 *
 * ★ Die Migration `2026_09_05_000010` hat den FK des Kanons daraufhin auf `knowledge_document_id`
 * getauscht und schrieb ausdrücklich: *„`_sections`/`_chunks` bleiben ungenutzt stehen (additiv,
 * leer; Drop ist eine eigene Entscheidung)."* Das ist diese Entscheidung.
 *
 * **Warum das kein Aufräum-Luxus ist.** Der Producer läuft: `foodalchemist:knowledge-sectionize`
 * ist registriert und schreibt in beide Tabellen. Ein Schema mit Schreiber und ohne Leser ist
 * die teuerste Sorte totes Gewicht — es sieht bei jeder Diagnose nach Bestand aus, es lädt zum
 * „das könnte man doch nutzen" ein, und es ist der Beleg im eigenen Repo dafür, wie ein Umbau
 * aussieht, der am Problem vorbeigebaut wurde (Spec 52, Abschnitt „Warum kein Modul-Umbau").
 *
 * Reihenfolge: erst `_chunks` (FK auf `_sections`), dann `_sections`.
 *
 * ⚠ Vor dem Drop wird gezählt. Sind wider Erwarten Zeilen drin, bricht die Migration ab statt
 * Daten wegzuwerfen — dieselbe Vorsicht wie in `000010`.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['foodalchemist_knowledge_chunks', 'foodalchemist_knowledge_sections'] as $tabelle) {
            if (! Schema::hasTable($tabelle)) {
                continue;
            }
            $zeilen = DB::table($tabelle)->count();
            if ($zeilen > 0) {
                throw new RuntimeException(
                    "«{$tabelle}» enthält {$zeilen} Zeile(n). Der Drop ist auf LEERE Tabellen ausgelegt "
                    .'(sie hatten nie einen Leser). Erst klären, wer sie befüllt hat — '
                    .'`foodalchemist:knowledge-sectionize` ist mit dieser Migration entfernt.'
                );
            }
            Schema::drop($tabelle);
        }
    }

    public function down(): void
    {
        // Kein Rückweg. Die Tabellen wiederherzustellen hiesse, ein Schema zurückzuholen, das
        // nie gelesen wurde — und der Kanon zeigt seit `000010` auf Dossiers. Wer die
        // Abschnitts-Idee neu aufgreift, baut sie gegen den dann geltenden Stand, nicht gegen
        // diesen. Die Definitionen stehen in `2026_09_03_000001/000002`.
    }
};
