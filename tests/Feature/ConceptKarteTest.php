<?php

use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * F3: Concept-„Karte" — die schöne Einzel-Concept-Ausgabe (Foodbook-styled), ZWEITE Ausgabe
 * neben dem technischen Report. Deckt FoodbookService::conceptKarteDaten (Shape + Wording-
 * Auflösung + €/Gast) und die Route/Blade `dokumente.concept-karte` ab.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(FoodbookService::class);

    $this->baueConcept = function ($team) {
        $dish = $this->makeRecipe($team, 'HG Lachs', ['is_sales_recipe' => true, 'sales_net' => 24.00]);
        $concept = $this->makeConcept($team, 'Fisch-Menü', [
            'kind' => 'concept', 'consumer_name' => 'Meeresbrise', 'claim' => 'Frisch vom Meer',
            'description' => 'Leichtes Fischmenü.', 'price_per_person_cache' => 36.00,
        ]);
        $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id, 'wording' => 'Gebratenes Lachsfilet']);

        return $concept;
    };
});

it('conceptKarteDaten liefert Titel/Claim/Preis + die aufgelösten Menü-Zeilen', function () {
    $concept = ($this->baueConcept)($this->rootTeam);

    $data = $this->svc->conceptKarteDaten($this->rootTeam, $concept->id);

    expect($data['titel'])->toBe('Meeresbrise')        // consumer_name gewinnt
        ->and($data['claim'])->toBe('Frisch vom Meer')
        ->and($data['text'])->toBe('Leichtes Fischmenü.')
        ->and($data['preis_pp'])->toBe(36.0)
        ->and(collect($data['gerichte'])->pluck('text')->all())->toContain('Gebratenes Lachsfilet');
});

it('die Concept-Karte (Route + Blade) rendert Name, Gericht-Zeile und Preis', function () {
    $concept = ($this->baueConcept)($this->rootTeam);
    $this->actingAs($this->makeUser($this->rootTeam, 'Karte User'));

    $this->get(route('foodalchemist.concepts.karte', ['id' => $concept->id]))
        ->assertOk()
        ->assertSee('Meeresbrise')
        ->assertSee('Gebratenes Lachsfilet')
        ->assertSee('36,00');
});

it('die Concept-Karte ist team-gescoped (fremdes Concept → 404)', function () {
    $fremd = ($this->baueConcept)($this->childB);
    $this->actingAs($this->makeUser($this->childA, 'Kind A'));

    $this->get(route('foodalchemist.concepts.karte', ['id' => $fremd->id]))->assertNotFound();
});
