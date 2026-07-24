<?php

use Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment;
use Platform\FoodAlchemist\Models\FoodAlchemistEventtyp;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 19 M2 (E3.2): Dimension-Defaults am Foodbook + Einsatzmomente-Pivot + Casts.
 * Prüft nur Schema/Model/FELDER — Kaskaden-Auflösung folgt in E3.4 (leitplanken()).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->fb = app(FoodbookService::class);
});

it('update schreibt die M2-Default-Spalten (FELDER) und castet die Prozent-Werte', function () {
    $eventType = FoodAlchemistEventtyp::firstOrCreate(
        ['team_id' => $this->rootTeam->id, 'name' => 'Gala/Bankett'],
    );
    $servingForm = FoodAlchemistServierform::firstOrCreate(
        ['team_id' => $this->rootTeam->id, 'code' => 'buffet'],
        ['label' => 'Buffet'],
    );

    $foodbook = $this->fb->create($this->rootTeam, ['label' => 'Adler']);
    $this->fb->update($this->rootTeam, $foodbook->id, [
        'default_event_type_id' => $eventType->id,
        'default_serving_form_id' => $servingForm->id,
        'target_food_cost_pct' => 28.5,
        'food_cost_tolerance_pp' => 5,
    ]);

    $foodbook->refresh();
    expect($foodbook->default_event_type_id)->toBe($eventType->id)
        ->and($foodbook->default_serving_form_id)->toBe($servingForm->id)
        ->and((float) $foodbook->target_food_cost_pct)->toBe(28.5)
        ->and((float) $foodbook->food_cost_tolerance_pp)->toBe(5.0)
        ->and($foodbook->defaultEventType->name)->toBe('Gala/Bankett')
        ->and($foodbook->defaultServingForm->label)->toBe('Buffet');
});

it('Foodbook trägt 1–n Einsatzmomente über den service_moments-Pivot', function () {
    $lunch = FoodAlchemistEinsatzmoment::firstOrCreate(
        ['team_id' => $this->rootTeam->id, 'name' => 'Lunch'],
    );
    $dinner = FoodAlchemistEinsatzmoment::firstOrCreate(
        ['team_id' => $this->rootTeam->id, 'name' => 'Dinner'],
    );

    $foodbook = $this->fb->create($this->rootTeam, ['label' => 'Adler']);
    $foodbook->serviceMoments()->sync([$lunch->id, $dinner->id]);

    expect($foodbook->refresh()->serviceMoments->pluck('name')->sort()->values()->all())
        ->toBe(['Dinner', 'Lunch']);
});
