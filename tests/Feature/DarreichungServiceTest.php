<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\DarreichungResolver;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R0.5 — DarreichungService/DarreichungResolver-Testbasis (SQLite-tragfähiger Teil).
 * ⚠️ Der Zwei-Darreichungen-Fall (Buffet gewinnt über Standard, „2,32 statt 25 €")
 * ist auf der In-Memory-SQLite NICHT abbildbar — dort wirkt der partielle
 * Ein-Standard-Unique-Index wie volles unique(recipe_id) und verbietet eine zweite
 * Darreichung. Dieser Money-Path ist auf MySQL per Smoke verifiziert (R0.2). Hier:
 * Ein-Standard-Invariante (Idempotenz-Richtung), Resolver-Standard + -Fallback.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(DarreichungService::class);
    $this->resolver = app(DarreichungResolver::class);
    FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id], ['label' => 'Unbestimmt']);

    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g-dar', 'name' => 'Testgericht',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 25.00,
    ]);
});

it('ensureStandard legt genau EINE Standard-Darreichung an und ist idempotent', function () {
    $erste = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);
    expect($erste)->not->toBeNull()
        ->and($erste->is_standard)->toBeTrue();

    // zweiter Aufruf darf KEINE zweite Standard-Zeile erzeugen (Ein-Standard-Invariante)
    $zweite = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);
    expect($zweite->id)->toBe($erste->id);

    expect(FoodAlchemistRecipeDarreichung::where('recipe_id', $this->gericht->id)->where('is_standard', true)->count())
        ->toBe(1);
    expect(FoodAlchemistRecipeDarreichung::where('recipe_id', $this->gericht->id)->count())
        ->toBe(1);
});

it('Resolver: standardFuer liefert die Standard-Darreichung des Gerichts', function () {
    $standard = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);

    $gefunden = $this->resolver->standardFuer($this->gericht->fresh());
    expect($gefunden)->not->toBeNull()
        ->and($gefunden->id)->toBe($standard->id)
        ->and($gefunden->is_standard)->toBeTrue();
});

it('Resolver-Fallback: Gericht ohne Darreichung → standardFuer ist null (kein stiller Preis)', function () {
    expect($this->resolver->standardFuer($this->gericht->fresh()))->toBeNull();
});

it('Money-Path: die Standard-Darreichung ist die Preis-Wahrheit; recipes.sales_net spiegelt sie', function () {
    // Preis-Wahrheit liegt an der Darreichung; das Legacy-Feld recipes.sales_net ist die
    // Anzeige-/Kompat-Schicht und wird AUS dem Standard gespiegelt (nicht umgekehrt).
    $standard = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);
    $this->svc->aktualisieren($this->rootTeam, $standard->id, ['sales_net' => 2.32, 'price_mode' => 'manuell']);

    expect((float) $this->resolver->standardFuer($this->gericht->fresh())->sales_net)->toBe(2.32)
        ->and((float) $this->gericht->fresh()->sales_net)->toBe(2.32); // Legacy-Feld folgt dem Standard
});
