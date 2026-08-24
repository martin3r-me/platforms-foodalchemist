<?php

use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * F3: Format-Druck — schöne Kunden-Ausgabe (Foodbook-styled) über FormatService::dokumentDaten
 * + die Route/Blade `dokumente.format`. Deckt die positionen-Struktur (Edition/Header/Text),
 * die Menü-Zeilen-Auflösung (WordingResolver), den €/Gast-Preis und die Team-Scope-Härte ab.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(FormatService::class);

    // Concept-Edition mit einem Gericht (Kunden-Wording) + €/Person-Cache.
    $this->baueFormat = function ($team) {
        $dish = $this->makeRecipe($team, 'HG Rinderfilet', ['is_sales_recipe' => true, 'sales_net' => 30.00]);
        $concept = $this->makeConcept($team, 'Sommer-Menü', [
            'kind' => 'concept', 'consumer_name' => 'Sommergenuss', 'claim' => 'Leicht & frisch',
            'description' => 'Ein sommerliches Menü.', 'price_per_person_cache' => 42.50,
        ]);
        $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id, 'wording' => 'Rinderfilet Rossini']);

        $format = $this->svc->create($team, ['name' => 'CHEFS.CORNER', 'claim' => 'WORLD ON A PLATE', 'story' => 'Die Welt auf dem Teller.']);
        $this->svc->slotConceptEinfuegen($team, $format->id, $concept->id);
        $this->svc->slotBlockEinfuegen($team, $format->id, 'header', ['title' => 'Unsere Editionen']);
        $this->svc->slotBlockEinfuegen($team, $format->id, 'text', ['text_content' => 'Ein Wort zum Konzept.']);

        return $format;
    };
});

it('dokumentDaten liefert Identität + positionen (Edition/Header/Text) in Reihenfolge', function () {
    $format = ($this->baueFormat)($this->rootTeam);

    $data = $this->svc->dokumentDaten($this->rootTeam, $format->id);

    expect($data['name'])->toBe('CHEFS.CORNER')
        ->and($data['claim'])->toBe('WORLD ON A PLATE')
        ->and($data['story'])->toBe('Die Welt auf dem Teller.');

    // Positionen: erst die Edition, dann Header, dann Text (Slot-Reihenfolge).
    expect(collect($data['positionen'])->pluck('kind')->all())->toBe(['edition', 'header', 'text']);

    $edition = collect($data['positionen'])->firstWhere('kind', 'edition');
    expect($edition['title'])->toBe('Sommergenuss')          // consumer_name gewinnt über name
        ->and($edition['claim'])->toBe('Leicht & frisch')
        ->and($edition['preis_pp'])->toBe(42.5)
        ->and(collect($edition['gerichte'])->pluck('text')->all())->toContain('Rinderfilet Rossini');

    // Preis-Range über die Editionen (nur das eine Concept).
    expect($data['range']['min'])->toBe(42.5)->and($data['range']['max'])->toBe(42.5);
});

it('das Format-Dokument (Route + Blade) rendert Edition, Gericht-Zeile, Preis und Struktur', function () {
    $format = ($this->baueFormat)($this->rootTeam);
    $this->actingAs($this->makeUser($this->rootTeam, 'Format User'));

    $this->get(route('foodalchemist.formate.dokument', ['id' => $format->id]))
        ->assertOk()
        ->assertSee('Sommergenuss')          // Edition-Titel (Konsumentenname)
        ->assertSee('Rinderfilet Rossini')   // aufgelöste Gericht-Zeile
        ->assertSee('42,50')                 // €/Gast
        ->assertSee('Unsere Editionen')      // Header-Block
        ->assertSee('Ein Wort zum Konzept.') // Text-Block
        ->assertSee('CHEFS.CORNER');
});

it('das Format-Dokument ist team-gescoped (fremdes Format → 404)', function () {
    $fremd = ($this->baueFormat)($this->childB);
    $this->actingAs($this->makeUser($this->childA, 'Kind A'));

    $this->get(route('foodalchemist.formate.dokument', ['id' => $fremd->id]))->assertNotFound();
});
