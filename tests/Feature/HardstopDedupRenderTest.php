<?php

use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 41 FIX-4-(A) + FIX-5: die hardstop-zeilen-Blade rendert den Dedup-Kollisions-Flag als
 * eigene Info-Karte (ohne irreführende Ingredient-Buttons) und die Garnitur-Namens-Warnung.
 */
beforeEach(fn () => $this->seedTeamHierarchy());

it('rendert die Dedup-Kollision als Info-Karte ohne Hard-Stop-Buttons', function () {
    $offene = [[
        'index' => -1,
        'text' => 'Kürbispüree',
        'primaer' => 'dedup_kollision',
        'shortlist' => [],
        'la_kandidaten' => [],
        'lieferantenstrategie' => null,
        'schwacher_treffer' => null,
        'dedup_kollision' => [
            'existing_id' => 4242,
            'existing_name' => 'Kürbispüree',
            'modus' => 'komplett_neu',
            'hinweis' => 'existiert bereits als «Kürbispüree» (#4242) — als Variante behalten oder übernehmen?',
        ],
    ]];

    $view = $this->blade('<x-foodalchemist::hardstop-zeilen :offene="$offene" prefix="" />', ['offene' => $offene]);

    $view->assertSee('existiert bereits als', false)
        ->assertSee('Kürbispüree', false)
        ->assertSee('data-dedup="4242"', false)
        // KEINE Ingredient-Hard-Stop-Aktion für eine Rezept-Kollision
        ->assertDontSee('Beschaffung anstoßen', false)
        ->assertDontSee('Basisrezept anlegen', false);
});

it('rendert die Garnitur-Namens-Warnung additiv auf einer offenen Zeile', function () {
    $offene = [[
        'index' => 3,
        'text' => 'Adji Kresse',
        'primaer' => 'lieferantenartikel_waehlen',
        'shortlist' => [],
        'la_kandidaten' => [],
        'lieferantenstrategie' => null,
        'schwacher_treffer' => null,
        'namens_warnung' => true,
    ]];

    $view = $this->blade('<x-foodalchemist::hardstop-zeilen :offene="$offene" prefix="" />', ['offene' => $offene]);

    $view->assertSee('Name nicht im GP-Katalog', false)
        ->assertSee('data-namenswarnung="3"', false);
});
