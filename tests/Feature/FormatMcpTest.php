<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Format-Modul (Phase A) — MCP-Lockstep: Reads visibleToTeam, Writes isOwnedBy +
 * draft-on-create. Spiegelt das Concepts-Tool-Muster.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->tool = fn (string $name, array $args = []) => $this->registry->get($name)->execute($args, $this->kontext);
});

it('formats.POST: legt Format als Entwurf an (draft-on-create)', function () {
    $res = ($this->tool)('foodalchemist.formats.POST', ['name' => 'CHEFS.CORNER', 'origin' => 'eigen']);
    expect($res->success)->toBeTrue()
        ->and($res->data['format']['status'])->toBe('draft')
        ->and($res->data['format']['origin'])->toBe('eigen');
});

it('formats.GET: liefert Editionen (Concept-Slots) + Preis-Range', function () {
    // F2: Editionen sind Concept-Referenz-Slots (kein format_id-Besitz mehr).
    $f = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'CHEFS.CORNER']);
    $c1 = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'FUTURE FLAVORS', 'status' => 'active', 'price_per_person_cache' => 47.50]);
    $c2 = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'FARM TO TABLE', 'status' => 'active', 'price_per_person_cache' => 49.50]);
    app(FormatService::class)->slotConceptEinfuegen($this->rootTeam, $f->id, $c1->id);
    app(FormatService::class)->slotConceptEinfuegen($this->rootTeam, $f->id, $c2->id);

    $res = ($this->tool)('foodalchemist.formats.GET', ['format_id' => $f->id]);
    expect($res->success)->toBeTrue()
        ->and($res->data['editions'])->toHaveCount(2)
        ->and($res->data['editions'][0]['name'])->toBe('FUTURE FLAVORS')
        ->and($res->data['slots'])->toHaveCount(2)
        ->and($res->data['format']['price_range'])->toBe(['min' => 47.50, 'max' => 49.50]);
});

it('format_editions.POST: fügt ein Konzept als Aufbau-Position (Slot) ein', function () {
    $f = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'CHEFS.CORNER']);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'URBAN & FLAVOUR', 'status' => 'active']);

    $res = ($this->tool)('foodalchemist.format_editions.POST', ['format_id' => $f->id, 'concept_id' => $c->id]);
    expect($res->success)->toBeTrue()
        ->and((int) $res->data['edition']['format_id'])->toBe($f->id)
        ->and((int) $res->data['edition']['concept_id'])->toBe($c->id);
    // F2e Referenz-Modell: es entsteht ein Concept-Slot, das Konzept selbst bleibt eigenständig.
    expect(FoodAlchemistFormatSlot::where('format_id', $f->id)->where('type', 'concept')->where('concept_id', $c->id)->exists())->toBeTrue()
        ->and(FoodAlchemistConcept::find($c->id))->not->toBeNull();
});

it('format_editions.DELETE: entfernt die Aufbau-Position per slot_id (Konzept bleibt)', function () {
    $f = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'CHEFS.CORNER']);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'URBAN & FLAVOUR', 'status' => 'active']);
    $slot = app(FormatService::class)->slotConceptEinfuegen($this->rootTeam, $f->id, $c->id);

    $res = ($this->tool)('foodalchemist.format_editions.DELETE', ['slot_id' => $slot->id]);
    expect($res->success)->toBeTrue()
        ->and(FoodAlchemistFormatSlot::find($slot->id))->toBeNull()
        ->and(FoodAlchemistConcept::find($c->id))->not->toBeNull();   // Referenz gelöst, Konzept bleibt
});

it('Tenancy: PUT auf ein fremdes (Kind-)Format ist NOT_FOUND aus Root-Kontext', function () {
    $fremd = FoodAlchemistFormat::create(['team_id' => $this->childA->id, 'name' => 'Kind-Format']);
    $res = ($this->tool)('foodalchemist.formats.PUT', ['format_id' => $fremd->id, 'claim' => 'hack']);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
});

// ── Format-Umbau F5: Format als Kapitel/Rubrik buchen (live, kein Sonderweg) ──────────

it('foodbook_format_chapters.POST: bucht ein Format als Kapitel (live concept_ref-Blöcke)', function () {
    $f = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'CHEFS.CORNER', 'consumer_name' => 'Chefs Corner']);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'URBAN & FLAVOUR', 'status' => 'active']);
    app(FormatService::class)->slotConceptEinfuegen($this->rootTeam, $f->id, $c->id);
    $buch = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Katalog 2027', 'status' => 'aktiv']);

    $res = ($this->tool)('foodalchemist.foodbook_format_chapters.POST', ['foodbook_id' => $buch->id, 'format_id' => $f->id]);
    expect($res->success)->toBeTrue()
        ->and($res->data['chapter']['title'])->toBe('CHEFS.CORNER');

    // C (Dominique 2026-08-27): Format = SEKTION (Struktur-Kapitel), JE KONZEPT ein Unterkapitel
    // mit einem LIVE concept_ref-Block — nicht mehr ein flaches Kapitel mit Konzept-Blöcken.
    $sektion = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::find($res->data['chapter']['id']);
    expect($sektion)->not->toBeNull()
        ->and($sektion->format_id)->toBeNull()          // kein Live-Format-Sonderweg
        ->and((bool) $sektion->is_struktur)->toBeTrue(); // Format = gruppierende Sektion

    $unter = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::where('foodbook_id', $buch->id)
        ->where('parent_id', $sektion->id)->first();
    expect($unter)->not->toBeNull()
        ->and($unter->blocks()->where('type', 'concept_ref')->where('concept_id', $c->id)->exists())->toBeTrue();
});

it('speisekarte_format_rubriken.POST: bucht ein Format als Rubrik (live menue_ref-Positionen)', function () {
    $f = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'CHEFS.CORNER', 'consumer_name' => 'Chefs Corner']);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'URBAN & FLAVOUR', 'status' => 'active', 'is_template' => false]);
    app(FormatService::class)->slotConceptEinfuegen($this->rootTeam, $f->id, $c->id);
    $karte = app(\Platform\FoodAlchemist\Services\SpeisekarteService::class)->create($this->rootTeam, ['name' => 'Abendkarte']);

    $res = ($this->tool)('foodalchemist.speisekarte_format_rubriken.POST', ['speisekarte_id' => $karte->id, 'format_id' => $f->id]);
    expect($res->success)->toBeTrue()
        ->and($res->data['rubrik']['title'])->toBe('CHEFS.CORNER');

    $rubrik = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik::find($res->data['rubrik']['id']);
    expect($rubrik)->not->toBeNull()
        ->and($rubrik->format_id)->toBeNull()   // kein Live-Format-Sonderweg
        ->and($rubrik->items()->where('type', 'menue_ref')->where('concept_id', $c->id)->exists())->toBeTrue();
});
