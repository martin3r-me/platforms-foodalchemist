<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Formate\Browser;
use Platform\FoodAlchemist\Livewire\Formate\DetailPanel;
use Platform\FoodAlchemist\Livewire\Formate\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Format-Modul (Phase B) — UI-Smoke: Browser/DetailPanel/Editor rendern (Blade-Compile +
 * Property-/Methoden-Existenz) und die Kern-Aktionen treiben. Layout-blind (Livewire::test),
 * darum ergänzend ein Live-Smoke der Seite von Hand.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(FormatService::class);
});

it('rendert den Formate-Browser', function () {
    $this->svc->create($this->rootTeam, ['name' => 'CHEFS.CORNER', 'status' => 'active']);
    Livewire::test(Browser::class)->assertOk()->assertSee('CHEFS.CORNER');
});

it('legt über den Browser ein Format an und öffnet den Editor', function () {
    Livewire::test(Browser::class)
        ->call('neu')
        ->assertDispatched('formate-editor.oeffnen');
    expect(FoodAlchemistFormat::where('team_id', $this->rootTeam->id)->count())->toBe(1);
});

it('Editor: lädt Identität, speichert, öffnet das Modal', function () {
    $f = $this->svc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);

    Livewire::test(Editor::class)
        ->call('oeffnen', $f->id)
        ->assertSet('id', $f->id)
        ->assertSet('tab', 'identitaet')
        ->assertDispatched('modal.open')
        ->assertSee('Marken-Story')
        ->set('form.claim', 'WORLD ON A PLATE')
        ->call('speichern')
        ->assertDispatched('formate-gespeichert');

    expect(FoodAlchemistFormat::find($f->id)->claim)->toBe('WORLD ON A PLATE');
});

it('Editor: fügt ein Konzept als Aufbau-Position ein und entfernt es wieder', function () {
    $f = $this->svc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    // F2: der Picker zeigt aktive Konzepte (Referenz — kein format_id-Filter mehr).
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'FUTURE FLAVORS', 'status' => 'active']);

    $t = Livewire::test(Editor::class)->call('oeffnen', $f->id)->call('setTab', 'editionen');
    $t->assertSee('FUTURE FLAVORS');   // im Picker (aktive Konzepte)
    $t->call('conceptEinfuegen', $c->id)->assertDispatched('formate-gespeichert');
    $slot = $f->slots()->where('type', 'concept')->firstOrFail();
    expect((int) $slot->concept_id)->toBe($c->id)
        // F2e Referenz-Modell: das Konzept selbst bleibt eigenständig (nicht gelöscht/verändert).
        ->and(FoodAlchemistConcept::find($c->id))->not->toBeNull();

    $t->call('slotEntfernen', $slot->id)->assertDispatched('formate-gespeichert');
    expect($f->slots()->count())->toBe(0);
    expect(FoodAlchemistConcept::find($c->id))->not->toBeNull();   // Konzept bleibt bestehen
});

it('#3: DetailPanel rendert Cockpit (Preisspanne) + Editionen-Liste', function () {
    $f = $this->svc->create($this->rootTeam, ['name' => 'CHEFS.CORNER', 'status' => 'active']);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'FUTURE FLAVORS', 'status' => 'active']);
    $c->update(['price_per_person_cache' => 42.00]);
    $this->svc->slotConceptEinfuegen($this->rootTeam, $f->id, $c->id);

    Livewire::test(DetailPanel::class)
        ->call('zeige', $f->id)
        ->assertSee('Preisspanne')
        ->assertSee('Editionen')
        ->assertSee('FUTURE FLAVORS')
        ->assertSee('42,00');
});

it('DetailPanel: zeigt das gewählte Format und löscht es', function () {
    $f = $this->svc->create($this->rootTeam, ['name' => 'CHEFS.CORNER', 'claim' => 'WORLD ON A PLATE']);

    Livewire::test(DetailPanel::class)
        ->call('zeige', $f->id)
        ->assertSee('CHEFS.CORNER')
        ->assertSee('WORLD ON A PLATE')
        ->call('loeschen')
        ->assertDispatched('formate-geloescht');

    expect(FoodAlchemistFormat::find($f->id))->toBeNull();
});
