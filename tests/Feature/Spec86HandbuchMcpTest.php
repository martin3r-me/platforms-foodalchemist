<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 86 Teil C — MCP-Tools des Handbuchs (PUBLISH | GET | WITHDRAW je Ausgabe-Dokument):
 * echter Round-Trip über die Registry (Rechte-Hülle inklusive), nicht nur Klassen-Existenz.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);

    $team = $this->rootTeam;
    $concept = $this->makeConcept($team, 'Menü A', ['kind' => 'concept', 'consumer_name' => 'Genuss']);
    $dish = $this->makeRecipe($team, 'Zanderfilet', ['is_sales_recipe' => true]);
    $this->makeStandardDarreichung($dish, 12.34);
    $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id, 'wording' => 'Zanderfilet auf Linsen', 'position' => 1]);
    FoodAlchemistRecipeStep::create(['team_id' => $team->id, 'recipe_id' => $dish->id, 'position' => 1, 'ebene' => 'produktion', 'text' => 'Kross braten.']);
    $this->fb = $this->makeFoodbook($team, 'Sommerfest', ['personen' => 6]);
    $kap = $this->makeChapter($this->fb, ['title' => 'Hauptgänge', 'consumer_title' => 'Hauptgänge', 'position' => 1]);
    $this->makeFoodbookBlock($kap, ['type' => 'concept_ref', 'concept_id' => $concept->id, 'position' => 1]);
});

it('Foodbook-Handbuch: PUBLISH → GET → WITHDRAW über die Registry', function () {
    $pub = $this->registry->get('foodalchemist.foodbook_handbuch.PUBLISH')
        ->execute(['foodbook_id' => $this->fb->id, 'expires_at' => now()->addDays(20)->toDateString()], $this->kontext);
    expect($pub->success)->toBeTrue();
    $token = $pub->data['token'];
    $this->get('/p/handbuch/' . $token)->assertOk()->assertSee('Kross braten.');

    $get = $this->registry->get('foodalchemist.foodbook_handbuch.GET')->execute(['foodbook_id' => $this->fb->id, 'luecken' => true], $this->kontext);
    expect($get->data['live'])->toBeTrue()->and($get->data['url'])->toContain($token)->and($get->data)->toHaveKey('luecken');

    $weg = $this->registry->get('foodalchemist.foodbook_handbuch.WITHDRAW')->execute(['foodbook_id' => $this->fb->id], $this->kontext);
    expect($weg->success)->toBeTrue();
    $this->get('/p/handbuch/' . $token)->assertNotFound();
});

it('PUBLISH ohne expires_at und mit unbekannter ID liefern Fehler statt Link', function () {
    $ohne = $this->registry->get('foodalchemist.foodbook_handbuch.PUBLISH')->execute(['foodbook_id' => $this->fb->id], $this->kontext);
    expect($ohne->success)->toBeFalse();

    $fremd = $this->registry->get('foodalchemist.foodbook_handbuch.PUBLISH')
        ->execute(['foodbook_id' => 999999, 'expires_at' => now()->addDays(5)->toDateString()], $this->kontext);
    expect($fremd->success)->toBeFalse();
});

it('alle zwölf Handbuch-Tools sind registriert (vier Dokumenttypen × drei Verben)', function () {
    foreach (['foodbook', 'speisekarte', 'speiseplan', 'angebot'] as $typ) {
        foreach (['PUBLISH', 'GET', 'WITHDRAW'] as $verb) {
            expect($this->registry->get("foodalchemist.{$typ}_handbuch.{$verb}"))->not->toBeNull();
        }
    }
});
