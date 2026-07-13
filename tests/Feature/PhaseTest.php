<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\PhaseService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R4.3 — Phasen-Statusmaschine: Kontext→Struktur→Befüllung→Kalkulation→Freigabe,
 * Freigabe-Gate gegen rote Coverage-Ampeln (Override protokolliert), Browser-Filter,
 * MCP setzt Phasen außer Freigabe.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PhaseService::class);
    $this->fb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Phasen-FB']);
    $this->concept = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Phasen-Konzept']);
});

it('Statusmaschine: Phase an Foodbook UND Konzept setzbar, Default kontext, ungültig wird abgelehnt', function () {
    expect($this->fb->refresh()->phase)->toBe('kontext')
        ->and($this->concept->refresh()->phase)->toBe('kontext');

    $fb = $this->svc->setPhase($this->rootTeam, 'foodbook', $this->fb->id, 'befuellung');
    $c = $this->svc->setPhase($this->rootTeam, 'concept', $this->concept->id, 'struktur');
    expect($fb->phase)->toBe('befuellung')->and($c->phase)->toBe('struktur');

    expect(fn () => $this->svc->setPhase($this->rootTeam, 'foodbook', $this->fb->id, 'fertig'))
        ->toThrow(RuntimeException::class);
});

it('D1: geerbtes Objekt — Kind-Team setzt keine Phase', function () {
    expect(fn () => $this->svc->setPhase($this->childA, 'foodbook', $this->fb->id, 'struktur'))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
});

it('Freigabe-Gate: rote Coverage-Ampel blockt — Override mit Begründung wird protokolliert', function () {
    // Gerüst mit unerfüllbarem Pflicht-Slot → Coverage verletzt
    $frames = app(PlanningFrameService::class);
    $frame = $frames->frameFor($this->rootTeam, 'foodbook', $this->fb->id);
    $frames->addSlot($this->rootTeam, $frame, ['label' => 'Dessert', 'is_pflicht' => true, 'target_count' => 2]);

    // Ohne Override: geblockt
    expect(fn () => $this->svc->setPhase($this->rootTeam, 'foodbook', $this->fb->id, 'freigabe'))
        ->toThrow(RuntimeException::class, 'Freigabe-Gate');

    // Mit Override: durchgelassen + protokolliert
    $fb = $this->svc->setPhase($this->rootTeam, 'foodbook', $this->fb->id, 'freigabe', 'Kunde will es trotzdem — Dessert kommt extern');
    expect($fb->phase)->toBe('freigabe')
        ->and($fb->phase_override_note)->toContain('Dessert kommt extern')
        ->and($fb->phase_override_at)->not->toBeNull();
});

it('Freigabe ohne Gerüst oder mit grüner Coverage: frei (kein Gate)', function () {
    $fb = $this->svc->setPhase($this->rootTeam, 'foodbook', $this->fb->id, 'freigabe');
    expect($fb->phase)->toBe('freigabe')->and($fb->phase_override_note)->toBeNull();
});

it('Browser-Filter: paginateBrowser filtert Foodbooks und Concepts nach Phase', function () {
    $this->svc->setPhase($this->rootTeam, 'foodbook', $this->fb->id, 'kalkulation');
    FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Anderes FB']);

    $gefiltert = app(FoodbookService::class)->paginateBrowser(['phase' => 'kalkulation'], $this->rootTeam);
    expect($gefiltert->total())->toBe(1)
        ->and($gefiltert->first()->label)->toBe('Phasen-FB');

    $this->svc->setPhase($this->rootTeam, 'concept', $this->concept->id, 'befuellung');
    $concepts = app(ConceptService::class)->paginateBrowser(['phase' => 'befuellung'], $this->rootTeam);
    expect($concepts->total())->toBe(1)
        ->and($concepts->first()->name)->toBe('Phasen-Konzept');
});

it('MCP: phase.PUT setzt Arbeits-Phasen, Freigabe bleibt menschlich (Service blockt via=mcp)', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($user, $this->rootTeam);

    $ok = $registry->get('foodalchemist.phase.PUT')->execute([
        'owner_type' => 'concept', 'owner_id' => $this->concept->id, 'phase' => 'kalkulation',
    ], $kontext);
    expect($ok->success)->toBeTrue()->and($ok->data['phase'])->toBe('kalkulation');

    // Service-Härte: selbst wenn das Schema umgangen wird, blockt via=mcp die Freigabe
    expect(fn () => $this->svc->setPhase($this->rootTeam, 'concept', $this->concept->id, 'freigabe', null, 'mcp'))
        ->toThrow(RuntimeException::class, 'menschlich');

    // GET-Tools liefern die Phase mit
    $get = $registry->get('foodalchemist.concepts.GET')->execute(['concept_id' => $this->concept->id], $kontext);
    expect($get->data['concept']['phase'])->toBe('kalkulation');
});

it('UI: Phasen-Stepper im Foodbook-Editor — Klick setzt Phase, Gate-Fehler öffnet Override', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);

    $frames = app(PlanningFrameService::class);
    $frame = $frames->frameFor($this->rootTeam, 'foodbook', $this->fb->id);
    $frames->addSlot($this->rootTeam, $frame, ['label' => 'Dessert', 'is_pflicht' => true]);

    $comp = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Foodbooks\Index::class)
        ->call('waehle', $this->fb->id)
        ->assertSee('Phase')
        ->call('phaseSetzen', 'kalkulation');
    expect($this->fb->refresh()->phase)->toBe('kalkulation');

    // Gate: Freigabe blockt, Override-Feld öffnet; mit Begründung geht es durch
    $comp->call('phaseSetzen', 'freigabe')
        ->assertSet('phaseOverrideOffen', true)
        ->set('phaseOverrideNote', 'Ausnahme laut GF')
        ->call('phaseSetzen', 'freigabe')
        ->assertSet('phaseOverrideOffen', false);
    expect($this->fb->refresh()->phase)->toBe('freigabe')
        ->and($this->fb->phase_override_note)->toBe('Ausnahme laut GF');
});
