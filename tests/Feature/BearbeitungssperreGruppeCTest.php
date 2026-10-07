<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\DetailPanel as ConcepterDetail;
use Platform\FoodAlchemist\Livewire\Concepter\Editor as ConcepterEditor;
use Platform\FoodAlchemist\Livewire\Concepts\Index as ConceptsIndex;
use Platform\FoodAlchemist\Livewire\Formate\DetailPanel as FormateDetail;
use Platform\FoodAlchemist\Livewire\Formate\Editor as FormateEditor;
use Platform\FoodAlchemist\Livewire\Pakete\Index as PaketeIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Services\BearbeitungssperreService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 65 · Bearbeitungssperre, Rollout Gruppe C: Concepter (Editor + Detailspalte), Concepts-Seite, Pakete-Seite,
 * Format-Editor + Format-Detailspalte. Je Editor: ohne „Bearbeiten" abgewiesen (DB unverändert), mit Sperre schreibbar,
 * zweite Person abgewiesen. Gleiches Ziel = gleiche Sperre über Editor und Detailspalte hinweg.
 */
beforeEach(function () {
    config(['foodalchemist.bearbeitungssperre' => true]);
    $this->seedTeamHierarchy();
    $this->anna = $this->makeUser($this->rootTeam, 'Anna');
    $this->ben = $this->makeUser($this->rootTeam, 'Ben');
    $this->svc = app(BearbeitungssperreService::class);
    $this->concept = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $this->format = app(FormatService::class)->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    $this->paket = app(PaketService::class)->create($this->rootTeam, ['name' => 'Salad Wall', 'role' => 'Vorspeise']);
});

// ── Concepter-Editor (Typ concept) ──────────────────────────────────────────

it('Concepter-Editor: ohne „Bearbeiten" Lesemodus, Speichern und Sofort-Aktionen abgewiesen', function () {
    $this->actingAs($this->anna);
    $c = Livewire::test(ConcepterEditor::class)->call('oeffnen', 'concepts', $this->concept->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->assertDontSeeHtml('data-concepter-speichern');

    $c->set('form.name', 'Geändert')->call('bearbeitungSpeichern')->call('speichern')
        ->set('neuerSlotRolle', 'Vorspeise')->call('slotHinzu');
    expect($this->concept->refresh()->name)->toBe('Grill-Buffet')
        ->and($this->concept->slots()->count())->toBe(0);

    // Lesen bleibt frei: Reiter wechseln
    $c->call('setTab', 'kalkulation')->assertSet('tab', 'kalkulation');
});

it('Concepter-Editor: „Bearbeiten" schaltet frei, Speichern schreibt und beendet, Editor bleibt offen', function () {
    $this->actingAs($this->anna);
    $c = Livewire::test(ConcepterEditor::class)->call('oeffnen', 'concepts', $this->concept->id)
        ->call('bearbeitenStarten')
        ->assertSeeHtml('data-concepter-speichern')
        ->assertSeeHtml('data-fa-lesemodus="0"');
    expect($this->svc->haelt('concept', $this->concept->id, $this->anna->id))->toBeTrue();

    // Sofort-Aktion (Position anlegen) beendet die Bearbeitung NICHT
    $c->set('neuerSlotRolle', 'Vorspeise')->call('slotHinzu');
    expect($this->concept->slots()->count())->toBe(1)
        ->and($this->svc->haelt('concept', $this->concept->id, $this->anna->id))->toBeTrue();

    $c->set('form.name', 'Grill-Buffet neu')->call('bearbeitungSpeichern')
        ->assertNotDispatched('modal.close')
        ->assertSeeHtml('data-bearbeiten-starten');
    expect($this->concept->refresh()->name)->toBe('Grill-Buffet neu')
        ->and($this->svc->haelt('concept', $this->concept->id, $this->anna->id))->toBeFalse();
});

it('Concepter-Editor: zweite Person sieht „wird bearbeitet" und kann nicht schreiben', function () {
    $this->svc->sperren('concept', $this->concept->id, $this->anna->id, 'Anna');
    $this->actingAs($this->ben);

    $c = Livewire::test(ConcepterEditor::class)->call('oeffnen', 'concepts', $this->concept->id)
        ->assertSeeHtml('data-bearbeiten-fremd')->assertSee('Wird von Anna bearbeitet')
        ->call('bearbeitenStarten');
    expect($this->svc->haelt('concept', $this->concept->id, $this->ben->id))->toBeFalse();

    $c->set('form.name', 'Von Ben')->call('bearbeitungSpeichern');
    expect($this->concept->refresh()->name)->toBe('Grill-Buffet');
});

it('Concepter-Editor: Schließen und Wechsel in ein anderes Concept geben die eigene Sperre frei', function () {
    $this->actingAs($this->anna);
    Livewire::test(ConcepterEditor::class)->call('oeffnen', 'concepts', $this->concept->id)
        ->call('bearbeitenStarten')
        ->dispatch('modal.closed', name: 'concepter-editor');
    expect($this->svc->haelt('concept', $this->concept->id, $this->anna->id))->toBeFalse();

    $anderes = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Fingerfood']);
    Livewire::test(ConcepterEditor::class)->call('oeffnen', 'concepts', $this->concept->id)
        ->call('bearbeitenStarten')
        ->call('oeffnen', 'concepts', $anderes->id);
    expect($this->svc->haelt('concept', $this->concept->id, $this->anna->id))->toBeFalse();
});

it('Concepter-Editor: Sperre gilt Concept-übergreifend — Concepts-Seite und Detailspalte sehen dieselbe Sperre', function () {
    $this->svc->sperren('concept', $this->concept->id, $this->anna->id, 'Anna');
    $this->actingAs($this->ben);

    Livewire::test(ConceptsIndex::class)->call('waehle', $this->concept->id)
        ->assertSeeHtml('data-bearbeiten-fremd');
    Livewire::test(ConcepterDetail::class)->call('zeige', 'concepts', $this->concept->id)
        ->assertSeeHtml('data-bearbeiten-fremd');
});

// ── Concepter-Detailspalte (sofort speichernd) ─────────────────────────────

it('Concepter-Detailspalte: Löschen nur mit Sperre, Kopien ohne; Fertig gibt frei', function () {
    $this->actingAs($this->anna);
    $c = Livewire::test(ConcepterDetail::class)->call('zeige', 'concepts', $this->concept->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->call('loeschen');
    expect(FoodAlchemistConcept::find($this->concept->id))->not->toBeNull();

    $c->call('bearbeitenStarten')->assertSeeHtml('data-bearbeiten-fertig')->call('bearbeitenFertig');
    expect($this->svc->haelt('concept', $this->concept->id, $this->anna->id))->toBeFalse();

    $c->call('bearbeitenStarten')->call('loeschen');
    expect(FoodAlchemistConcept::find($this->concept->id))->toBeNull()
        ->and($this->svc->haelt('concept', $this->concept->id, $this->anna->id))->toBeFalse();
});

// ── Concepts-Seite (Typ concept) ───────────────────────────────────────────

it('Concepts-Seite: Speichern ohne Sperre abgewiesen, mit Sperre schreibt es, zweite Person abgewiesen', function () {
    $this->actingAs($this->anna);
    $c = Livewire::test(ConceptsIndex::class)->call('waehle', $this->concept->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"');
    $c->set('form.name', 'Ohne Sperre')->call('speichern')->call('slotHinzu');
    expect($this->concept->refresh()->name)->toBe('Grill-Buffet')
        ->and($this->concept->slots()->count())->toBe(0);

    $c->call('bearbeitenStarten')->assertSeeHtml('data-concept-speichern')
        ->set('form.name', 'Mit Sperre')->call('speichern');
    expect($this->concept->refresh()->name)->toBe('Mit Sperre')
        ->and($this->svc->haelt('concept', $this->concept->id, $this->anna->id))->toBeFalse();

    $this->svc->sperren('concept', $this->concept->id, $this->anna->id, 'Anna');
    $this->actingAs($this->ben);
    $b = Livewire::test(ConceptsIndex::class)->call('waehle', $this->concept->id)->call('bearbeitenStarten');
    expect($this->svc->haelt('concept', $this->concept->id, $this->ben->id))->toBeFalse();
    $b->set('form.name', 'Von Ben')->call('speichern');
    expect($this->concept->refresh()->name)->toBe('Mit Sperre');
});

// ── Pakete-Seite (Typ paket) ───────────────────────────────────────────────

it('Pakete-Seite: Speichern ohne Sperre abgewiesen, mit Sperre schreibt es, zweite Person abgewiesen', function () {
    $this->actingAs($this->anna);
    $c = Livewire::test(PaketeIndex::class)->call('waehle', $this->paket->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"');
    $c->set('form.name', 'Ohne Sperre')->call('speichern');
    expect(FoodAlchemistPaket::find($this->paket->id)->name)->toBe('Salad Wall');

    $c->call('bearbeitenStarten')->assertSeeHtml('data-paket-speichern')
        ->set('form.name', 'Mit Sperre')->call('speichern')
        ->assertSeeHtml('data-bearbeiten-starten');
    expect(FoodAlchemistPaket::find($this->paket->id)->name)->toBe('Mit Sperre')
        ->and($this->svc->haelt('paket', $this->paket->id, $this->anna->id))->toBeFalse();

    $this->svc->sperren('paket', $this->paket->id, $this->anna->id, 'Anna');
    $this->actingAs($this->ben);
    $b = Livewire::test(PaketeIndex::class)->call('waehle', $this->paket->id)
        ->assertSeeHtml('data-bearbeiten-fremd')->call('bearbeitenStarten');
    expect($this->svc->haelt('paket', $this->paket->id, $this->ben->id))->toBeFalse();
    $b->set('form.name', 'Von Ben')->call('speichern');
    expect(FoodAlchemistPaket::find($this->paket->id)->name)->toBe('Mit Sperre');
});

// ── Format-Editor (Typ format) ─────────────────────────────────────────────

it('Format-Editor: ohne „Bearbeiten" abgewiesen, mit Sperre schreibbar, Speichern beendet, zweite Person abgewiesen', function () {
    $this->actingAs($this->anna);
    $c = Livewire::test(FormateEditor::class)->call('oeffnen', $this->format->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->assertDontSeeHtml('data-format-speichern');
    $c->set('form.name', 'Ohne Sperre')->call('bearbeitungSpeichern')->call('speichern')->call('blockHinzu', 'header');
    expect($this->format->refresh()->name)->toBe('CHEFS.CORNER')
        ->and($this->format->slots()->count())->toBe(0);

    // Lesen bleibt frei: Reiter + Picker-Reiter
    $c->call('setTab', 'editionen')->assertSet('tab', 'editionen')->call('setPickerTab', 'paket')->assertSet('pickerTab', 'paket');

    $c->call('bearbeitenStarten')->assertSeeHtml('data-format-speichern')
        ->call('blockHinzu', 'header');
    expect($this->format->slots()->count())->toBe(1)
        ->and($this->svc->haelt('format', $this->format->id, $this->anna->id))->toBeTrue();   // Sofort-Aktion beendet nicht

    $c->set('form.name', 'Mit Sperre')->call('bearbeitungSpeichern')
        ->assertNotDispatched('modal.close')
        ->assertSeeHtml('data-bearbeiten-starten');
    expect($this->format->refresh()->name)->toBe('Mit Sperre')
        ->and($this->svc->haelt('format', $this->format->id, $this->anna->id))->toBeFalse();

    $this->svc->sperren('format', $this->format->id, $this->anna->id, 'Anna');
    $this->actingAs($this->ben);
    $b = Livewire::test(FormateEditor::class)->call('oeffnen', $this->format->id)
        ->assertSeeHtml('data-bearbeiten-fremd')->call('bearbeitenStarten');
    expect($this->svc->haelt('format', $this->format->id, $this->ben->id))->toBeFalse();
    $b->set('form.name', 'Von Ben')->call('bearbeitungSpeichern');
    expect($this->format->refresh()->name)->toBe('Mit Sperre');
});

it('Format-Editor: Schließen gibt die eigene Sperre frei', function () {
    $this->actingAs($this->anna);
    Livewire::test(FormateEditor::class)->call('oeffnen', $this->format->id)
        ->call('bearbeitenStarten')
        ->dispatch('modal.closed', name: 'formate-editor');
    expect($this->svc->haelt('format', $this->format->id, $this->anna->id))->toBeFalse();
});

// ── Format-Detailspalte (sofort speichernd) ────────────────────────────────

it('Format-Detailspalte: Löschen nur mit Sperre, fremde Sperre aus dem Editor gilt auch hier', function () {
    $this->actingAs($this->anna);
    $c = Livewire::test(FormateDetail::class)->call('zeige', $this->format->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->call('loeschen');
    expect(app(FormatService::class)->detail($this->rootTeam, $this->format->id))->not->toBeNull();

    $this->svc->sperren('format', $this->format->id, $this->ben->id, 'Ben');
    $c->call('bearbeitenStarten')->assertSeeHtml('data-bearbeiten-fremd')->call('loeschen');
    expect(app(FormatService::class)->detail($this->rootTeam, $this->format->id))->not->toBeNull();

    $this->svc->freigeben('format', $this->format->id, $this->ben->id);
    $c->call('bearbeitenStarten')->call('loeschen');
    expect(app(FormatService::class)->detail($this->rootTeam, $this->format->id))->toBeNull();
});
