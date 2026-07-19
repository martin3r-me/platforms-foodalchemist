<?php

use Platform\FoodAlchemist\Models\FoodAlchemistStammLieferant;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\LaCandidateFinder;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/**
 * Spec 16·S2 — WG-Lead-gescopter LA-Kandidaten-Finder.
 * Deterministisch (kein LLM): Lexik/Terminologie + WG-Lead-Scope + Anti-Marker.
 */
uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->finder = app(LaCandidateFinder::class);

    $this->mkSupplier = fn (string $name) => FoodAlchemistSupplier::create([
        'team_id' => $this->rootTeam->id, 'name' => $name,
    ]);
    $this->mkLa = fn (int $supplierId, string $designation) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplierId,
        'designation' => $designation, 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
    $this->setLead = fn (int $supplierId, ?string $wg) => FoodAlchemistStammLieferant::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplierId, 'commodity_group_code' => $wg,
    ]);
});

it('findet den lexikalisch besten LA im Pool (verbose Designation)', function () {
    $s = ($this->mkSupplier)('Hanos');
    ($this->mkLa)($s->id, 'Tomaten geschält 2500g Dose');
    ($this->mkLa)($s->id, 'Zanderfilet ohne Haut TK');

    $best = $this->finder->best($this->rootTeam, 'Tomate');

    expect($best)->not->toBeNull()
        ->and($best->designation)->toContain('Tomaten')
        ->and($best->score)->toBeGreaterThan(0.0);
});

it('verengt bei WG-Hint auf die WG-Lead-Lieferanten und rankt den Lead vorn', function () {
    $lead = ($this->mkSupplier)('Chefs Culinar');
    $fremd = ($this->mkSupplier)('Irgendwer');
    ($this->setLead)($lead->id, '03');                      // Lead für WG 03
    ($this->mkLa)($lead->id, 'Basilikum frisch');
    ($this->mkLa)($fremd->id, 'Basilikum frisch');

    $best = $this->finder->best($this->rootTeam, 'Basilikum', '03');

    expect($best)->not->toBeNull()
        ->and($best->supplier_id)->toBe($lead->id)          // der WG-Lead gewinnt
        ->and($best->ist_lead)->toBeTrue();
});

it('löst Dialekt-Alias auf (Paradeiser → Tomate, Weg-2-Terminologie)', function () {
    $s = ($this->mkSupplier)('Hanos');
    ($this->mkLa)($s->id, 'Tomaten passiert 500g');

    $best = $this->finder->best($this->rootTeam, 'Paradeiser');

    expect($best)->not->toBeNull()
        ->and($best->designation)->toContain('Tomaten');
});

it('unterdrückt die Anti-Marker-Falle: Brie findet nicht den Bries-LA', function () {
    // Token-Form "Bries" (Kalbsthymus, Innerei) — der Anti-Marker greift auf Token-Ebene.
    // Compound wie "Kalbsbries" ist die bekannte S3-Decompounding-Lücke (Code-Kommentar).
    $s = ($this->mkSupplier)('Metro');
    ($this->mkLa)($s->id, 'Bries frisch');

    expect($this->finder->best($this->rootTeam, 'Brie'))->toBeNull();
});

it('bevorzugt bei vorhandenem Brie den Brie-Käse vor dem Bries', function () {
    $s = ($this->mkSupplier)('Metro');
    ($this->mkLa)($s->id, 'Bries frisch');
    $brie = ($this->mkLa)($s->id, 'Brie de Meaux');

    $best = $this->finder->best($this->rootTeam, 'Brie');

    expect($best)->not->toBeNull()
        ->and($best->id)->toBe($brie->id);
});

it('fällt bei WG ohne definierte Leads auf globale Suche zurück (E2)', function () {
    $s = ($this->mkSupplier)('Hanos');
    ($this->mkLa)($s->id, 'Tomaten geschält');
    // Keine preferred_suppliers-Zeile für WG 99.

    $best = $this->finder->best($this->rootTeam, 'Tomate', '99');

    expect($best)->not->toBeNull()
        ->and($best->designation)->toContain('Tomaten');
});

it('gibt null zurück, wenn keine LA lexikalisch passt', function () {
    $s = ($this->mkSupplier)('Hanos');
    ($this->mkLa)($s->id, 'Zanderfilet');

    expect($this->finder->best($this->rootTeam, 'Marsianische Nichtzutat'))->toBeNull();
});
