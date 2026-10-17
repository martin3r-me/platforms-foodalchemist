<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine as Line;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PaketGrundeinstellungenService;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 83 B2a — ein Master-Rezept im Abo trägt einen MASTER-Posten (für den Mandanten fremd). Das Paket "Grundeinstellungen" hat diesen Posten
 * kopiert; die Zuordnung steht in paket_kopien. Reihenfolge in standardPosten(): eigen/sichtbar > Kopie über paket_kopien > Team-Vorgabe bei Gerichten
 * > Warnung. Ohne Kopie wird nichts still übernommen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->actingAs($this->admin);
    $this->svc = app(ProductionOrderService::class);

    $this->masterPosten = DB::table('foodalchemist_production_stations')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->master->id, 'slug' => 'saucier', 'name' => 'Saucier', 'sort_order' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // Das Rezept trägt den MASTER-Posten (so kommt ein Master-Rezept im Abo zum Mandanten)
    $this->aboRezept = fn (bool $gericht = false) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'abo'.uniqid(), 'name' => 'Abo-Fond', 'status' => 'approved', 'is_sales_recipe' => $gericht,
        'yield_kg' => 2.0, 'work_time_min' => 20, 'default_station_id' => $this->masterPosten, 'sales_net' => $gericht ? 3.0 : null, 'sales_unit_count' => $gericht ? 1 : null,
    ]);
    $this->auftrag = fn (Team $team, FoodAlchemistRecipe $r) => $this->svc->saveNew($team, '2026-08-20', 'Abo-Test',
        [['source_ref' => 'r:'.$r->recipe_key, 'recipe_id' => $r->id, 'amount_kg' => 4.0]]);
    $this->zeile = fn ($order, $r) => Line::where('production_order_id', $order->id)->where('recipe_id', $r->id)->first();
});

it('★ ohne Paket-Kopie wird der Master-Posten nicht still übernommen: Zeile ohne Posten + Warnung', function () {
    $r = ($this->aboRezept)();
    $order = ($this->auftrag)($this->rootTeam, $r);

    expect(($this->zeile)($order, $r)->station_id)->toBeNull()
        ->and(collect($order->refresh()->warnungen)->implode(' '))->toContain('Abo-Fond')->toContain('nicht zu diesem Team');
});

it('★ mit der Paket-Kopie wird der Master-Posten auf die Kopie im Mandanten-Team aufgelöst', function () {
    app(PaketGrundeinstellungenService::class)->buchen($this->rootTeam, $this->admin);
    $kopie = FoodAlchemistProductionStation::where('team_id', $this->rootTeam->id)->where('slug', 'saucier')->first();
    expect($kopie)->not->toBeNull();

    $r = ($this->aboRezept)();
    $order = ($this->auftrag)($this->rootTeam, $r);

    expect((int) ($this->zeile)($order, $r)->station_id)->toBe((int) $kopie->id)             // die Kopie, nicht der Master-Posten
        ->and((int) ($this->zeile)($order, $r)->station_id)->not->toBe((int) $this->masterPosten)
        ->and(collect($order->refresh()->warnungen)->implode(' '))->not->toContain('nicht zu diesem Team');
});

it('ein Unter-Team löst über die Kopie seines Haupt-Teams auf', function () {
    app(PaketGrundeinstellungenService::class)->buchen($this->rootTeam, $this->admin);
    $kopie = FoodAlchemistProductionStation::where('team_id', $this->rootTeam->id)->where('slug', 'saucier')->first();
    $r = FoodAlchemistRecipe::create([
        'team_id' => $this->childA->id, 'recipe_key' => 'abo-kind', 'name' => 'Abo-Kind', 'status' => 'approved', 'is_sales_recipe' => false,
        'yield_kg' => 2.0, 'work_time_min' => 20, 'default_station_id' => $this->masterPosten,
    ]);

    // Spec 84 B3: der Auftrag entsteht im Unter-Team (in der Oberfläche ist das UI-Team = Auftrags-Team)
    $order = \Platform\FoodAlchemist\Support\FaTeam::imTeam($this->childA, fn () => ($this->auftrag)($this->childA, $r));

    expect((int) ($this->zeile)($order, $r)->station_id)->toBe((int) $kopie->id);
});

it('eine stillgelegte Kopie löst nicht mehr auf (Gericht fällt auf die Team-Vorgabe zurück)', function () {
    app(PaketGrundeinstellungenService::class)->buchen($this->rootTeam, $this->admin);   // setzt Pass + Team-Vorgabe
    $kopie = FoodAlchemistProductionStation::where('team_id', $this->rootTeam->id)->where('slug', 'saucier')->first();
    $pass = FoodAlchemistProductionStation::where('team_id', $this->rootTeam->id)->where('slug', 'pass')->first();
    $kopie->update(['is_inactive' => true]);

    $gericht = ($this->aboRezept)(true);
    $order = ($this->auftrag)($this->rootTeam, $gericht);

    expect((int) ($this->zeile)($order, $gericht)->station_id)->toBe((int) $pass->id);          // Kopie nicht nutzbar → Pass
});

it('Reihenfolge: ein eigener, sichtbarer Rezept-Posten geht vor der Auflösung', function () {
    app(PaketGrundeinstellungenService::class)->buchen($this->rootTeam, $this->admin);
    $eigen = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'slug' => 'eigen', 'name' => 'Eigen']);
    $r = ($this->aboRezept)();
    $r->update(['default_station_id' => $eigen->id]);

    $order = ($this->auftrag)($this->rootTeam, $r);

    expect((int) ($this->zeile)($order, $r)->station_id)->toBe((int) $eigen->id);
});
