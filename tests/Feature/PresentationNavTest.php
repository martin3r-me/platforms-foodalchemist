<?php

use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 (Punkt 3) — Navigation (Anker-Sprungmenü / Sidebar) + Lightbox in der Präsentation.
 * Nav lohnt erst ab 2 Kapiteln; Design-Token `nav` steuert die Variante.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->pres = app(PresentationService::class);
    $this->actingAs($this->makeUser($this->rootTeam, 'Nav'));

    $this->baueMitKapiteln = function ($team, int $n) {
        $fb = $this->makeFoodbook($team, 'Nav-Katalog', ['personen' => 4]);
        for ($i = 1; $i <= $n; $i++) {
            $c = $this->makeConcept($team, "Menü {$i}", ['kind' => 'concept', 'consumer_name' => "Gang {$i}"]);
            $dish = $this->makeRecipe($team, "Gericht {$i}", ['is_sales_recipe' => true, 'sales_net' => 10.0]);
            $this->makeConceptSlot($c, ['sales_recipe_id' => $dish->id, 'wording' => "Gericht {$i}", 'position' => 1]);
            $kap = $this->makeChapter($fb, ['title' => "Kapitel {$i}", 'consumer_title' => "Kapitel {$i}", 'position' => $i]);
            $this->makeFoodbookBlock($kap, ['type' => 'concept_ref', 'concept_id' => $c->id, 'position' => 1]);
        }

        return $fb;
    };
});

it('editorial (default) rendert Anker-Sprungmenü + Lightbox ab 2 Kapiteln', function () {
    $fb = ($this->baueMitKapiteln)($this->rootTeam, 2);
    $res = $this->pres->publish($this->rootTeam, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);

    $this->get('/p/foodbook/' . $res['token'])
        ->assertOk()
        ->assertSee('pt-nav-anchor', false)
        ->assertSee('data-pt-toc', false)
        ->assertSee('data-pt-lightbox', false)
        ->assertSee('data-pt-navlink="', false);
});

it('navigator-Design rendert die feste Sidebar', function () {
    $fb = ($this->baueMitKapiteln)($this->rootTeam, 2);
    $res = $this->pres->publish($this->rootTeam, 'foodbook', $fb->id, [
        'design' => 'navigator',
        'expires_at' => now()->addDays(30)->toDateString(),
    ]);

    $this->get('/p/foodbook/' . $res['token'])
        ->assertOk()
        ->assertSee('pt-nav-sidebar', false)
        ->assertSee('class="pt-sidebar"', false)
        ->assertSee('data-pt-drawer', false);
});

it('unter 2 Kapiteln bleibt die Navigation aus (pt-nav-none)', function () {
    $fb = ($this->baueMitKapiteln)($this->rootTeam, 1);
    $res = $this->pres->publish($this->rootTeam, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);

    $this->get('/p/foodbook/' . $res['token'])
        ->assertOk()
        ->assertSee('pt-nav-none', false)
        ->assertDontSee('<div class="pt-toc"', false)   // Anker-Markup fehlt (JS referenziert den Selektor immer)
        ->assertDontSee('class="pt-sidebar"', false);
});
