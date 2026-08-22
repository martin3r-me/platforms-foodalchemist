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

it('Editor: ordnet eine freie Edition zu und löst sie wieder', function () {
    $f = $this->svc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'FUTURE FLAVORS']);

    $t = Livewire::test(Editor::class)->call('oeffnen', $f->id)->call('setTab', 'editionen');
    $t->assertSee('FUTURE FLAVORS');   // im Picker (freie Konzepte)
    $t->call('editionZuordnen', $c->id)->assertDispatched('formate-gespeichert');
    expect((int) FoodAlchemistConcept::find($c->id)->format_id)->toBe($f->id);

    $t->call('editionLoesen', $c->id)->assertDispatched('formate-gespeichert');
    expect(FoodAlchemistConcept::find($c->id)->format_id)->toBeNull();
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
