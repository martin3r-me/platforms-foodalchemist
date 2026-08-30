<?php

use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Republish-Preis-Schutz, Kern: `setzePreisePfade` ist die exakte Inverse zu `preisPfade` und
 * respektiert BEIDE Preis-Formate — float (Foodbook/Speisekarte linear) und den deutschen
 * Format-String im Speiseplan-Grid. Round-Trip + Grid-String-Erhalt.
 */
it('setzePreisePfade schreibt jeden Pfad zurück (Round-Trip) und erhält das Grid-String-Format', function () {
    $svc = app(PresentationService::class);

    $snap = ['content' => [
        'sections' => [[
            'blocks' => [[
                'label' => 'Menü',
                'items' => [['label' => 'Zander', 'price' => 24.5], ['label' => 'ohne Preis', 'price' => null]],
                'price' => ['pp' => 39.0, 'pauschal' => 0],
            ]],
        ]],
        'grid' => ['lines' => [['name' => 'Linie 1', 'cells' => ['2026-09-01' => [['label' => 'Suppe', 'price' => '1.234,56 €']]]]]],
        'total' => ['vk_pro_person' => 42.0, 'pauschal' => 0],
    ]];

    $pfade = $svc->preisPfade($snap);
    expect($pfade)->not->toBeEmpty();

    // Jeden Preis um +100 überschreiben und zurücklesen.
    $ov = collect($pfade)->mapWithKeys(fn ($v, $k) => [$k => $v['net'] + 100.0])->all();
    $neu = $svc->setzePreisePfade($snap, $ov);
    $pfade2 = $svc->preisPfade($neu);

    foreach ($pfade as $k => $v) {
        expect($pfade2[$k]['net'])->toBe($v['net'] + 100.0);
    }
    // Grid-Zelle bleibt ein deutscher Format-String (nicht float) — sonst bricht das Rendering.
    expect($neu['content']['grid']['lines'][0]['cells']['2026-09-01'][0]['price'])->toBe('1.334,56 €')
        ->and($neu['content']['sections'][0]['blocks'][0]['items'][0]['price'])->toBe(124.5);   // linear = float
});

it('setzePreisePfade legt nichts an: unbekannte Pfade + preislose Zeilen bleiben unberührt', function () {
    $svc = app(PresentationService::class);
    $snap = ['content' => ['sections' => [['blocks' => [['items' => [['label' => 'A', 'price' => 10.0]]]]]]]];

    $neu = $svc->setzePreisePfade($snap, ['s9.b9.i9' => 999.0, 's0.b0.i0' => 88.0]);
    expect($neu['content']['sections'][0]['blocks'][0]['items'][0]['price'])->toBe(88.0)
        ->and($neu['content']['sections'][0]['blocks'][0]['items'])->toHaveCount(1);   // kein Phantom-Knoten
});
