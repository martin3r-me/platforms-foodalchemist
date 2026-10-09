<?php

use Platform\FoodAlchemist\Services\Matching\TokenEngine;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #233 (Lauf 86) setzt matchScore auf 0, wenn nur Zustandswörter treffen. isQualifierToken erkennt aber per Wortanfang
 * bzw. Teilstring — Komposita wie „Babyspinat", „Frischkäse", „Currypulver", „Rinderbeinscheiben" sind Produkte.
 */
beforeEach(fn () => $this->seedTeamHierarchy());

it('matchScore: Komposita mit Zustands-/Schnitt-Teil sind Produktwörter, reine Zustandswörter nicht', function () {
    $e = app(TokenEngine::class);
    $s = fn (string $q, string $c) => $e->matchScore($e->tokenize($q), null, $e->tokenize($c), null);

    expect($s('Babyspinat frisch', 'Babyspinat: frisch'))->toBeGreaterThan(0.5)
        ->and($s('Currypulver mild', 'Currypulver: mild, trocken'))->toBeGreaterThan(0.5)
        ->and($s('Frischkäse natur', 'Frischkäse: Doppelrahm, natur'))->toBeGreaterThan(0.5)
        ->and($s('Rinderbeinscheiben frisch', 'Rinderbeinscheiben: frisch'))->toBeGreaterThan(0.5)
        ->and($s('Rohmilch frisch', 'Rohmilch: frisch, 4 %'))->toBeGreaterThan(0.5)
        ->and($s('Brühwürfel Gemüse', 'Brühwürfel: Gemüse, trocken'))->toBeGreaterThan(0.5)
        ->and($s('Pilzmischung: frisch, ganz', 'Auberginen: frisch, ganz'))->toBe(0.0)
        ->and($s('Zwiebel: frisch, gewürfelt', 'Karotte: frisch, gewürfelt'))->toBe(0.0);
});

it('istReinesMerkmal: Zustands-/Schnittwort mit Beugung ja, Kompositum nein', function () {
    $e = app(TokenEngine::class);
    $rein = fn (string $w) => $e->istReinesMerkmal($e->tokenize($w)[0]);

    foreach (['frisch', 'frische', 'tk', 'tiefgekühlt', 'getrocknet', 'konserviert', 'ganz', 'ganze', 'geschält', 'scheiben', 'gewürfelt', 'gehackt', 'pulver', 'baby'] as $w) {
        expect($rein($w))->toBeTrue($w);
    }
    foreach (['frischkäse', 'babyspinat', 'rohmilch', 'currypulver', 'brühwürfel', 'rinderbeinscheiben', 'feinkost', 'ganzkornreis'] as $w) {
        expect($rein($w))->toBeFalse($w);
    }
});
