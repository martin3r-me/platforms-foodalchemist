<?php

use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\ReportExportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #5a: Technischer Foodbook-Report — die ZWEITE Foodbook-Ausgabe neben dem schönen `foodbooks.dokument`.
 * Deckt ReportExportService::foodbookDaten (Kapitel × Positionen über die IDENTISCHE Concept-/Rezept-
 * Report-Auflösung + Filter) + Route/Blade `dokumente.report`-Foodbook-Zweig ab. Die Produktions-
 * Kaskade lebt HIER (aus dem Dokument gezogen). Spiegelt FormatReportTest.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->fb = app(FoodbookService::class);
    $this->report = app(ReportExportService::class);

    $this->baueFoodbook = function ($team) {
        $dish = $this->makeRecipe($team, 'HG Rinderfilet', [
            'is_sales_recipe' => true, 'sales_net' => 30.00, 'notes_manual' => 'Sonderhinweis Mise en Place',
        ]);
        $concept = $this->makeConcept($team, 'Sommer-Menü', [
            'kind' => 'concept', 'consumer_name' => 'Sommergenuss', 'price_per_person_cache' => 42.50,
        ]);
        $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id, 'wording' => 'Rinderfilet Rossini']);
        $einzel = $this->makeRecipe($team, 'Kürbissuppe', ['is_sales_recipe' => true, 'sales_net' => 6.50]);

        $fb = $this->fb->create($team, ['label' => 'Angebot Adler']);
        $kap = $this->fb->addKapitel($team, $fb->id, ['title' => 'Vorspeisen']);
        $this->fb->addBlock($team, $kap->id, ['type' => 'concept_ref', 'concept_id' => $concept->id]);
        $this->fb->addBlock($team, $kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $einzel->id]);

        return $fb;
    };
});

it('foodbookDaten liefert Kapitel × Positionen über den Concept-/Rezept-Report', function () {
    $fb = ($this->baueFoodbook)($this->rootTeam);
    $optionen = $this->report->optionen(['profil' => 'voll'], 'foodbook');
    $data = $this->report->foodbookDaten($this->rootTeam, $fb->id, $optionen);

    expect($data['typ'])->toBe('foodbook')
        ->and($data['recipe'])->toBeNull()
        ->and($data['concept'])->toBeNull()
        ->and($data['format'])->toBeNull()
        ->and($data['foodbook']['name'])->toBe('Angebot Adler');

    $kap = $data['foodbook']['kapitel'][0];
    expect($kap['title'])->toBe('Vorspeisen')
        ->and(collect($kap['positionen'])->pluck('kind')->all())->toBe(['concept', 'recipe']);

    // Concept-Position trägt die volle Concept-Report-Auflösung inkl. aufgelöstem Gericht-Node.
    $conceptPos = collect($kap['positionen'])->firstWhere('kind', 'concept');
    expect($conceptPos['concept']['name'])->toBe('Sommer-Menü')
        ->and($conceptPos['concept']['slots'][0]['gerichte'][0]['recipe']['name'])->toBe('HG Rinderfilet');
});

it('der Foodbook-Report (Route + Blade) rendert Profil-Leiste, Filter + Inhalt', function () {
    $fb = ($this->baueFoodbook)($this->rootTeam);
    $this->actingAs($this->makeUser($this->rootTeam, 'Report User'));

    $this->get(route('foodalchemist.foodbooks.report', ['id' => $fb->id, 'profil' => 'voll']))
        ->assertOk()
        ->assertSee('Report-Profile')
        ->assertSee('Volle Kaskade')
        ->assertSee('Kaskade')
        ->assertSee('Foodbook-Übersicht')
        ->assertSee('Vorspeisen')            // Kapitel
        ->assertSee('Sommer-Menü')           // Concept-Position
        ->assertSee('HG Rinderfilet')        // aufgelöster Gericht-Node (Kaskade)
        ->assertSee('Kürbissuppe');          // recipe_ref-Position
});

it('der Notizen-Filter greift auch im Foodbook-Report', function () {
    $fb = ($this->baueFoodbook)($this->rootTeam);
    $this->actingAs($this->makeUser($this->rootTeam, 'Notiz User'));

    $this->get(route('foodalchemist.foodbooks.report', ['id' => $fb->id, 'profil' => 'voll', 'notizen' => 1]))
        ->assertOk()->assertSee('Sonderhinweis Mise en Place');
    $this->get(route('foodalchemist.foodbooks.report', ['id' => $fb->id, 'profil' => 'voll', 'notizen' => 0]))
        ->assertOk()->assertDontSee('Sonderhinweis Mise en Place');
});

it('der Foodbook-Report ist team-gescoped (fremdes Foodbook → 404)', function () {
    $fremd = ($this->baueFoodbook)($this->childB);
    $this->actingAs($this->makeUser($this->childA, 'Kind A'));

    $this->get(route('foodalchemist.foodbooks.report', ['id' => $fremd->id]))->assertNotFound();
});

it('#5a: das Dokument zeigt die Allergen-Legende nur mit ?deklaration (Toggle)', function () {
    $team = $this->rootTeam;
    $dish = $this->makeRecipe($team, 'Pasta', ['is_sales_recipe' => true, 'sales_net' => 9.0, 'allergen_gluten' => 'enthalten']);
    $fb = $this->fb->create($team, ['label' => 'K']);
    $kap = $this->fb->addKapitel($team, $fb->id, ['title' => 'Kap']);
    $this->fb->addBlock($team, $kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $dish->id]);
    $this->actingAs($this->makeUser($team, 'Doc User'));

    // Default (an): Legende da. ?deklaration=0: weg. Kaskaden-Knopf gibt es nicht mehr.
    $this->get(route('foodalchemist.foodbooks.dokument', ['id' => $fb->id]))
        ->assertOk()->assertSee('Allergene')->assertSee('ohne Allergene')->assertDontSee('Produktions-Kaskade');
    $this->get(route('foodalchemist.foodbooks.dokument', ['id' => $fb->id, 'deklaration' => 0]))
        ->assertOk()->assertSee('mit Allergenen')->assertDontSee('class="legende"');
});
