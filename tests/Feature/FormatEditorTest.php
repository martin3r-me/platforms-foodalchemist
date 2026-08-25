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

it('Editor: neue Edition inline anlegen (als Concept-Slot) + Wording des Concepts speichern', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $f = $this->fsvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);

    $t = Livewire::test(Editor::class)->call('oeffnen', $f->id)->call('setTab', 'editionen')
        ->set('neueEditionName', 'FARM TO TABLE')->call('neueEdition')
        ->assertDispatched('formate-gespeichert');

    // F2e: die Edition ist eine reine Concept-Referenz (Slot), kein Besitz.
    $slot = $f->slots()->where('type', 'concept')->firstOrFail();
    $ed = $slot->concept;
    expect($ed->name)->toBe('FARM TO TABLE')
        ->and($ed->status)->toBe('active')
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

it('Format C1: eine Gericht-Zeile format-lokal überschreiben (Concept unangetastet)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $dish = $this->makeRecipe($this->rootTeam, 'Rinderfilet', ['is_sales_recipe' => true, 'sales_net' => 30.0]);
    $concept = $this->makeConcept($this->rootTeam, 'Menü', ['kind' => 'concept', 'status' => 'active']);
    $cslot = $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id]);
    $f = $this->fsvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    $fslot = $this->fsvc->slotConceptEinfuegen($this->rootTeam, $f->id, $concept->id);

    Livewire::test(Editor::class)->call('oeffnen', $f->id)->call('setTab', 'editionen')
        ->call('slotWordingBearbeiten', $fslot->id, $cslot->id, '')
        ->set('editSlotWording', 'Rinderfilet Rossini (Format)')
        ->call('slotWordingSpeichern')
        ->assertDispatched('formate-gespeichert');

    // Format-lokaler Override am format_slot; der Concept-Slot bleibt unangetastet (Format-Override
    // leakt NICHT ins Concept).
    $vorher = $cslot->wording;
    $payload = $fslot->fresh()->payload_json ?? [];
    expect($payload['wording_overrides'][(string) $cslot->id] ?? null)->toBe('Rinderfilet Rossini (Format)')
        ->and($cslot->fresh()->wording)->toBe($vorher)
        ->and($cslot->fresh()->wording)->not->toBe('Rinderfilet Rossini (Format)');

    // WordingResolver wendet den Override mit dem Format-Slot als Kontext an (Vorschau + Druck).
    $zeilen = app(\Platform\FoodAlchemist\Services\WordingResolver::class)->gerichtZeilen($concept->fresh(), $fslot->fresh());
    expect(collect($zeilen)->firstWhere('type', 'gericht')['text'])->toBe('Rinderfilet Rossini (Format)');
});
