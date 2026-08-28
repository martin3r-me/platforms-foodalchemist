<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 — Form-Scoping der Designs (pickerOptions je Ausgabeform) +
 * Speisekarte-Rubriken ohne „Kapitel"-Kicker.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->designs = app(PresentationDesignService::class);
});

it('pickerOptions filtert Built-ins je Form (menu nur Speisekarte, kiosk nicht Foodbook)', function () {
    $fb = collect($this->designs->pickerOptions($this->rootTeam, 'foodbook'))->pluck('value');
    $sk = collect($this->designs->pickerOptions($this->rootTeam, 'speisekarte'))->pluck('value');
    $sp = collect($this->designs->pickerOptions($this->rootTeam, 'speiseplan'))->pluck('value');

    expect($fb)->toContain('editorial')->toContain('navigator')
        ->not->toContain('menu')->not->toContain('kiosk');
    expect($sk)->toContain('menu')->toContain('kiosk')->toContain('editorial');
    expect($sp)->toContain('kiosk')->toContain('navigator')->not->toContain('menu');
});

it('DB-Design mit output_types erscheint nur bei passender Form', function () {
    $nurFoodbook = $this->designs->create($this->rootTeam, ['name' => 'Nur FB', 'output_types' => ['foodbook']]);
    $ueberall = $this->designs->create($this->rootTeam, ['name' => 'Universal', 'output_types' => []]);

    $fb = collect($this->designs->pickerOptions($this->rootTeam, 'foodbook'))->pluck('value');
    $sk = collect($this->designs->pickerOptions($this->rootTeam, 'speisekarte'))->pluck('value');

    expect($fb)->toContain('design:' . $nurFoodbook->id)->toContain('design:' . $ueberall->id);
    expect($sk)->not->toContain('design:' . $nurFoodbook->id)->toContain('design:' . $ueberall->id);
});

it('Speisekarte-Präsentation zeigt Rubriken OHNE Kapitel-Kicker, Foodbook MIT', function () {
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($this->user, $this->rootTeam);
    $pres = app(PresentationService::class);

    // Speisekarte
    $gericht = $this->makeRecipe($this->rootTeam, 'HG Zander', ['is_sales_recipe' => true, 'sales_net' => 24.0]);
    $karteId = $registry->get('foodalchemist.speisekarten.POST')->execute(['name' => 'Abendkarte'], $kontext)->data['speisekarte']['id'];
    $rubrikId = $registry->get('foodalchemist.speisekarte_rubrik.POST')->execute([
        'speisekarte_id' => $karteId, 'title' => 'Fisch', 'consumer_title' => 'Aus dem Wasser', 'art' => 'speisen',
    ], $kontext)->data['rubrik']['id'];
    $registry->get('foodalchemist.speisekarte_positionen.POST')->execute([
        'rubrik_id' => $rubrikId, 'type' => 'gericht_ref', 'sales_recipe_id' => $gericht->id,
    ], $kontext);
    $karte = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::find($karteId);
    $skRes = $pres->publish($this->rootTeam, 'speisekarte', $karte->id, ['expires_at' => now()->addDays(30)->toDateString()]);

    $this->get('/p/speisekarte/' . $skRes['token'])
        ->assertOk()
        ->assertSee('Aus dem Wasser', false)                       // Rubrik-Titel bleibt
        ->assertDontSee('class="pt-kicker">Kapitel', false);       // aber kein „Kapitel"-Label

    // Foodbook: Kicker bleibt
    $c = $this->makeConcept($this->rootTeam, 'Menü', ['kind' => 'concept', 'consumer_name' => 'Gang']);
    $dish = $this->makeRecipe($this->rootTeam, 'Lachs', ['is_sales_recipe' => true, 'sales_net' => 12.0]);
    $this->makeConceptSlot($c, ['sales_recipe_id' => $dish->id, 'wording' => 'Lachs', 'position' => 1]);
    $foodbook = $this->makeFoodbook($this->rootTeam, 'Katalog', ['personen' => 4]);
    $kap = $this->makeChapter($foodbook, ['title' => 'Vorspeisen', 'consumer_title' => 'Vorspeisen', 'position' => 1]);
    $this->makeFoodbookBlock($kap, ['type' => 'concept_ref', 'concept_id' => $c->id, 'position' => 1]);
    $fbRes = $pres->publish($this->rootTeam, 'foodbook', $foodbook->id, ['expires_at' => now()->addDays(30)->toDateString()]);

    $this->get('/p/foodbook/' . $fbRes['token'])
        ->assertOk()
        ->assertSee('class="pt-kicker">Kapitel', false);
});
