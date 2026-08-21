<?php

use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Stufe 3 — Angebot → Produktion: ein Angebot fließt (concept × Pax) direkt in einen
 * Produktionsauftrag am Event-Tag, damit der Auto-Planer es aufgreifen kann.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('legt aus einem Angebot einen Produktionsauftrag am Event-Tag an', function () {
    $angebot = FoodAlchemistAngebot::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Sommerfest',
        'status' => 'anfrage', 'personen' => 80, 'event_date' => '2026-08-15',
    ]);
    $concept = $this->makeConcept($this->rootTeam, 'Menü A', ['offer_id' => $angebot->id]);

    $res = app(AngebotService::class)->anProduktion($this->rootTeam, $angebot->id);

    expect($res['order_id'])->not->toBeNull()
        ->and($res['ziele'])->toBe(1);

    $order = FoodAlchemistProductionOrder::find($res['order_id']);
    expect($order->production_date->toDateString())->toBe('2026-08-15')
        ->and(collect($order->targets)->pluck('concept_id'))->toContain($concept->id);
});

it('legt nichts an, wenn das Angebot keine Concepts hat', function () {
    $angebot = FoodAlchemistAngebot::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Leer', 'status' => 'anfrage', 'personen' => 20,
    ]);

    expect(app(AngebotService::class)->anProduktion($this->rootTeam, $angebot->id)['order_id'])->toBeNull();
});

// ── Werkstrang M UX-Ausbau: Angebot-Gerüst-Review (Slots vor der Voll-Kaskade) ────────────────

it('UX Angebot-Gerüst-Review: Slots am Angebot-Frame hinzufügen + löschen', function () {
    $angebot = FoodAlchemistAngebot::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Gala', 'status' => 'anfrage', 'personen' => 60,
    ]);

    $comp = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Angebote\Editor::class)
        ->call('oeffnen', $angebot->id)
        ->set('neuerSlot', 'Vorspeise')->call('geruestSlotNeu')
        ->set('neuerSlot', 'Hauptgang')->call('geruestSlotNeu')
        ->assertOk();

    $frame = app(\Platform\FoodAlchemist\Services\PlanningFrameService::class)->find('offer', (int) $angebot->id);
    expect($frame)->not->toBeNull()
        ->and($frame->slots()->orderBy('id')->pluck('label')->all())->toBe(['Vorspeise', 'Hauptgang']);

    // ersten Slot löschen → nur „Hauptgang" bleibt
    $slotId = (int) $frame->slots()->orderBy('id')->first()->id;
    $comp->call('geruestSlotLoeschen', $slotId);
    expect($frame->slots()->orderBy('id')->pluck('label')->all())->toBe(['Hauptgang']);
});
