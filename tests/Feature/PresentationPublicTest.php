<?php

use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 — Öffentliche Präsentations-Route (/p/foodbook/{token}): ohne Login erreichbar,
 * rendert NUR aus dem Snapshot, 404-Matrix, Snapshot-Stabilität, Interna-Freiheit.
 * Bewusst OHNE actingAs — echter Gast-Zugriff.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PresentationService::class);

    $this->baue = function ($team) {
        $fb = $this->makeFoodbook($team, 'Sommerkatalog', [
            'personen' => 10, 'jahr' => 2027, 'description' => 'Feine Auswahl', 'brand_color' => '#123456',
        ]);
        $kap = $this->makeChapter($fb, ['title' => 'INTERN Vorspeisen', 'consumer_title' => 'Vorspeisen', 'position' => 1]);
        $dish = $this->makeRecipe($team, 'HG Lachsfilet', ['is_sales_recipe' => true, 'sales_net' => 12.0]);
        $concept = $this->makeConcept($team, 'Menü A', ['kind' => 'concept', 'consumer_name' => 'Genussreise', 'price_per_person_cache' => 40.0]);
        $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id, 'wording' => 'Lachs auf Spinat', 'position' => 1]);
        $this->makeFoodbookBlock($kap, ['type' => 'concept_ref', 'concept_id' => $concept->id, 'position' => 1]);
        $suppe = $this->makeRecipe($team, 'Kürbissuppe', ['is_sales_recipe' => true, 'sales_net' => 6.5]);
        $this->makeFoodbookBlock($kap, ['type' => 'recipe_ref', 'sales_recipe_id' => $suppe->id, 'position' => 2]);

        return $fb;
    };

    $this->publish = fn ($fb, array $over = []) => $this->svc->publish($this->rootTeam, 'foodbook', $fb->id, array_merge([
        'expires_at' => now()->addDays(30)->toDateString(),
    ], $over));
});

it('öffentlicher Link ist ohne Login erreichbar und rendert die Kundensicht', function () {
    $fb = ($this->baue)($this->rootTeam);
    $res = ($this->publish)($fb);

    $this->get('/p/foodbook/' . $res['token'])
        ->assertOk()
        ->assertSee('Sommerkatalog')
        ->assertSee('Vorspeisen')
        ->assertSee('Lachs auf Spinat')   // aufgelöste Kunden-Wording
        ->assertSee('Kürbissuppe')
        // Interna-Freiheit:
        ->assertDontSee('INTERN Vorspeisen')  // interner Kapitel-Titel
        ->assertDontSee('HG Lachsfilet')      // interner Rezept-Name (durch Wording ersetzt)
        ->assertDontSee('Wareneinsatz');
});

it('unbekannter Token → 404', function () {
    $this->get('/p/foodbook/deadbeefdeadbeef')->assertNotFound();
});

it('zurückgezogene Präsentation → 404', function () {
    $fb = ($this->baue)($this->rootTeam);
    $res = ($this->publish)($fb);
    $this->svc->withdraw($this->rootTeam, 'foodbook', $fb->id);

    $this->get('/p/foodbook/' . $res['token'])->assertNotFound();
});

it('abgelaufener Link → 404 (Pflicht-Datum serverseitig geprüft)', function () {
    $fb = ($this->baue)($this->rootTeam);
    $res = ($this->publish)($fb);
    $fb->refresh()->forceFill(['presentation_expires_at' => now()->subDay()])->save();

    $this->get('/p/foodbook/' . $res['token'])->assertNotFound();
});

it('Snapshot bleibt stabil, wenn nach der Freigabe editiert wird', function () {
    $fb = ($this->baue)($this->rootTeam);
    $res = ($this->publish)($fb);

    $fb->chapters()->first()->update(['consumer_title' => 'GEÄNDERT NACH FREIGABE']);

    $this->get('/p/foodbook/' . $res['token'])
        ->assertOk()
        ->assertSee('Vorspeisen')
        ->assertDontSee('GEÄNDERT NACH FREIGABE');
});
