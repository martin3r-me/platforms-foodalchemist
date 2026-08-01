<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Livewire\Produktion\Tagesplan;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine as Line;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation as Posten;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 30 E3 — Tagesplan. Die Sicht, die Vorproduktion überhaupt erst nützlich macht:
 * Zeilen aus MEHREREN Aufträgen an einem Tag, mit dem Auftrag als Kontext.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ProductionOrderService::class);
    $this->actingAs($this->makeUser($this->rootTeam, 'Küchenchef'));

    $this->fond = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'fond', 'name' => 'Brauner Fond',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 2.0, 'work_time_min' => 120,
    ]);
    $this->glace = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'glace', 'name' => 'Glace de Viande',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 1.0, 'work_time_min' => 90,
    ]);

    // Zwei Aufträge mit VERSCHIEDENEN Liefertagen, deren Vorproduktion auf denselben Tag fällt.
    $this->a1 = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Hochzeit Meyer', [
        ['source_ref' => 'r:fond', 'recipe_id' => $this->fond->id, 'amount_kg' => 2.0],
    ]);
    $this->a2 = $this->svc->saveNew($this->rootTeam, '2026-08-21', 'Tagung Schmitt', [
        ['source_ref' => 'r:glace', 'recipe_id' => $this->glace->id, 'amount_kg' => 1.0],
    ]);

    $this->plan = fn () => Livewire::test(Tagesplan::class, ['von' => '2026-08-15']);
});

it('führt Zeilen mehrerer Aufträge an einem Tag zusammen — mit Auftrag und Liefertag als Kontext', function () {
    $l1 = Line::where('production_order_id', $this->a1->id)->firstOrFail();
    $l2 = Line::where('production_order_id', $this->a2->id)->firstOrFail();
    $this->svc->assignLine($this->rootTeam, $l1->id, ['vorlauf_tage' => 2]);   // 20.08. − 2 = 18.08.
    $this->svc->assignLine($this->rootTeam, $l2->id, ['vorlauf_tage' => 3]);   // 21.08. − 3 = 18.08.

    ($this->plan)()->set('tage', 14)
        ->assertSeeHtml('data-tagesplan-tag="2026-08-18"')
        ->assertSee('Brauner Fond')->assertSee('Glace de Viande')
        ->assertSee('Hochzeit Meyer')->assertSee('Tagung Schmitt')
        ->assertSee('für 20.08.')->assertSee('für 21.08.');
});

it('zeigt Auslastung mit Balken nur dort, wo eine Kapazität hinterlegt ist', function () {
    $mit = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'mit', 'name' => 'Warme Küche', 'kapazitaet_min_pro_tag' => 480]);
    $ohne = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'ohne', 'name' => 'Kalte Küche']);

    $this->svc->assignLine($this->rootTeam, Line::where('production_order_id', $this->a1->id)->value('id'), ['station_id' => $mit->id]);
    $this->svc->assignLine($this->rootTeam, Line::where('production_order_id', $this->a2->id)->value('id'), ['station_id' => $ohne->id]);

    // Die View zeigt den Auslastungs-Block; die Kapazitäts-SEMANTIK wird unten am Service
    // geprüft statt am gerenderten Zahlen-String — sonst hinge der Test an Whitespace.
    ($this->plan)()->set('tage', 14)
        ->assertSee('Warme Küche')->assertSee('Kalte Küche')
        ->assertSeeHtml('data-tagesplan-auslastung');

    $auslastung = app(\Platform\FoodAlchemist\Services\ProductionCapacityService::class)
        ->auslastung($this->rootTeam, '2026-08-15', '2026-08-28');
    $buckets = collect($auslastung)->flatten(1);

    expect($buckets->firstWhere('station_id', $mit->id)['kapazitaet_min'])->toBe(480)
        ->and($buckets->firstWhere('station_id', $ohne->id)['kapazitaet_min'])->toBeNull()
        ->and($buckets->firstWhere('station_id', $ohne->id)['stufe'])->toBe('ohne_kapazitaet');
});

it('meldet Überlast am Tag, aber blockiert nichts', function () {
    $eng = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'eng', 'name' => 'Patisserie', 'kapazitaet_min_pro_tag' => 60]);
    $this->svc->assignLine($this->rootTeam, Line::where('production_order_id', $this->a1->id)->value('id'), ['station_id' => $eng->id]);

    ($this->plan)()->set('tage', 14)->assertSee('Überlast')->assertSee('200 %');
});

it('verschiebt eine Zeile über den Vorlauf auf einen anderen Tag', function () {
    $l = Line::where('production_order_id', $this->a1->id)->firstOrFail();

    ($this->plan)()->set('tage', 14)->call('vorlaufSetzen', $l->id, '4')->assertSet('fehler', null);

    expect($l->fresh()->plan_date->toDateString())->toBe('2026-08-16');
});

it('filtert auf einen Posten und wieder zurück', function () {
    $p = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'wk', 'name' => 'Warme Küche']);
    $this->svc->assignLine($this->rootTeam, Line::where('production_order_id', $this->a1->id)->value('id'), ['station_id' => $p->id]);

    ($this->plan)()->set('tage', 14)
        ->call('postenWaehlen', $p->id)
        ->assertSet('postenFilter', $p->id)
        ->assertSee('Brauner Fond')
        ->assertDontSee('Glace de Viande')          // hängt an keinem Posten
        ->call('postenWaehlen', $p->id)             // zweiter Klick hebt auf
        ->assertSet('postenFilter', null)
        ->assertSee('Glace de Viande');
});

it('blendet erledigte und stornierte Aufträge aus', function () {
    $this->svc->setStatus($this->rootTeam, $this->a1->id, ProductionOrderStatus::InProgress);
    $this->svc->setStatus($this->rootTeam, $this->a1->id, ProductionOrderStatus::Done);

    ($this->plan)()->set('tage', 14)
        ->assertDontSee('Hochzeit Meyer')
        ->assertSee('Tagung Schmitt');
});

it('sagt im Leerzustand, was der Tagesplan überhaupt zeigt', function () {
    Livewire::test(Tagesplan::class, ['von' => '2027-01-01'])
        ->assertSeeHtml('data-tagesplan-leer')
        ->assertSee('geplanten und laufenden');
});

it('das Zeitfenster steht in der URL — Kontext überlebt einen Reload', function () {
    expect(collect((new ReflectionClass(Tagesplan::class))->getProperties())
        ->filter(fn ($p) => $p->getAttributes(\Livewire\Attributes\Url::class) !== [])
        ->map(fn ($p) => $p->getName())->values()->all())
        ->toBe(['von', 'tage', 'postenFilter']);
});
