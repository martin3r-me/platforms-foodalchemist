<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 — Editor-Publish-Tab „Branding & Präsentation" am Foodbook-Index: Veröffentlichen
 * (Pflicht-Datum), Zurückziehen — Wiring auf den PresentationService.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->baue = function ($team) {
        $fb = $this->makeFoodbook($team, 'Katalog', ['personen' => 6]);
        $kap = $this->makeChapter($fb, ['title' => 'Vorspeisen', 'consumer_title' => 'Vorspeisen', 'position' => 1]);
        $dish = $this->makeRecipe($team, 'Suppe', ['is_sales_recipe' => true, 'sales_net' => 5.0]);
        $this->makeFoodbookBlock($kap, ['type' => 'recipe_ref', 'sales_recipe_id' => $dish->id, 'position' => 1]);

        return $fb;
    };
});

it('Veröffentlichen aus dem Editor-Tab friert ein + aktiviert den Link', function () {
    $fb = ($this->baue)($this->rootTeam);
    $this->actingAs($this->makeUser($this->rootTeam, 'Editor'));

    Livewire::test(Index::class)
        ->call('waehle', $fb->id)
        ->set('presentationDesign', 'menu')
        ->set('presentationGueltigBis', now()->addDays(20)->toDateString())
        ->call('veroeffentlichen')
        ->assertSet('presentationFehler', null);

    $fb->refresh();
    expect($fb->presentation_enabled)->toBeTrue()
        ->and($fb->presentation_token)->not->toBeNull()
        ->and($fb->presentation_design)->toBe('menu')
        ->and($fb->presentation_expires_at)->not->toBeNull();
});

it('ohne gültig-bis wird nicht veröffentlicht (Pflicht-Datum)', function () {
    $fb = ($this->baue)($this->rootTeam);
    $this->actingAs($this->makeUser($this->rootTeam, 'Editor'));

    $c = Livewire::test(Index::class)
        ->call('waehle', $fb->id)
        ->set('presentationGueltigBis', null)
        ->call('veroeffentlichen');

    expect($c->get('presentationFehler'))->not->toBeNull();
    expect($fb->refresh()->presentation_enabled)->toBeFalse();
});

it('Zurückziehen deaktiviert den Link', function () {
    $fb = ($this->baue)($this->rootTeam);
    $this->actingAs($this->makeUser($this->rootTeam, 'Editor'));

    Livewire::test(Index::class)
        ->call('waehle', $fb->id)
        ->set('presentationGueltigBis', now()->addDays(20)->toDateString())
        ->call('veroeffentlichen')
        ->call('zuruckziehen');

    expect($fb->refresh()->presentation_enabled)->toBeFalse();
});
