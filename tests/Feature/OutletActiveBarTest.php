<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\ActiveOutletBar;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\ActiveOutletContext;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — Slice D2: der „aktiver Betrieb"-Balken (FA-Sidebar) setzt den ambienten
 * ActiveOutletContext und meldet den Wechsel, damit die Preis-Flächen neu rechnen (D3).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('setzt den aktiven Betrieb in den Kontext + dispatcht den Wechsel', function () {
    $outlet = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Nord']);

    Livewire::test(ActiveOutletBar::class)
        ->set('aktiverBetrieb', $outlet->id)
        ->assertDispatched('aktiver-betrieb-geaendert');

    expect(app(ActiveOutletContext::class)->current($this->rootTeam)?->id)->toBe($outlet->id);
});

it('leere Auswahl setzt zurück auf Team-Standard', function () {
    $outlet = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Nord']);
    app(ActiveOutletContext::class)->set($this->rootTeam, $outlet->id);

    Livewire::test(ActiveOutletBar::class)
        ->set('aktiverBetrieb', '')
        ->assertDispatched('aktiver-betrieb-geaendert');

    expect(app(ActiveOutletContext::class)->current($this->rootTeam))->toBeNull();
});
