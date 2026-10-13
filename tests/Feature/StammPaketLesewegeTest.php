<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\SupplierService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamScope;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §12 A3 — Stamm-Lesewege ohne Filter (Vorbereitung Stamm-Umzug): stamm_paket ohne Opt-in bleibt auch über diese Wege unsichtbar.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    StammPaket::flush();
    $this->sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Hanos']);
    $this->basis = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => 'Basis', 'article_number' => 'B1', 'qty' => 1, 'unit_code' => 'kg']);
    $this->paket = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => 'Paket', 'article_number' => 'P1', 'qty' => 1, 'unit_code' => 'kg', 'stamm_class' => 'stamm_paket']);
});

it('StammPaket::begrenzen kennt Alias + Basistabelle', function () {
    $abfrage = fn () => StammPaket::begrenzen(DB::table('foodalchemist_supplier_items AS i')->select('i.id'), 'i', $this->rootTeam, 'foodalchemist_supplier_items');

    expect($abfrage()->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all())->toBe([$this->basis->id]);
    StammPaket::freischalten($this->rootTeam);
    expect($abfrage()->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all())->toBe([$this->basis->id, $this->paket->id]);
});

it('★ SupplierService::listWithCounts zählt Stamm-Paket-Artikel ohne Opt-in nicht', function () {
    $zeile = fn () => app(SupplierService::class)->listWithCounts($this->rootTeam)->firstWhere('id', $this->sup->id);
    $gp = $this->makeGp($this->rootTeam, 'Mehl');
    foreach ([$this->basis, $this->paket] as $la) {
        FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    }

    expect($zeile()->item_count)->toBe(1)->and($zeile()->mapped_count)->toBe(1);
    StammPaket::freischalten($this->rootTeam);
    expect($zeile()->item_count)->toBe(2)->and($zeile()->mapped_count)->toBe(2);
});

it('★ InhaltsFreigabe über Alias: aliasierte Abfrage = Eloquent-Sicht (Haken „erbt nichts" greift auch bei `recipes AS r`)', function () {
    $fremd = $this->makeRecipe($this->rootTeam, 'Geheimrezept', ['is_sales_recipe' => true]);
    app(InhaltsFreigabeService::class)->setzeErbtAlles($this->rootTeam, $this->childA->id, false, $this->makeUser($this->rootTeam, 'Inhaber'));

    $eloquent = FoodAlchemistRecipe::visibleToTeam($this->childA)->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
    $alias = TeamScope::applyVisible(DB::table('foodalchemist_recipes AS r')->whereNull('r.deleted_at')->select('r.id'), 'r.team_id', $this->childA)
        ->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();

    expect($eloquent)->not->toContain($fremd->id)
        ->and($alias)->toBe($eloquent);
});
