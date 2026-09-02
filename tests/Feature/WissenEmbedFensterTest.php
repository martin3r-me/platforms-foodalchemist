<?php

use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * W1-1: das Embedding-Fenster. Gemessen begründet (siehe Konstanten-Docblock):
 * Kopf-Anfragen 92 % findbar, Schwanz-Anfragen 72 % — die 20 Punkte Differenz sind
 * der Preis des Fensters, den 8000 holen soll.
 */
function embedText(object $doc): string
{
    $rm = (new ReflectionClass(KnowledgeEmbeddingService::class))->getMethod('embedText');
    $rm->setAccessible(true);

    return $rm->invoke(app(KnowledgeEmbeddingService::class), $doc);
}

it('das Fenster steht auf 8000 — und der Wert ist bewusst gesetzt', function () {
    $f = (new ReflectionClass(KnowledgeEmbeddingService::class))->getConstant('DOMAIN_LEAD_CHARS');

    // Wird das hier rot, ZUERST den Docblock der Konstante lesen: der Wert hängt an einer
    // Messung, nicht an einer Meinung — und jede Änderung verlangt einen Re-Embed.
    expect($f)->toBe(8000);
});

it('nimmt Inhalt bis 8000 Zeichen mit — vorher endete der Vektor bei 2000', function () {
    // Marker knapp hinter der ALTEN Grenze: unter 2000 wäre er verloren gewesen.
    $inhalt = str_repeat('Füllsatz zur Länge. ', 120) . 'MARKERHINTERZWEITAUSEND ' . str_repeat('weiter. ', 50);
    expect(mb_strlen($inhalt))->toBeGreaterThan(2000);

    $text = embedText((object) ['title' => 'Dossier', 'category' => 'domain', 'content_md' => $inhalt, 'slug' => 'd']);

    expect($text)->toContain('MARKERHINTERZWEITAUSEND');
});

it('kappt jenseits von 8000 — das Fenster ist ein Deckel, keine Einladung', function () {
    $inhalt = str_repeat('x', 8100) . 'MARKERHINTERACHTTAUSEND';

    $text = embedText((object) ['title' => 'D', 'category' => 'domain', 'content_md' => $inhalt, 'slug' => 'd']);

    expect($text)->not->toContain('MARKERHINTERACHTTAUSEND')
        ->and(mb_strlen($text))->toBeLessThan(8100 + 20);
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
