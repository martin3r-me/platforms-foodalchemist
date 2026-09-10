<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 * ★ **Korrektur 2026-09-09 — der erste Anlauf hat den demo-Deploy blockiert.**
 *
 * Die Migration warf ab, wenn Zeilen drin waren, „weil sie nie einen Leser hatten". Auf demo
 * liegen aber **2.574 Zeilen in `_chunks`** — jemand hat `knowledge-sectionize` gefahren.
 * Ergebnis: `migrate` schlug fehl, und weil eine fehlgeschlagene Migration den ganzen Deploy
 * abbricht, war ab da JEDER Deploy blockiert, auch fremde.
 *
 * Zwei Fehler, beide meine:
 *   1. Ich habe „die Tabellen sind leer" BEHAUPTET, statt es auf demo zu messen. Der Satz
 *      stammte aus dem Docblock von `2026_09_05_000010` („bis hier bewusst LEER") — der galt
 *      für `_canon`, nicht für `_chunks`.
 *   2. Der Riegel prüfte das falsche Kriterium. Zeilen sind kein Beleg für Nutzung; der
 *      Beleg für Nutzung ist ein LESER. Und den gibt es nicht — `grep` über `src/` und
 *      `resources/` findet null Zugriff auf `knowledge_sections`/`knowledge_chunks`
 *      (2026-09-09 geprüft, nach dem Löschen von Sectionizer/Chunker/Kommando). Die Zeilen
 *      sind abgeleitete Daten aus `knowledge_documents`, die nichts konsumiert.
 *
 * Deshalb: droppen und die Zahl protokollieren, statt abbrechen. Die Daten sind nach dem
 * Wegfall des Producers nicht mehr erzeugbar — das ist in Ordnung, denn der Weg wurde am
 * 2026-09-05 verworfen (Dominique: „ein Dossier = ein Thema"), und wer die Abschnitts-Idee
 * neu aufgreift, baut sie gegen den dann geltenden Stand.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['foodalchemist_knowledge_chunks', 'foodalchemist_knowledge_sections'] as $tabelle) {
            if (! Schema::hasTable($tabelle)) {
                continue;
            }

            // Die Zahl ins Log, nicht in eine Exception: sie ist Beleg, kein Hindernis.
            // Wer je wissen will, wie viel abgeleitetes Material hier lag, findet es hier.
            $zeilen = DB::table($tabelle)->count();
            Log::info('foodalchemist.spec52.f5.drop', ['tabelle' => $tabelle, 'zeilen' => $zeilen]);

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
