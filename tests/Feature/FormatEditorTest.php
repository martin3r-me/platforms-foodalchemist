<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Formate\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Format-Modul (Phase D, Concepter 2.0) — Format-Editor als Oberkapitel→Unterkapitel-Baum:
 * Editionen inline anlegen (mit Auto-Sektions-Gerüst), Wording pro Edition (Titel/Claim/
 * Hinführung, Foodbook-Kapitel-Parität).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->fsvc = app(FormatService::class);
    $this->fbsvc = app(FoodbookService::class);
});

it('createEdition legt ein Concept an, hängt es als Edition an und seedet das Sektions-Gerüst', function () {
    $f = $this->fsvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);

    $ed = $this->fsvc->createEdition($this->rootTeam, $f->id, 'FUTURE FLAVORS', true);

    expect((int) $ed->format_id)->toBe($f->id)
        ->and($ed->name)->toBe('FUTURE FLAVORS')
        ->and($ed->status)->toBe('draft')
        ->and($ed->slots()->where('type', 'header')->count())->toBe(count(FormatService::SEKTIONS_GERUEST));
});

it('updateEditionWording pflegt Titel/Claim/Hinführung (claim neu speicherbar)', function () {
    $f = $this->fsvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    $ed = $this->fsvc->createEdition($this->rootTeam, $f->id, 'FUTURE FLAVORS', false);

    $ed = $this->fsvc->updateEditionWording($this->rootTeam, $f->id, $ed->id, [
        'consumer_name' => 'Future Flavors', 'claim' => 'Die neue Küche der Welt', 'description' => 'Molecular, live angerichtet.',
    ]);

    expect($ed->consumer_name)->toBe('Future Flavors')
        ->and($ed->claim)->toBe('Die neue Küche der Welt')
        ->and($ed->description)->toBe('Molecular, live angerichtet.');
});

it('updateEditionWording lehnt ein Concept ab, das nicht zu DIESEM Format gehört', function () {
    $f = $this->fsvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    $fremd = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Standalone']); // kein format_id

    expect(fn () => $this->fsvc->updateEditionWording($this->rootTeam, $f->id, $fremd->id, ['claim' => 'x']))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('Editor: neue Edition inline anlegen (als Concept-Slot) + Wording des Concepts speichern', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $f = $this->fsvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);

    $t = Livewire::test(Editor::class)->call('oeffnen', $f->id)->call('setTab', 'editionen')
        ->set('neueEditionName', 'FARM TO TABLE')->call('neueEdition')
        ->assertDispatched('formate-gespeichert');

    // F2: die Edition ist eine Concept-Referenz (Slot), kein format_id-Besitz.
    $slot = $f->slots()->where('type', 'concept')->firstOrFail();
    $ed = $slot->concept;
    expect($ed->name)->toBe('FARM TO TABLE')
        ->and($ed->status)->toBe('active')
        ->and($ed->format_id)->toBeNull()
        ->and($ed->slots()->where('type', 'header')->count())->toBe(count(FormatService::SEKTIONS_GERUEST));

    $t->call('conceptWordingSpeichern', $ed->id, 'claim', 'Natur pur auf dem Teller')
        ->assertDispatched('formate-gespeichert');
    expect(FoodAlchemistConcept::find($ed->id)->claim)->toBe('Natur pur auf dem Teller');
});

it('Editor: Konzept einfügen · Struktur-Block · Reihenfolge · entfernen (Aufbau auf Slots)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $f = $this->fsvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'FUTURE FLAVORS', 'status' => 'active']);

    $t = Livewire::test(Editor::class)->call('oeffnen', $f->id)->call('setTab', 'editionen');
    // Konzept als Position + einen Header-Block einfügen.
    $t->call('conceptEinfuegen', $c->id)->assertDispatched('formate-gespeichert');
    $t->call('blockHinzu', 'header')->assertDispatched('formate-gespeichert');

    $slots = $f->slots()->orderBy('position')->get();
    expect($slots)->toHaveCount(2)
        ->and($slots[0]->type)->toBe('concept')
        ->and($slots[1]->type)->toBe('header');

    // Reihenfolge tauschen: Header nach oben.
    $t->call('slotHochRunter', $slots[1]->id, -1)->assertDispatched('formate-gespeichert');
    expect($f->slots()->orderBy('position')->pluck('type')->all())->toBe(['header', 'concept']);

    // Concept-Slot entfernen — Konzept bleibt bestehen.
    $conceptSlot = $f->slots()->where('type', 'concept')->firstOrFail();
    $t->call('slotEntfernen', $conceptSlot->id)->assertDispatched('formate-gespeichert');
    expect($f->slots()->count())->toBe(1)
        ->and(FoodAlchemistConcept::find($c->id))->not->toBeNull();
});
