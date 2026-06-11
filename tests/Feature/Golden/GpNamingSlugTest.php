<?php

use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M3-09: GL-12 Golden (implementierbares Teilset GT-12-01…04, 09, 10 + I6-Slug-Identität).
 * §19-Render-Beispiele (GT-12-05…08) und §12-Anti-Pattern-Reviews folgen mit dem
 * vollen Naming-Validator (V-20-Vokabular-CRUD bzw. M4-Review-Queue).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(GpNamingService::class);
});

it('GT-12-01: nur Hauptzutat ⇒ kein Doppelpunkt ohne Attribute', function () {
    expect($this->svc->renderGpName(['hauptzutat' => 'Vollmilch']))->toBe('Vollmilch');
});

it('GT-12-02: volles §6-Schema mit Pflichtangabe + Bio-Suffix', function () {
    $name = $this->svc->renderGpName([
        'hauptzutat' => 'Vollmilch', 'zustand' => 'frisch',
        'verarbeitung' => 'pasteurisiert', 'pflichtangabe' => '3,5 %', 'bio' => true,
    ]);

    expect($name)->toBe('Vollmilch: frisch, pasteurisiert, 3,5 % / (Bio)');
});

it('GT-12-03: §7.1 Verpackungswort = Hard-Error; Wort-Boundary („Dosentomate" erlaubt)', function () {
    $kiste = $this->svc->validateGpName('Tomaten Kiste: frisch', ['hauptzutat' => 'Tomaten Kiste', 'zustand' => 'frisch']);
    expect($kiste['errors'])->toHaveCount(1)
        ->and($kiste['errors'][0])->toContain('§7.1');

    $dose = $this->svc->validateGpName('Dose Ananas: konserviert', ['hauptzutat' => 'Dose Ananas', 'zustand' => 'konserviert']);
    expect($dose['errors'])->not->toBeEmpty();                  // „Dose" als Wort blockt

    $dosentomate = $this->svc->validateGpName('Dosentomate: konserviert', ['hauptzutat' => 'Dosentomate', 'zustand' => 'konserviert']);
    expect($dosentomate['errors'])->toBeEmpty();                // Kompositum nicht
});

it('GT-12-04 (A2-SOLL): Langform tiefgekuehlt wird zu TK normalisiert, DANN valid', function () {
    expect($this->svc->normalisiereZustand('tiefgekuehlt'))->toBe('TK');

    $pruefung = $this->svc->validateGpName(
        $this->svc->renderGpName(['hauptzutat' => 'Erbse', 'zustand' => 'tiefgekuehlt']),
        ['hauptzutat' => 'Erbse', 'zustand' => 'tiefgekuehlt'],
    );
    expect($pruefung['errors'])->toBeEmpty();

    $kaputt = $this->svc->validateGpName('Erbse: matschig', ['hauptzutat' => 'Erbse', 'zustand' => 'matschig']);
    expect($kaputt['errors'][0])->toContain('§9');
});

it('GT-12-09 (I6): slugify byte-identisch — ä→a (EIN Zeichen), gp_key immer 3 Slots', function () {
    expect($this->svc->slugify('Wuerfel 5 mm'))->toBe('wuerfel_5_mm')
        ->and($this->svc->slugify('Grüne Bohnen'))->toBe('grune_bohnen')     // ü→u, NICHT ue!
        ->and($this->svc->slugify('Süßkartoffel'))->toBe('suskartoffel')     // ß→s, NICHT ss!
        ->and($this->svc->slugify('  Crème (fraîche) '))->toBe('crème_fraîche') // Unicode bleibt, Ränder getrimmt
        ->and($this->svc->buildGpKey('apfel', 'Wuerfel 5 mm', null))->toBe('apfel|wuerfel_5_mm|');
});

it('GT-12-10: Anlage-Guard — identischer gp_key ⇒ HARD_STOP, force legt trotzdem an', function () {
    $erstes = $this->svc->createGp($this->rootTeam, [
        'hauptzutat' => 'Tomate', 'zustand' => 'trocken', 'verarbeitung' => 'pulverfoermig',
    ]);
    expect($erstes->gp_key)->toBe('tomate|pulverfoermig|')
        ->and($erstes->status->value)->toBe('tentative')
        ->and($erstes->hauptzutat_slug)->toBe('tomate');

    expect(fn () => $this->svc->createGp($this->rootTeam, [
        'hauptzutat' => 'Tomate', 'zustand' => 'trocken', 'verarbeitung' => 'pulverfoermig',
    ]))->toThrow(RuntimeException::class, 'HARD_STOP_EXISTING_GP');

    $force = $this->svc->createGp($this->rootTeam, [
        'hauptzutat' => 'Tomate', 'zustand' => 'trocken', 'verarbeitung' => 'pulverfoermig',
    ], force: true);
    expect($force->id)->not->toBe($erstes->id)
        ->and($force->gp_key)->toBe('tomate|pulverfoermig|~2'); // DB-UNIQUE bleibt scharf — Force-Suffix
});

it('Anlage-Guard: Jaccard ≥ 0.92 gegen bestehenden Namen blockt auch ohne Key-Kollision', function () {
    $this->svc->createGp($this->rootTeam, ['hauptzutat' => 'Limettensaft', 'zustand' => 'konserviert']);

    // gleicher Name, anderes verarbeitung-Feld ⇒ anderer gp_key, aber Token-identisch
    expect(fn () => $this->svc->createGp($this->rootTeam, [
        'hauptzutat' => 'Limettensaft', 'zustand' => 'konserviert', 'form' => 'Ganz',
        'name' => 'Limettensaft: konserviert',
    ]))->toThrow(RuntimeException::class, 'HARD_STOP_EXISTING_GP');
});

it('I4 Drift-Warning: manueller Name ≠ Render ⇒ Warning, kein Error', function () {
    $pruefung = $this->svc->validateGpName('Limettensaft Premium', ['hauptzutat' => 'Limettensaft', 'zustand' => 'konserviert']);

    expect($pruefung['errors'])->toBeEmpty()
        ->and($pruefung['warnings'])->toHaveCount(1)
        ->and($pruefung['warnings'][0])->toContain('Drift');
});

it('§11.2: Derivat-Anlage setzt requires_la=0', function () {
    $mutter = $this->makeGp($this->rootTeam, 'Zitrone');
    $derivat = $this->svc->createGp($this->rootTeam, [
        'hauptzutat' => 'Zitronensaft', 'zustand' => 'frisch',
        'is_derivat' => true, 'derivat_von_gp_id' => $mutter->id,
    ]);

    expect($derivat->is_derivat)->toBeTrue()
        ->and($derivat->requires_la)->toBeFalse()
        ->and($derivat->derivat_von_gp_id)->toBe($mutter->id);
});
