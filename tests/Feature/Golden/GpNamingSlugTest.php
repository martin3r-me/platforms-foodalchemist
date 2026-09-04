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
        'hauptzutat' => 'Vollmilch', 'condition' => 'frisch',
        'processing' => 'pasteurisiert', 'pflichtangabe' => '3,5 %', 'bio' => true,
    ]);

    expect($name)->toBe('Vollmilch: frisch, pasteurisiert, 3,5 % / (Bio)');
});

it('GT-12-03: §7.1 Verpackungswort = Hard-Error; Wort-Boundary („Dosentomate" erlaubt)', function () {
    $kiste = $this->svc->validateGpName('Tomaten Kiste: frisch', ['hauptzutat' => 'Tomaten Kiste', 'condition' => 'frisch']);
    expect($kiste['errors'])->toHaveCount(1)
        ->and($kiste['errors'][0])->toContain('§7.1');

    $dose = $this->svc->validateGpName('Dose Ananas: konserviert', ['hauptzutat' => 'Dose Ananas', 'condition' => 'konserviert']);
    expect($dose['errors'])->not->toBeEmpty();                  // „Dose" als Wort blockt

    $dosentomate = $this->svc->validateGpName('Dosentomate: konserviert', ['hauptzutat' => 'Dosentomate', 'condition' => 'konserviert']);
    expect($dosentomate['errors'])->toBeEmpty();                // Kompositum nicht
});

it('GT-12-04 (A2-SOLL): Langform tiefgekuehlt wird zu TK normalisiert, DANN valid', function () {
    expect($this->svc->normalisiereZustand('tiefgekuehlt'))->toBe('TK');

    $pruefung = $this->svc->validateGpName(
        $this->svc->renderGpName(['hauptzutat' => 'Erbse', 'condition' => 'tiefgekuehlt']),
        ['hauptzutat' => 'Erbse', 'condition' => 'tiefgekuehlt'],
    );
    expect($pruefung['errors'])->toBeEmpty();

    $kaputt = $this->svc->validateGpName('Erbse: matschig', ['hauptzutat' => 'Erbse', 'condition' => 'matschig']);
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
        'hauptzutat' => 'Tomate', 'condition' => 'trocken', 'processing' => 'pulverfoermig',
    ]);
    expect($erstes->gp_key)->toBe('tomate|pulverfoermig|')
        ->and($erstes->status->value)->toBe('tentative')
        ->and($erstes->main_ingredient_slug)->toBe('tomate');

    expect(fn () => $this->svc->createGp($this->rootTeam, [
        'hauptzutat' => 'Tomate', 'condition' => 'trocken', 'processing' => 'pulverfoermig',
    ]))->toThrow(RuntimeException::class, 'HARD_STOP_EXISTING_GP');

    $force = $this->svc->createGp($this->rootTeam, [
        'hauptzutat' => 'Tomate', 'condition' => 'trocken', 'processing' => 'pulverfoermig',
    ], force: true);
    expect($force->id)->not->toBe($erstes->id)
        ->and($force->gp_key)->toBe('tomate|pulverfoermig|~2'); // DB-UNIQUE bleibt scharf — Force-Suffix
});

it('Anlage-Guard: Jaccard ≥ 0.92 gegen bestehenden Namen blockt auch ohne Key-Kollision', function () {
    $this->svc->createGp($this->rootTeam, ['hauptzutat' => 'Limettensaft', 'condition' => 'konserviert']);

    // gleicher Name, anderes verarbeitung-Feld ⇒ anderer gp_key, aber Token-identisch
    expect(fn () => $this->svc->createGp($this->rootTeam, [
        'hauptzutat' => 'Limettensaft', 'condition' => 'konserviert', 'form' => 'Ganz',
        'name' => 'Limettensaft: konserviert',
    ]))->toThrow(RuntimeException::class, 'HARD_STOP_EXISTING_GP');
});

it('I4 Drift-Warning: manueller Name ≠ Render ⇒ Warning, kein Error', function () {
    $pruefung = $this->svc->validateGpName('Limettensaft Premium', ['hauptzutat' => 'Limettensaft', 'condition' => 'konserviert']);

    expect($pruefung['errors'])->toBeEmpty()
        ->and($pruefung['warnings'])->toHaveCount(1)
        ->and($pruefung['warnings'][0])->toContain('Drift');
});

it('§11.2: Derivat-Anlage setzt requires_la=0', function () {
    $mutter = $this->makeGp($this->rootTeam, 'Zitrone');
    $derivat = $this->svc->createGp($this->rootTeam, [
        'hauptzutat' => 'Zitronensaft', 'condition' => 'frisch',
        'is_derivat' => true, 'derivat_von_gp_id' => $mutter->id,
    ]);

    expect($derivat->is_derivat)->toBeTrue()
        ->and($derivat->requires_la)->toBeFalse()
        ->and($derivat->derivat_von_gp_id)->toBe($mutter->id);
});

it('§10: »generisch« im GP-Namen ist ein Hard-Error, Wort-Boundary bleibt gewahrt', function () {
    // Anlass 2026-09-03: »Apfel (generisch): frisch« existierte auf demo und konkurrierte im
    // Matcher PUNKTGLEICH (Score 1.001) mit »Apfel Royal Gala: Ganz«. §10 verlangt Spezifisches
    // vor Generischem; Dominique: »generisch darf gar nicht benannt werden«.
    $gen = $this->svc->validateGpName('Apfel (generisch): frisch', ['hauptzutat' => 'Apfel', 'condition' => 'frisch']);
    expect($gen['errors'])->not->toBeEmpty()
        ->and(implode(' ', $gen['errors']))->toContain('§10');

    // Englische Variante ebenso — der Marker kommt auch aus Importen.
    $en = $this->svc->validateGpName('Apple generic: frisch', ['hauptzutat' => 'Apple generic', 'condition' => 'frisch']);
    expect(implode(' ', $en['errors']))->toContain('§10');

    // Die korrekte, spezifische Benennung darf NICHT blocken — sonst wäre die Bremse eine Falle.
    $gala = $this->svc->validateGpName('Apfel Royal Gala: frisch', ['hauptzutat' => 'Apfel Royal Gala', 'condition' => 'frisch']);
    expect(implode(' ', $gala['errors']))->not->toContain('§10');

    // Wort-Boundary wie bei §7.1: ein Kompositum, das den Marker nur enthält, ist erlaubt.
    // (»Generischsalat« gibt es nicht — der Test sichert die Mechanik, nicht das Beispiel.)
    $kompositum = $this->svc->validateGpName('Generischsalat: frisch', ['hauptzutat' => 'Generischsalat', 'condition' => 'frisch']);
    expect(implode(' ', $kompositum['errors']))->not->toContain('§10');
});

it('syncIngredients behauptet nicht »manual«, wenn niemand von Hand gewaehlt hat', function () {
    // Anlass: der TK-Apfel in Gericht 3689 trug `manual`, obwohl das Rezept per MCP
    // erzeugt wurde. Der Fallback beschriftete »gp_id gesetzt, keine Methode« als
    // Handarbeit — falsche Provenienz, die die Diagnose zweimal fehlgeleitet hat.
    // Auf recipe_ingredients entscheidet niemand an `manual`; die Schutzregel in
    // SalesImportService gilt fuer foodalchemist_sales_facts, eine andere Tabelle.
    $quelle = file_get_contents(__DIR__ . '/../../../src/Services/RecipeService.php');
    $stelle = mb_strpos($quelle, "\$attrs['match_method']\n                            ??");

    expect($stelle)->not->toBeFalse();

    $zeile = mb_substr($quelle, $stelle, 220);
    expect($zeile)->not->toContain("'manual'")
        ->and($zeile)->toContain("'gp_v2_fk'")
        ->and($zeile)->toContain("'recipe_ref'")
        ->and($zeile)->toContain("'unmatched'");
});
