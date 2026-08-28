<?php

use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 — Interne LIVE-Vorschau (/foodbooks/{id}/praesentation): auth + team-gescopt,
 * rendert dieselben Templates aus AKTUELLEN Daten (Gegenprobe zur Snapshot-Stabilität
 * des Public-Links), inkl. ?design=-Override.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->baue = function ($team) {
        $fb = $this->makeFoodbook($team, 'Sommerkatalog', ['personen' => 10, 'jahr' => 2027]);
        $kap = $this->makeChapter($fb, ['title' => 'INTERN Vorspeisen', 'consumer_title' => 'Vorspeisen', 'position' => 1]);
        $suppe = $this->makeRecipe($team, 'Kürbissuppe', ['is_sales_recipe' => true, 'sales_net' => 6.5]);
        $this->makeFoodbookBlock($kap, ['type' => 'recipe_ref', 'sales_recipe_id' => $suppe->id, 'position' => 1]);

        return $fb;
    };
});

it('interne Vorschau rendert live (auth) und spiegelt Editor-Änderungen sofort', function () {
    $fb = ($this->baue)($this->rootTeam);
    $this->actingAs($this->makeUser($this->rootTeam, 'Preview User'));

    $this->get(route('foodalchemist.foodbooks.praesentation', ['id' => $fb->id]))
        ->assertOk()
        ->assertSee('Vorspeisen')
        ->assertSee('Kürbissuppe')
        ->assertDontSee('INTERN Vorspeisen');

    // LIVE: eine Editor-Änderung ist SOFORT in der Vorschau sichtbar (kein Snapshot).
    $fb->chapters()->first()->update(['consumer_title' => 'Live Geändert']);
    $this->get(route('foodalchemist.foodbooks.praesentation', ['id' => $fb->id]))
        ->assertOk()->assertSee('Live Geändert');
});

it('interne Vorschau ist team-gescopt (fremdes Foodbook → 404)', function () {
    $fremd = ($this->baue)($this->childB);
    $this->actingAs($this->makeUser($this->childA, 'Kind A'));

    $this->get(route('foodalchemist.foodbooks.praesentation', ['id' => $fremd->id]))->assertNotFound();
});

it('?design-Override rendert ein anderes Design ohne es zu speichern', function () {
    $fb = ($this->baue)($this->rootTeam);
    $this->actingAs($this->makeUser($this->rootTeam, 'Preview User'));

    $this->get(route('foodalchemist.foodbooks.praesentation', ['id' => $fb->id, 'design' => 'kiosk']))
        ->assertOk()->assertSee('Vorspeisen');

    // Nichts persistiert — presentation_design bleibt Default.
    expect($fb->refresh()->presentation_design)->toBe('editorial');
});
