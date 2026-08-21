<?php

use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 41 B3: deterministischer Container-Struktur-Guard (expandiereContainerGeruest) — ein
 * Buffet/Menü-Brief darf nie auf 1 Position kollabieren (RC-4 / Fall 003 »Lunchbuffet«).
 * Der Guard ist rein (keine DB/kein LLM) → per Reflection getestet.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ConceptGeneratorService::class);

    $this->guard = function (array $slots, string $brief, array $achsen) {
        $m = new ReflectionMethod(ConceptGeneratorService::class, 'expandiereContainerGeruest');
        $m->setAccessible(true);

        return $m->invoke($this->svc, $slots, $brief, $achsen);
    };

    $this->slot = fn (string $label, string $typ) => [
        'label' => $label, 'slot_type' => $typ, 'target_count' => 1,
        'price_anchor' => null, 'price_min' => null, 'price_max' => null,
        'is_pflicht' => true, 'rules' => [],
    ];
});

it('expandiert ein kollabiertes Buffet in kanonische Stationen', function () {
    $kollabiert = [($this->slot)('Lunchbuffet', 'kapitel')];

    $out = ($this->guard)($kollabiert, 'lunchbuffet für den sommer als tagung', []);
    $labels = array_column($out, 'label');
    $typen = array_unique(array_column($out, 'slot_type'));

    expect(count($out))->toBeGreaterThanOrEqual(5)
        ->and($typen)->toBe(['station'])
        ->and($labels)->toContain('Kalte Vorspeisen / Salate')
        ->and($labels)->toContain('Warme Hauptkomponente')
        ->and($labels)->toContain('Dessert / Sweet-Table');
});

it('respektiert menue_typ=buffet auch ohne Brief-Schlagwort', function () {
    $out = ($this->guard)([($this->slot)('Alles', 'gang')], 'Sommerliches Konzept', ['menue_typ' => 'buffet']);

    expect(array_unique(array_column($out, 'slot_type')))->toBe(['station'])
        ->and(count($out))->toBeGreaterThanOrEqual(5);
});

it('lässt ein bereits mehrgliedriges Buffet unangetastet (golden-safe)', function () {
    $gut = [
        ($this->slot)('Salate', 'station'),
        ($this->slot)('Warmes', 'station'),
        ($this->slot)('Dessert', 'station'),
    ];

    $out = ($this->guard)($gut, 'buffet', []);

    expect($out)->toBe($gut);
});

it('expandiert ein kollabiertes Menü in Gänge, Dessert zuletzt', function () {
    $out = ($this->guard)([($this->slot)('Menü', 'kapitel')], 'festliches 5-gänge menü', ['menue_typ' => 'menue', 'menue_gaenge' => 5]);
    $labels = array_column($out, 'label');

    expect(array_unique(array_column($out, 'slot_type')))->toBe(['gang'])
        ->and(count($out))->toBe(5)
        ->and(end($labels))->toBe('Dessert');
});

it('greift NICHT ohne Container-Archetyp (Einzelgericht-Brief bleibt)', function () {
    // »menüteller« ist KEIN Menü-Container (verankerte Regex, nicht \b) → Archetyp null → No-op.
    $einzel = [($this->slot)('Rinderfilet Hauptgang', 'gang')];

    $out = ($this->guard)($einzel, 'herbstlicher menüteller hauptgang mit rinderfilet', []);

    expect($out)->toBe($einzel);
});
