<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GP-Formular, Bereich „Name“ (Dominique 26.10.): Hilfetexte stehen nicht mehr offen. ⓘ neben dem Label klappt auf und zu (button mit aria-expanded/
 * aria-controls), Standard zu, Text bleibt im DOM. „Zusatz (Fantasiename)“ heißt „Zusatz (Marke / Handelsname)“, dazu das Feld Rezepturware.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.bearbeitungssperre' => false]);
});

it('Hilfetexte im Bereich Name: ⓘ-Knopf mit aria, Standard zu, Text im DOM; neues Zusatz-Label und Feld Rezepturware', function () {
    $html = Livewire::test(GpModal::class)->call('oeffnen', null)->html();

    foreach (['gp-flavor', 'gp-zusatz', 'gp-rezeptur', 'gp-name'] as $feld) {
        expect($html)->toContain('data-fa-field-info="' . $feld . '"')->toContain('aria-controls="hinweis-' . $feld . '"')
            ->toContain('id="hinweis-' . $feld . '" x-show="hinweisOffen"');
    }
    expect(substr_count($html, 'x-data="{ hinweisOffen: false }"'))->toBeGreaterThanOrEqual(4)   // Standard zu
        ->and($html)->not->toContain('hinweisOffen: true')
        ->toContain('aria-expanded="false"')
        ->toContain('Zusatz (Marke / Handelsname)')->not->toContain('Zusatz (Fantasiename)')
        ->toContain('z. B. Block House, Heisters, Mambonito')
        ->toContain('Händlernamen nie')
        ->toContain('data-gp-rezepturware');
});

it('Rezepturware ja ohne Klammer zeigt live „Marke offen“; mit Klammer nicht', function () {
    $c = Livewire::test(GpModal::class)->call('oeffnen', null)->set('builder.rezepturware', '1')->set('builder.zusatz', '');
    expect($c->html())->toContain('data-gp-marke-offen');

    $c->set('builder.zusatz', 'Block House');
    expect($c->html())->not->toContain('data-gp-marke-offen');
});

it('Formulare ohne `einklappen` bleiben wie bisher (Hilfetext offen, kein ⓘ)', function () {
    $html = \Illuminate\Support\Facades\Blade::render('<x-fa::field label="Test" for="x-test" hint="Hilfe sichtbar"><input id="x-test"></x-fa::field>');

    expect($html)->toContain('Hilfe sichtbar')->not->toContain('data-fa-field-info')->not->toContain('hinweisOffen');
});
