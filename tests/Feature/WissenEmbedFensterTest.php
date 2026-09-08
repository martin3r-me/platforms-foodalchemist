<?php

use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Das Fenster steht auf **4000** — gemessen am 2026-09-07, nicht geraten.
 *
 * Vorgeschichte, damit niemand die alte Messung gegen die neue ausspielt: W1-1 hat
 * 2000 → 8000 an MONOLITHEN von 10–50k gemessen und verworfen (Schwanz 72 % → 68 %,
 * Verdünnung frisst den Zugewinn). Nach der Granularisierung (Spec 50 Strang III: ein
 * Thema pro Dossier, Median 2.885 Z.) misst 4000 etwas anderes — ein Ein-Themen-Dossier
 * wird vollständig abgebildet statt ein Monolith gemittelt. Ergebnis bei n=120:
 * Schwanz 47,5 % → 55,0 %, Kopf 85,0 % → 81,7 %, und 29 % des Korpus liegen erstmals
 * überhaupt im Vektor. Entschieden: BEHALTEN.
 *
 * Diese Tests pinnen den Wert samt Begründung, damit ihn niemand „naheliegend" wieder
 * verstellt, ohne vorher `foodalchemist:wissen-recall-probe --team=<id> --limit=120` zu
 * fahren. Jede Änderung verlangt ausserdem einen Re-Embed.
 */
function embedText(object $doc): string
{
    $rm = (new ReflectionClass(KnowledgeEmbeddingService::class))->getMethod('embedText');
    $rm->setAccessible(true);

    return $rm->invoke(app(KnowledgeEmbeddingService::class), $doc);
}

it('das Fenster steht auf 4000 — und der Config-Default sagt dasselbe', function () {
    $f = (new ReflectionClass(KnowledgeEmbeddingService::class))->getConstant('DOMAIN_LEAD_CHARS');

    // Wird das hier rot, ZUERST den Docblock der Konstante lesen: 8000 ist am 2026-09-03
    // an Monolithen gemessen (72 % → 68 %) und zurückgenommen worden, 4000 am 2026-09-07
    // an Ein-Themen-Dossiers gemessen (Schwanz 47,5 % → 55,0 %, n=120) und behalten.
    expect($f)->toBe(4000);

    // ★ Der eigentliche Befund dieses Tests: Konstante UND Config-Default müssen dasselbe
    // sagen. Vom 2026-09-07 bis 2026-09-08 stand der Default auf 2000 und demo fuhr per
    // ENV 4000 — eine frische Umgebung hätte still das halbe Fenster bekommen. Genau der
    // Riss, den Spec 52 überall sonst auch schliesst (H7: demo handgerichtet, Repo hinkt).
    expect(app(KnowledgeEmbeddingService::class)->leadChars())->toBe(4000)
        ->and((int) config('foodalchemist.semantic_search.embed_lead_chars'))->toBe(4000);
});

it('ein Dossier auf Deckel-Größe steckt GANZ im Vektor — das war der Zweck der Umstellung', function () {
    // 3.785 Z. ist der real gemessene Fall vom 2026-09-07 (embedText lieferte 3.835 Z.).
    $inhalt = str_repeat('Füllsatz zur Länge. ', 189) . 'MARKERAMDOSSIERENDE';
    expect(mb_strlen($inhalt))->toBeLessThan(4000)->toBeGreaterThan(3700);

    $text = embedText((object) ['title' => 'Dossier', 'category' => 'domain', 'content_md' => $inhalt, 'slug' => 'd']);

    // Bei Fenster 2000 fehlte hier die zweite Hälfte. Das ist die Zeile, die den
    // strukturellen Gewinn festhält: unter dem Deckel wird nichts mehr abgeschnitten.
    expect($text)->toContain('MARKERAMDOSSIERENDE');
});

it('kappt jenseits von 4000 — der Rest ist trotzdem meist findbar, das erledigte W1-1', function () {
    $inhalt = str_repeat('Füllsatz zur Länge. ', 220) . 'MARKERHINTERVIERTAUSEND';
    expect(mb_strlen($inhalt))->toBeGreaterThan(4000);

    $text = embedText((object) ['title' => 'Dossier', 'category' => 'domain', 'content_md' => $inhalt, 'slug' => 'd']);

    // Nicht im Vektor — und laut Messung trotzdem oft auffindbar (55 %), weil die
    // Themen-Signatur des Kopfes das ganze Dokument trägt. Deshalb ist ein Dossier
    // ÜBER dem Deckel ein Kurations-Befund (knowledge-oversized), kein Suchproblem.
    expect($text)->not->toContain('MARKERHINTERVIERTAUSEND')
        ->and(mb_strlen($text))->toBeLessThan(4100);
});

it('Pairing-Docs bleiben unberührt — sie haben ihren eigenen, kompakten Embedding-Text', function () {
    $text = embedText((object) [
        'title' => 'Zander', 'category' => 'pairing', 'slug' => 'pairing.zander',
        'content_md' => str_repeat('Prosa. ', 3000),
    ]);

    // Der Pairing-Zweig baut aus Slug + Partner-NAMEN, nicht aus dem Lead — ein grösseres
    // Fenster darf ihn nicht mit Prosa aufblasen.
    expect(mb_strlen($text))->toBeLessThan(600);
});
