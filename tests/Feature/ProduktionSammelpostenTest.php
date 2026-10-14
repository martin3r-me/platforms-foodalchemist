<?php

use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine as Line;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation as Posten;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProductionCapacityService;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 83 B3g: der Pass ist ein SAMMELPOSTEN ohne eigenes Personal (`production_stations.ist_sammelposten`). Kapazität und Last hängen nicht
 * am Pass selbst: keine Überlast-Warnung für ihn; ein normaler Posten mit derselben Last warnt weiter.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ProductionOrderService::class);
    $this->kap = app(ProductionCapacityService::class);
    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'fond', 'name' => 'Brauner Fond', 'status' => 'approved',
        'is_sales_recipe' => false, 'yield_kg' => 2.0, 'work_time_min' => 120, 'batch_max_kg' => 2.0,
    ]);
    $this->order = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Sammelposten', [
        ['source_ref' => 'r:fond', 'recipe_id' => $this->rezept->id, 'amount_kg' => 3.0],
    ]);
    $this->zuteilen = fn (Posten $p) => $this->svc->assignLine($this->rootTeam, Line::where('production_order_id', $this->order->id)->first()->id, ['station_id' => $p->id]);
});

it('★ ein normaler Posten über Kapazität warnt, derselbe Posten als Sammelposten (Pass) nicht', function () {
    $normal = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'saucier', 'name' => 'Saucier', 'kapazitaet_min_pro_tag' => 60]);
    ($this->zuteilen)($normal);
    expect($this->kap->warnungenFuer($this->rootTeam, $this->order->id))->not->toBeEmpty();

    $normal->update(['ist_sammelposten' => true]);
    expect($this->kap->warnungenFuer($this->rootTeam, $this->order->id))->toBe([]);

    $bucket = collect($this->kap->auslastung($this->rootTeam, '2026-08-19', '2026-08-21'))->flatten(1)->firstWhere('station_id', $normal->id);
    expect($bucket['ist_sammelposten'])->toBeTrue()->and($bucket['kapazitaet_min'])->toBeNull()->and($bucket['stufe'])->toBe('ohne_kapazitaet')
        ->and($bucket['geplant_min'])->toBeGreaterThan(0);   // die Last bleibt sichtbar (Minutensumme), nur ohne Kapazitätsurteil
});

it('das Feld ist standardmäßig aus und als boolean gecastet', function () {
    $p = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'kalt', 'name' => 'Kalt'])->refresh();

    expect($p->ist_sammelposten)->toBeFalse();
});
