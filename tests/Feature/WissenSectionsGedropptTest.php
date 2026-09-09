<?php

use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Spec 52 · F5 — `_sections`/`_chunks` sind weg, und niemand liest sie.
 *
 * ★ **Warum diese Datei existiert: der erste Anlauf hat den demo-Deploy blockiert.**
 *
 * Die Drop-Migration warf ab, wenn Zeilen in den Tabellen lagen — „sie hatten nie einen
 * Leser, also müssen sie leer sein". Auf demo lagen **2.574 Zeilen in `_chunks`**; jemand
 * hatte `knowledge-sectionize` gefahren. Eine fehlgeschlagene Migration bricht den ganzen
 * Deploy ab, also war ab da JEDER Deploy blockiert, auch fremde.
 *
 * Zwei Fehler, beide in meiner Annahme:
 *   1. „Die Tabellen sind leer" war BEHAUPTET, nicht gemessen — der Satz stammte aus dem
 *      Docblock einer anderen Migration und galt dort für `_canon`.
 *   2. Der Riegel prüfte das falsche Kriterium. **Zeilen sind kein Beleg für Nutzung.** Der
 *      Beleg für Nutzung ist ein LESER — und der lässt sich statisch prüfen, nicht zur
 *      Laufzeit erraten.
 *
 * Der Test prüft deshalb genau das, was der Riegel hätte prüfen sollen, nur an der Stelle,
 * wo es hingehört: im Code, nicht in der Migration.
 */
it('das Schema ist weg — beide Tabellen', function () {
    expect(Schema::hasTable('foodalchemist_knowledge_sections'))->toBeFalse()
        ->and(Schema::hasTable('foodalchemist_knowledge_chunks'))->toBeFalse();
});

it('und KEIN Code greift mehr darauf zu — das ist die Bedingung, unter der der Drop richtig war', function () {
    // Statisch, nicht zur Laufzeit: eine Tabelle ohne Leser ist gefahrlos droppbar, egal wie
    // viele Zeilen drin liegen. Genau diese Prüfung fehlte, als ich die Migration schrieb.
    $wurzel = dirname(__DIR__, 2);
    $treffer = [];

    foreach (['src', 'resources', 'config'] as $ordner) {
        $pfad = $wurzel.'/'.$ordner;
        if (! is_dir($pfad)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pfad));
        foreach ($it as $datei) {
            if (! $datei->isFile() || ! in_array($datei->getExtension(), ['php'], true)) {
                continue;
            }
            $inhalt = (string) file_get_contents($datei->getPathname());
            foreach (['foodalchemist_knowledge_sections', 'foodalchemist_knowledge_chunks'] as $tabelle) {
                // Kommentare zählen nicht: `DossierText` erklärt in Prosa, WARUM es das Schema
                // nicht benutzt. Gesucht sind echte Zugriffe.
                if (str_contains($inhalt, "'".$tabelle."'") || str_contains($inhalt, '"'.$tabelle.'"')) {
                    $treffer[] = str_replace($wurzel.'/', '', $datei->getPathname());
                }
            }
        }
    }

    expect($treffer)->toBe([], 'Zugriff auf ein gedropptes Schema: '.implode(', ', $treffer));
});

it('der Producer ist ebenfalls weg — sonst schriebe etwas in eine Tabelle, die es nicht gibt', function () {
    foreach ([
        'src/Console/KnowledgeSectionizeCommand.php',
        'src/Services/Knowledge/KnowledgeSectionizer.php',
        'src/Services/Knowledge/KnowledgeChunker.php',
    ] as $datei) {
        expect(is_file(dirname(__DIR__, 2).'/'.$datei))->toBeFalse($datei.' existiert noch');
    }
});
