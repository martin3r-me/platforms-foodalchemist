<?php

use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * E0 (#507): friert das Kalibrierungs-Golden-Set strukturell ein. Die eigentliche
 * Recall@15-Messung läuft in E5 online gegen den echten Embedder (hier nicht
 * messbar — Fake-BoW übersetzt/stemmt nicht). Dieser Test garantiert nur, dass die
 * Fixture wohlgeformt + für die E5-Harness verlässlich konsumierbar bleibt.
 */
$laden = fn () => require dirname(__DIR__) . '/Fixtures/SemanticGoldenSet.php';

it('ist wohlgeformt und deckt alle Fallklassen ab', function () use ($laden) {
    $set = $laden();

    expect($set)->toBeArray()
        ->and(count($set))->toBeGreaterThanOrEqual(30);   // Plan: ~30–50 Paare

    $relationen = collect($set)->pluck('relation')->unique()->all();
    foreach (['synonym', 'translation', 'compound', 'regional', 'anti_marker'] as $erwartet) {
        expect($relationen)->toContain($erwartet);
    }

    $polaritäten = collect($set)->countBy('polarity');
    expect($polaritäten['positive'] ?? 0)->toBeGreaterThanOrEqual(20)
        ->and($polaritäten['negative'] ?? 0)->toBeGreaterThanOrEqual(5);   // Anti-Marker-Gegenprobe
});

it('erfüllt die Feld-Invarianten je Polarität', function () use ($laden) {
    foreach ($laden() as $i => $fall) {
        expect($fall)->toHaveKeys(['query', 'expect', 'forbid', 'relation', 'polarity', 'note']);
        expect(trim((string) $fall['query']))->not->toBe('', "Zeile {$i}: leere Query");

        if ($fall['polarity'] === 'positive') {
            expect($fall['expect'])->not->toBeNull("Zeile {$i}: positiver Fall ohne expect")
                ->and($fall['relation'])->not->toBe('anti_marker');
        } else {
            expect($fall['forbid'])->not->toBeNull("Zeile {$i}: negativer Fall ohne forbid")
                ->and($fall['relation'])->toBe('anti_marker');
        }
    }
});

it('hat eindeutige Queries (keine Dubletten)', function () use ($laden) {
    $queries = collect($laden())->pluck('query')->map(fn ($q) => mb_strtolower(trim((string) $q)));
    expect($queries->count())->toBe($queries->unique()->count());
});
