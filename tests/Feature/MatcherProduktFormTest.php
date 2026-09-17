<?php

use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #505-Nachtrag (2026-09, Anlass „Creme-Suppe: Tomate-Speck" — GP 13757 „TK, getrocknet"
 * wurde gegen „konserviert" verrechnet, weil beide Zustände in einer Klasse lagen):
 * Identität/Zustand/Form-Trennung ({@see TokenEngine::produktForm()}). Golden-Fälle aus dem
 * Paket-A-Brief — je Abfrage darf NUR der GP mit passendem §9-Zustand matchen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
});

it('produktForm() trennt §9-Zustand von reiner Geometrie/Verarbeitung', function (string $text, ?string $zustand, ?string $form) {
    $out = app(TokenEngine::class)->produktForm($text);
    expect($out['zustand'])->toBe($zustand)->and($out['form'])->toBe($form);
})->with([
    'Dose + Form' => ['Stückige Tomaten, aus der Dose', 'konserviert', 'stueckig'],
    'Dosentomaten-Kompositum' => ['Dosentomaten', 'konserviert', null],
    'nur Form, kein §9-Wort' => ['passierte Tomaten', null, 'passiert'],
    'TK mit Bindestrich' => ['TK-Erbsen', 'TK', null],
    'getrocknet' => ['getrocknete Tomaten', 'trocken', null],
    'frisch' => ['Tomaten frisch', 'frisch', null],
]);

it('mehrdeutiger §9-Zustand im Namen bleibt unentschieden statt geraten', function () {
    // Wie GpZustandBackfillCommand::MUSTER: „TK, getrocknet" nennt zwei Zustände — konservativ
    // NULL statt eine Willkür-Entscheidung (Anlass GP 13757).
    expect(app(TokenEngine::class)->produktForm('Tomate: TK, getrocknet')['zustand'])->toBeNull();
});

it('matcht jede Zustandsabfrage NUR gegen den GP mit passendem §9-Zustand', function (string $query, string $slug, string $erwartet) {
    // hauptzutatSlug wie im echten KI-Zutatenfeld (z['slug']) — der robuste Identitäts-Anker
    // über Singular/Plural- und Wortform-Drift hinweg; die LIKE-Vorfilterung dieses Tests
    // prüft NUR die Zustandstrennung, nicht die (separate) Tokenisierungs-Konvergenz.
    $fresh = $this->makeGp($this->rootTeam, 'Tomate: frisch, ganz');
    $fresh->update(['status' => 'approved', 'condition' => 'frisch', 'main_ingredient_slug' => 'tomate']);
    $dry = $this->makeGp($this->rootTeam, 'Tomate: getrocknet, in Oel');
    $dry->update(['status' => 'approved', 'condition' => 'trocken', 'main_ingredient_slug' => 'tomate']);
    $canned = $this->makeGp($this->rootTeam, 'Tomate: konserviert, stückig');
    $canned->update(['status' => 'approved', 'condition' => 'konserviert', 'main_ingredient_slug' => 'tomate']);
    $tk = $this->makeGp($this->rootTeam, 'Erbse: TK, ganz');
    $tk->update(['status' => 'approved', 'condition' => 'TK', 'main_ingredient_slug' => 'erbse']);

    $kandidaten = ['fresh' => $fresh, 'dry' => $dry, 'canned' => $canned, 'tk' => $tk];
    $match = app(IngredientMatchService::class)->matchIngredient($this->rootTeam, $query, $slug);

    expect($match['gp_id'])->toBe($kandidaten[$erwartet]->id);
})->with([
    'frisch' => ['Tomaten frisch', 'tomate', 'fresh'],
    'getrocknet' => ['getrocknete Tomaten', 'tomate', 'dry'],
    'Dose' => ['Dosentomaten', 'tomate', 'canned'],
    'TK' => ['TK-Erbsen', 'erbse', 'tk'],
]);

it('ohne §9-Wort im Query filtert acceptsProductForm nichts — Score entscheidet (passierte Tomaten)', function () {
    $fresh = $this->makeGp($this->rootTeam, 'Tomate: frisch, ganz');
    $fresh->update(['status' => 'approved', 'condition' => 'frisch', 'main_ingredient_slug' => 'tomate']);
    $canned = $this->makeGp($this->rootTeam, 'Tomate: konserviert, passiert');
    $canned->update(['status' => 'approved', 'condition' => 'konserviert', 'main_ingredient_slug' => 'tomate']);

    $matcher = app(IngredientMatchService::class);
    expect($matcher->acceptsProductForm('passierte Tomaten', $fresh->name, $fresh->condition))->toBeTrue()
        ->and($matcher->acceptsProductForm('passierte Tomaten', $canned->name, $canned->condition))->toBeTrue();

    // Beide zulässig (kein §9-Filter), aber der Namens-Token „passiert" gewinnt die Score-Wertung.
    $match = $matcher->matchIngredient($this->rootTeam, 'passierte Tomaten', 'tomate');
    expect($match['gp_id'])->toBe($canned->id);
});
