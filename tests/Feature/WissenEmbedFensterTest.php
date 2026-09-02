<?php

use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * W1-1 wurde GEMESSEN UND VERWORFEN. 2000 → 8000 brachte nichts: Kopf-Anfragen blieben bei
 * 92 %, Anfragen jenseits von 2000 fielen von 72 % auf 68 % — obwohl der Inhalt bei 8000
 * nachweislich im Vektor war (source_hash gegengeprüft). Verdünnung frisst den Zugewinn.
 *
 * Diese Tests pinnen den Wert samt Begründung, damit ihn niemand „naheliegend" wieder
 * hochsetzt, ohne vorher `foodalchemist:wissen-recall-probe --team=<id>` zu fahren.
 */
function embedText(object $doc): string
{
    $rm = (new ReflectionClass(KnowledgeEmbeddingService::class))->getMethod('embedText');
    $rm->setAccessible(true);

    return $rm->invoke(app(KnowledgeEmbeddingService::class), $doc);
}

it('das Fenster steht auf 2000 — grösser wurde gemessen und brachte nichts', function () {
    $f = (new ReflectionClass(KnowledgeEmbeddingService::class))->getConstant('DOMAIN_LEAD_CHARS');

    // Wird das hier rot, ZUERST den Docblock der Konstante lesen: 8000 ist am 2026-09-03
    // gemessen worden (72 % → 68 %) und wurde deshalb zurückgenommen. Jede Änderung
    // verlangt ausserdem einen Re-Embed, sonst mischen sich alte und neue Vektoren.
    expect($f)->toBe(2000);
});

it('kappt jenseits von 2000 — und genau dieser Rest ist trotzdem zu 72 % findbar', function () {
    $inhalt = str_repeat('Füllsatz zur Länge. ', 120) . 'MARKERHINTERZWEITAUSEND';
    expect(mb_strlen($inhalt))->toBeGreaterThan(2000);

    $text = embedText((object) ['title' => 'Dossier', 'category' => 'domain', 'content_md' => $inhalt, 'slug' => 'd']);

    // Nicht im Vektor — und laut Messung trotzdem meist auffindbar, weil die
    // Themen-Signatur des Kopfes das ganze Dokument trägt. Das ist der Befund, der
    // W1-1 erledigt hat.
    expect($text)->not->toContain('MARKERHINTERZWEITAUSEND')
        ->and(mb_strlen($text))->toBeLessThan(2100);
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
