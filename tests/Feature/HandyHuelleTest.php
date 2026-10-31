<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Handy\Suche;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Support\HandyNavigation;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1003 Handy-Ansicht · App-Hülle (PR1b): untere Leiste, „Mehr“, globale Suche, App-Manifest.
 * Das Layout prüft der Browser (Screenshots im PR); hier die Verträge.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
});

it('Untere Leiste: Reiter und Mehr folgen der Desktop-Navigation, Dashboard steht oben in Mehr', function () {
    $nav = HandyNavigation::fuer($this->user);

    expect(collect($nav['reiter'])->pluck('route')->all())->toBe(['foodalchemist.recipes.index', 'foodalchemist.planung.index', 'foodalchemist.review'])
        ->and($nav['mehr'][0]['route'])->toBe('foodalchemist.dashboard')
        ->and(collect($nav['mehr'])->pluck('route'))->toContain('foodalchemist.verkauf.index', 'foodalchemist.gps.index')
        // Hotfix 31.10.: Trend und Wissen haben Handy-Ansichten (HandyTrendsLesen/HandyWissenLesen), also auch einen Einstieg
        ->and(collect($nav['mehr'])->pluck('route'))->toContain('foodalchemist.trendradar.index', 'foodalchemist.knowledge.index');
});

it('Untere Leiste: abgeschalteter Bereich erscheint auch am Handy nicht', function () {
    $admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($admin->email)]]);
    app(FaRechte::class)->setzeTeamBereich($this->rootTeam, $admin, 'einkauf', false);

    $nav = HandyNavigation::fuer($this->user->fresh());

    expect(collect($nav['mehr'])->pluck('route'))->not->toContain('foodalchemist.orders.index')
        ->and(collect($nav['mehr'])->pluck('route'))->toContain('foodalchemist.gps.index');
});

it('Untere Leiste rendert Suche, Reiter, Mehr und den Konto-Platz; am Computer per CSS aus', function () {
    $html = Blade::render('<x-foodalchemist::handy.nav><x-slot:konto><span>KONTO-MARKER</span></x-slot:konto></x-foodalchemist::handy.nav>');

    expect($html)->toContain('data-fa-handy-nav')
        ->toContain(route('foodalchemist.suche'))
        ->toContain('data-fa-handy-mehr')
        ->toContain('KONTO-MARKER')
        ->not->toContain('Desktop-Ansicht');   // Hotfix 31.10.: aus dem Schalter kam man am Handy nicht zurück
});

it('Kopf-Baustein: Manifest, iOS-Vollbild und Handy-Entscheidung vor dem ersten Zeichnen', function () {
    $html = Blade::render('<x-foodalchemist::handy.kopf />');

    expect($html)->toContain(route('foodalchemist.handy_app.manifest'))
        ->toContain('apple-mobile-web-app-capable')
        ->toContain('apple-touch-icon')
        ->toContain('window.faHandyModus');
});

it('App-Manifest: öffentlich, Vollbild, Startseite Dashboard, Icons inkl. maskable; fremde Icon-Namen 404', function () {
    auth()->logout();

    $m = $this->get(route('foodalchemist.handy_app.manifest'))->assertOk()->json();

    expect($m['display'])->toBe('standalone')
        ->and($m['start_url'])->toStartWith($m['scope'])
        ->and(collect($m['icons'])->pluck('purpose'))->toContain('maskable');

    $this->get(route('foodalchemist.handy_app.icon', ['name' => 'icon-192']))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get('/_platform/fa-app/geheim.png')->assertNotFound();
});

it('Suche: findet Basisrezept und Gericht über die vorhandenen Dienste und verlinkt die Detailseite', function () {
    $basis = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'minestrone_handysuche', 'name' => 'Minestrone HANDYSUCHE', 'status' => 'approved']);

    Livewire::test(Suche::class)
        ->set('q', 'handysuche')
        ->assertSee('Minestrone HANDYSUCHE')
        ->assertSee(route('foodalchemist.recipes.index', ['rezept' => $basis->id]), false);
});

it('Suche: unter zwei Zeichen keine Treffer-Abfrage, sondern Hinweis', function () {
    Livewire::test(Suche::class)
        ->set('q', 'm')
        ->assertSee('Ab zwei Buchstaben suchen');
});

it('Suche: fremdes Team bleibt unsichtbar', function () {
    FoodAlchemistRecipe::create(['team_id' => $this->childB->id, 'recipe_key' => 'fremd_handygeheim', 'name' => 'Fremdrezept HANDYGEHEIM', 'status' => 'approved']);
    $this->user->current_team_id = $this->childA->id;
    $this->user->save();

    Livewire::test(Suche::class)
        ->set('q', 'handygeheim')
        ->assertDontSee('Fremdrezept HANDYGEHEIM');
});

it('Artikel per EAN: exakt über Verpackungs- oder Bestell-EAN, nur Ziffern', function () {
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Handy-Lieferant']);
    $artikel = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lieferant->id, 'designation' => 'Pfeffer EAN', 'qty' => 1.0, 'unit_code' => 'kg', 'ean_ordering' => '4012345678901']);
    $kaputt = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lieferant->id, 'designation' => 'Altdaten', 'qty' => 1.0, 'unit_code' => 'kg', 'ean_packaging' => 'X-12']);
    $svc = app(SupplierItemService::class);

    expect($svc->byEan($this->rootTeam, '4012345678901')->pluck('id')->all())->toBe([$artikel->id])
        ->and($svc->byEan($this->rootTeam, '4012 3456 78901')->pluck('id')->all())->toBe([$artikel->id])
        ->and($svc->byEan($this->rootTeam, '40123456'))->toHaveCount(0)
        ->and($svc->byEan($this->rootTeam, 'X-12'))->toHaveCount(0);
});

it('Artikel-Suche ohne Gesamtzahl liefert dieselben ersten Treffer wie searchGlobal', function () {
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Handy-Lieferant']);
    foreach (['Minestrone A', 'Minestrone B', 'Tomate'] as $name) {
        FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lieferant->id, 'designation' => $name, 'qty' => 1.0, 'unit_code' => 'kg']);
    }
    $svc = app(SupplierItemService::class);

    expect($svc->searchGlobalErste($this->rootTeam, 'minestrone', [], 5)->pluck('id')->all())
        ->toBe($svc->searchGlobal($this->rootTeam, 'minestrone', [], 5)->getCollection()->pluck('id')->all());
});
