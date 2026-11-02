<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Handy\GpAnsicht;
use Platform\FoodAlchemist\Livewire\Recipes\DetailPanel as RezeptDetail;
use Platform\FoodAlchemist\Livewire\Verkauf\DetailPanel as GerichtDetail;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1003 Grundprodukt am Handy (Handy\GpAnsicht): nur lesen, Sicht visibleToTeam; das Rezept- und das
 * Gericht-Handbuch verlinken jede GP-Zutat dorthin.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->gp = $this->makeGp($this->rootTeam, 'Butter: Süßrahm HANDYGP');
    $this->metro = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Metro HANDYGP']);
    $this->butter = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->metro->id,
        'designation' => 'Butter 1 kg HANDYGP', 'qty' => 1.0, 'unit_code' => 'kg']);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->butter->id, 'price' => 5.00, 'status' => '0']);
    FoodAlchemistItemAllergen::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->butter->id, 'allergen_milk' => 'enthalten', 'allergen_eggs' => 'spuren']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->butter->id, 'gp_id' => $this->gp->id]);
});

it('Handy-GP-Ansicht: Route erreichbar; Name, Warengruppe, Lead-Artikel mit Lieferant und Preis je Einheit, Allergene, Anker, Geschmack; kein Desktop-Link', function () {
    DB::table('foodalchemist_vocab_pairing_anchors')->insert(['uuid' => (string) UuidV7::generate(), 'slug' => 'butter_hgp', 'display_de' => 'Butter-Anker HGP', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('foodalchemist_gp_anchor_mappings')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id, 'gp_id' => $this->gp->id,
        'anchor_id' => (int) DB::getPdo()->lastInsertId(), 'role' => 'kern', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('foodalchemist_gp_taste_vectors')->insert(['gp_id' => $this->gp->id, 'suess' => 0.2, 'fettig' => 0.9, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);

    expect(route('foodalchemist.gps.handy', ['gp' => $this->gp->id]))->toEndWith('/gps/' . $this->gp->id . '/handy');

    Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(GpAnsicht::class, ['gp' => $this->gp->id])
        ->assertSeeHtml('data-fa-handy-gp="' . $this->gp->id . '"')
        ->assertSee('Butter: Süßrahm HANDYGP')
        ->assertSeeHtml('data-fa-handy-gp-lead')->assertSee('Butter 1 kg HANDYGP')->assertSee('Metro HANDYGP')
        ->assertSeeHtml('data-fa-handy-gp-preis>5,00 €/kg')
        ->assertSeeHtml('data-fa-handy-gp-artikel="1"')
        ->assertSee('Enthält:')->assertSee('Milch')->assertSee('Spuren:')->assertSee('Eier')
        ->assertSee('Butter-Anker HGP')
        ->assertSeeHtml('data-fa-handy-gp-achse="fettig"')->assertSeeHtml('width: 90%')
        ->assertDontSee('Am Computer öffnen')   // Dominique 02.11.: kein Desktop-Link
        ->assertDontSee('Preis fehlt');

    // Route zeigt auf die Komponente (ein echter Seitenaufruf scheitert im Test-Harness am Core-Layout: oauth_access_tokens fehlt)
    $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create(route('foodalchemist.gps.handy', ['gp' => $this->gp->id])));
    expect($route->getName())->toBe('foodalchemist.gps.handy')
        ->and($route->getActionName())->toContain(GpAnsicht::class)
        ->and($route->parameter('gp'))->toBe((string) $this->gp->id);
});

it('GP eines fremden Teams ist am Handy nicht sichtbar: „nicht verfügbar“, keine Daten', function () {
    $fremd = $this->makeGp($this->childA, 'Geheim-GP NUR-A');
    $artikel = FoodAlchemistSupplierItem::create(['team_id' => $this->childA->id, 'supplier_id' => $this->metro->id, 'designation' => 'Geheimartikel NUR-A', 'qty' => 1.0, 'unit_code' => 'kg']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->childA->id, 'supplier_item_id' => $artikel->id, 'gp_id' => $fremd->id]);

    $this->actingAs($this->makeUser($this->childB, 'Team B'));
    Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(GpAnsicht::class, ['gp' => $fremd->id])
        ->assertSeeHtml('data-fa-handy-gp-fehlt')->assertSee('nicht verfügbar')
        ->assertDontSee('Geheim-GP NUR-A')->assertDontSee('Geheimartikel NUR-A')
        ->assertDontSeeHtml('data-fa-handy-gp-lead');

    // Gegenprobe: Team A sieht sein GP
    $this->actingAs($this->makeUser($this->childA, 'Team A'));
    Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(GpAnsicht::class, ['gp' => $fremd->id])
        ->assertSee('Geheim-GP NUR-A')->assertDontSeeHtml('data-fa-handy-gp-fehlt');
});

it('GP ohne Preis zeigt „Preis fehlt“', function () {
    $ohne = $this->makeGp($this->rootTeam, 'Kerbel: frisch HANDYGP');
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->metro->id, 'designation' => 'Kerbel Bund HANDYGP', 'qty' => 1.0, 'unit_code' => 'kg']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $ohne->id]);

    Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(GpAnsicht::class, ['gp' => $ohne->id])
        ->assertSee('Kerbel Bund HANDYGP')
        ->assertSeeHtml('data-fa-handy-gp-preis-fehlt')->assertSee('Preis fehlt');

    // ganz ohne Artikel ebenso
    $leer = $this->makeGp($this->rootTeam, 'Dill: frisch HANDYGP');
    Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(GpAnsicht::class, ['gp' => $leer->id])
        ->assertSeeHtml('data-fa-handy-gp-preis-fehlt')->assertSee('Preis fehlt');
});

it('Rezept-Handbuch am Handy: GP-Zutat verlinkt auf die Handy-GP-Ansicht, Unterrezept bleibt wie bisher', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Beurre blanc HANDYGP');
    $sub = $this->makeRecipe($this->rootTeam, 'Fond HANDYGP');
    $this->makeIngredient($rezept, 'Butter', $this->gp, '200');
    $this->makeIngredient($rezept, 'Fond', null, '100', 2)->update(['referenced_recipe_id' => $sub->id]);

    Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(RezeptDetail::class, ['recipeId' => $rezept->id])
        ->assertSeeHtml('data-fa-handbuch="rezept"')
        ->assertSeeHtml('href="' . route('foodalchemist.gps.handy', ['gp' => $this->gp->id]) . '"')
        ->assertSeeHtml('data-fa-handbuch-gp="' . $this->gp->id . '"')
        ->assertSeeHtml('wire:click.prevent="zeige(' . $sub->id . ')"');
});

it('Gericht-Handbuch am Handy: GP-Zutat verlinkt ebenso auf die Handy-GP-Ansicht', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Seezunge HANDYGP', ['is_sales_recipe' => true]);
    $this->makeStandardDarreichung($gericht, 29.5, ['quantity_per_unit_g' => 350]);
    $this->makeIngredient($gericht, 'Butter', $this->gp, '30');

    Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(GerichtDetail::class, ['recipeId' => $gericht->id])
        ->assertSeeHtml('data-fa-handbuch="gericht"')
        ->assertSeeHtml('href="' . route('foodalchemist.gps.handy', ['gp' => $this->gp->id]) . '"')
        ->assertSeeHtml('data-fa-handbuch-gp="' . $this->gp->id . '"');
});

it('Artikel-Karte am Handy verlinkt ihr Grundprodukt auf die Handy-GP-Ansicht', function () {
    Livewire::withCookie(HandyLesemodus::COOKIE, '1')->withQueryParams(['artikel' => $this->butter->id])
        ->test(\Platform\FoodAlchemist\Livewire\Suppliers\Index::class)
        ->assertSeeHtml('href="' . route('foodalchemist.gps.handy', ['gp' => $this->gp->id]) . '"');
});
