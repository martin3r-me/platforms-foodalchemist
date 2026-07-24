<?php

use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 19 E4.2: `kapitelZiele()` — aufgelöste SOLL-Sicht mit Vererbung
 * Kapitel → Eltern → Slot → Foodbook + Herkunfts-Quellen je Feld.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(FoodbookService::class);
    $this->frames = app(PlanningFrameService::class);
});

it('leeres Kapitel ohne Slot/Foodbook-Default → alle Felder null + Quellen null', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Leer']);
    $kapitel = $this->svc->addKapitel($this->rootTeam, $fb->id, ['title' => 'Vorspeisen']);

    $z = $this->svc->kapitelZiele($this->rootTeam, $kapitel);
    expect($z)->toHaveKeys([
        'target_count', 'price_anchor', 'price_min', 'price_max', 'niveau',
        'serving_form_id', 'service_moment_id', 'pricing_mode', 'target_food_cost_pct', 'quellen',
    ])
        ->and($z['target_count'])->toBeNull()
        ->and($z['price_anchor'])->toBeNull()
        ->and($z['niveau'])->toBeNull()
        ->and($z['pricing_mode'])->toBeNull()
        ->and($z['quellen']['target_count'])->toBeNull()
        ->and($z['quellen']['niveau'])->toBeNull();
});

it('eigener Kapitel-Wert gewinnt (Quelle kapitel)', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Eigen']);
    $this->svc->update($this->rootTeam, $fb->id, ['target_food_cost_pct' => 30.0]); // Foodbook-Default
    $kapitel = $this->svc->addKapitel($this->rootTeam, $fb->id, ['title' => 'Haupt']);
    $this->svc->updateKapitel($this->rootTeam, $kapitel->id, [
        'target_count' => 3, 'price_anchor' => 12.50, 'niveau' => 'premium',
        'pricing_mode' => 'einzel', 'target_food_cost_pct' => 27.0,
    ]);

    $z = $this->svc->kapitelZiele($this->rootTeam, $kapitel->refresh());
    expect($z['target_count'])->toBe(3)
        ->and($z['price_anchor'])->toBe(12.50)
        ->and($z['niveau'])->toBe('premium')
        ->and($z['pricing_mode'])->toBe('einzel')
        ->and($z['target_food_cost_pct'])->toBe(27.0)          // Kapitel schlägt Foodbook
        ->and($z['quellen']['target_count'])->toBe('kapitel')
        ->and($z['quellen']['target_food_cost_pct'])->toBe('kapitel');
});

it('leeres Kind-Kapitel erbt vom Eltern-Kapitel (Quelle eltern)', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Erbe']);
    $eltern = $this->svc->addKapitel($this->rootTeam, $fb->id, ['title' => 'Bankett']);
    $this->svc->updateKapitel($this->rootTeam, $eltern->id, [
        'target_count' => 5, 'niveau' => 'gehoben', 'pricing_mode' => 'paket',
    ]);
    $kind = $this->svc->addKapitel($this->rootTeam, $fb->id, ['title' => 'Vorspeisen'], $eltern->id);

    $z = $this->svc->kapitelZiele($this->rootTeam, $kind->refresh());
    expect($z['target_count'])->toBe(5)
        ->and($z['niveau'])->toBe('gehoben')
        ->and($z['pricing_mode'])->toBe('paket')
        ->and($z['quellen']['target_count'])->toBe('eltern')
        ->and($z['quellen']['niveau'])->toBe('eltern')
        ->and($z['quellen']['pricing_mode'])->toBe('eltern');
});

it('Slot-Fallback: Kapitel-Feld leer → verknüpfter Planungs-Slot liefert die Menge/Preis-Ziele', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Slot-Fallback']);
    $kapitel = $this->svc->addKapitel($this->rootTeam, $fb->id, ['title' => 'Fingerfood']);
    $frame = $this->frames->frameFor($this->rootTeam, 'foodbook', $fb->id);
    $slot = $this->frames->addSlot($this->rootTeam, $frame, [
        'label' => 'Fingerfood', 'slot_type' => 'gang', 'target_count' => 7, 'price_anchor' => 8.00,
    ]);
    // Slot direkt ans Kapitel koppeln OHNE Stempelung (Kapitel-Felder bleiben null).
    $this->frames->updateSlot($this->rootTeam, $slot->id, ['chapter_id' => $kapitel->id]);

    $z = $this->svc->kapitelZiele($this->rootTeam, $kapitel->refresh());
    expect($z['target_count'])->toBe(7)
        ->and($z['price_anchor'])->toBe(8.00)
        ->and($z['quellen']['target_count'])->toBe('slot')
        ->and($z['quellen']['price_anchor'])->toBe('slot');
});

it('Foodbook-Fallback: Servierform/WE-Ziel/Niveau lösen aus den Foodbook-Defaults auf', function () {
    $servingForm = FoodAlchemistServierform::firstOrCreate(
        ['team_id' => $this->rootTeam->id, 'code' => 'buffet'], ['label' => 'Buffet']
    );
    $fb = $this->svc->create($this->rootTeam, ['label' => 'FB-Default']);
    $this->svc->update($this->rootTeam, $fb->id, [
        'default_niveau' => 'klassisch',
        'default_serving_form_id' => $servingForm->id,
        'target_food_cost_pct' => 30.0,
    ]);
    $kapitel = $this->svc->addKapitel($this->rootTeam, $fb->id, ['title' => 'Haupt']);

    $z = $this->svc->kapitelZiele($this->rootTeam, $kapitel->refresh());
    expect($z['niveau'])->toBe('klassisch')
        ->and($z['serving_form_id'])->toBe((int) $servingForm->id)
        ->and($z['target_food_cost_pct'])->toBe(30.0)
        ->and($z['quellen']['niveau'])->toBe('foodbook')
        ->and($z['quellen']['serving_form_id'])->toBe('foodbook')
        ->and($z['quellen']['target_food_cost_pct'])->toBe('foodbook');
});
