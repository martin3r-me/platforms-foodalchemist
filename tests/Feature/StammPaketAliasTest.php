<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamScope;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §12 A3: Die Stamm-Klasse (basis | stamm_paket) wirkt in TeamScope::applyVisible auch, wenn die Tabelle als Alias kommt
 * (`foodalchemist_supplier_items AS i`, Spalte `i.team_id`). Vorher: betrifft('i') = false, stamm_paket-Zeilen blieben ohne Opt-in sichtbar.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    StammPaket::flush();
    $this->sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Hanos']);
    $this->basis = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => 'Basis', 'article_number' => 'B1']);
    $this->paket = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => 'Paket', 'article_number' => 'P1', 'stamm_class' => 'stamm_paket']);
    $this->eigen = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->sup->id, 'designation' => 'Eigen', 'article_number' => 'E1']);
    $this->fremd = FoodAlchemistSupplierItem::create(['team_id' => Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false])->id, 'supplier_id' => $this->sup->id, 'designation' => 'Fremd', 'article_number' => 'F1']);

    $this->ids = fn ($query) => $query->get()->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
});

it('Alias in from: stamm_paket ist ohne Opt-in unsichtbar, mit Opt-in sichtbar; Team-Zeilen und fremde Teams wie bisher', function () {
    $abfrage = fn () => TeamScope::applyVisible(DB::table('foodalchemist_supplier_items AS i')->whereNull('i.deleted_at')->select('i.id'), 'i.team_id', $this->rootTeam);

    expect(($this->ids)($abfrage()))->toBe([$this->basis->id, $this->eigen->id]);          // kein Paket, kein Fremder

    StammPaket::freischalten($this->rootTeam);
    expect(($this->ids)($abfrage()))->toBe([$this->basis->id, $this->paket->id, $this->eigen->id]);
});

it('Alias aus einem Join (Spalte i.team_id, from ist eine andere Tabelle)', function () {
    $abfrage = fn () => TeamScope::applyVisible(
        DB::table('foodalchemist_suppliers AS sup')->join('foodalchemist_supplier_items AS i', 'i.supplier_id', '=', 'sup.id')->select('i.id'), 'i.team_id', $this->rootTeam,
    );

    expect(($this->ids)($abfrage()))->toBe([$this->basis->id, $this->eigen->id]);
    StammPaket::freischalten($this->rootTeam);
    expect(($this->ids)($abfrage()))->toContain($this->paket->id);
});

it('Alias auf GPs (Muster FavoriteGpService: foodalchemist_gps AS g) und Lieferanten', function () {
    $gpBasis = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'al|b', 'name' => 'Salz']);
    $gpPaket = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'al|p', 'name' => 'Mehl', 'stamm_class' => 'stamm_paket']);
    $supPaket = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Paket-Lieferant', 'stamm_class' => 'stamm_paket']);

    $gps = fn () => TeamScope::applyVisible(DB::table('foodalchemist_gps AS g')->whereNull('g.deleted_at')->select('g.id'), 'g.team_id', $this->rootTeam);
    $lief = fn () => TeamScope::applyVisible(DB::table('foodalchemist_suppliers AS s')->select('s.id'), 's.team_id', $this->rootTeam);

    expect(($this->ids)($gps()))->toBe([$gpBasis->id])->and(($this->ids)($lief()))->toBe([$this->sup->id]);
    StammPaket::freischalten($this->rootTeam);
    expect(($this->ids)($gps()))->toBe([$gpBasis->id, $gpPaket->id])->and(($this->ids)($lief()))->toContain($supPaket->id);
});

it('Ohne Alias und mit vollem Tabellennamen verhält es sich unverändert; Eloquent-Builder ebenso', function () {
    $voll = fn () => TeamScope::applyVisible(DB::table('foodalchemist_supplier_items')->whereNull('deleted_at')->select('id'), 'foodalchemist_supplier_items.team_id', $this->rootTeam);
    $ohnePunkt = fn () => TeamScope::applyVisible(DB::table('foodalchemist_supplier_items')->whereNull('deleted_at')->select('id'), 'team_id', $this->rootTeam);
    $eloquent = fn () => TeamScope::applyVisible(FoodAlchemistSupplierItem::query()->select('id'), 'foodalchemist_supplier_items.team_id', $this->rootTeam);

    foreach ([$voll, $ohnePunkt, $eloquent] as $abfrage) {
        expect(($this->ids)($abfrage()))->toBe([$this->basis->id, $this->eigen->id]);
    }
    StammPaket::freischalten($this->rootTeam);
    foreach ([$voll, $ohnePunkt, $eloquent] as $abfrage) {
        expect(($this->ids)($abfrage()))->toContain($this->paket->id);
    }
});

it('Alias OHNE Punkt in der Spalte: from-Alias wird als Präfix der Klassenspalte benutzt', function () {
    $abfrage = fn () => TeamScope::applyVisible(DB::table('foodalchemist_supplier_items AS i')->select('i.id'), 'team_id', $this->rootTeam);

    expect(($this->ids)($abfrage()))->toBe([$this->basis->id, $this->eigen->id]);
});

it('Alias auf einer Tabelle ohne Stamm-Klasse bleibt unberührt (nur NULL oder Kette)', function () {
    DB::table('foodalchemist_vocab_kitchen_equipment')->insert(['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null, 'slug' => 'topf', 'name' => 'Topf', 'sort_order' => 1, 'is_inactive' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $zeilen = TeamScope::applyVisible(DB::table('foodalchemist_vocab_kitchen_equipment AS e')->select('e.id'), 'e.team_id', $this->rootTeam)->pluck('id');

    expect($zeilen)->toHaveCount(1);
});

it('Master sieht das Paket auch über Alias ohne Opt-in', function () {
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    StammPaket::flush();

    expect(($this->ids)(TeamScope::applyVisible(DB::table('foodalchemist_supplier_items AS i')->select('i.id'), 'i.team_id', $this->rootTeam)))->toContain($this->paket->id);
});
