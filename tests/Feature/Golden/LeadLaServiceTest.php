<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGpLaPreference;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\StammLieferantService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M3-06: LeadLaService — GL-03-Kaskade (Golden-Essenzen GT-1…7) + V-27-Overlay
 * (Strategie, Sperre, Pin). GT-1-Realfall (GP 6723, Soll-Lead 29344887) läuft
 * zusätzlich als DB-Spotcheck gegen den Sandbox-Seed (siehe Roadmap-Notiz).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(LeadLaService::class);

    FoodAlchemistLookupWarengruppe::create(['team_id' => $this->rootTeam->id, 'code' => '10', 'name' => 'Getränke']);
    $this->gp = $this->makeGp($this->rootTeam, 'Limettensaft');
    $this->gp->update(['commodity_group_code' => '10']);
    $this->gp->refresh();

    $this->mkLa = function (string $lieferant, array $item = [], ?float $preis = null, string $status = '0', $gp = null) {
        $supplier = FoodAlchemistSupplier::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => $lieferant]);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
            'designation' => 'LA ' . uniqid(), ...$item,
        ]);
        FoodAlchemistSupplierItemStructure::create([
            'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => ($gp ?? $this->gp)->id,
        ]);
        if ($preis !== null) {
            FoodAlchemistPrice::create([
                'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id,
                'price' => $preis, 'status' => $status,
            ]);
        }

        return $la;
    };
});

it('GT-1-Essenz (A-2 NULLS LAST): bepreist-mit-qty schlägt Aktionspreis ohne qty', function () {
    $ohneQty = ($this->mkLa)('Delta Fleisch', [], 47.50, '2');                       // qty NULL ⇒ Vergleichspreis NULL
    $mitQty = ($this->mkLa)('Chefs Culinar West', ['qty' => 0.75, 'unit_code' => 'l'], 2.69);
    $teurer = ($this->mkLa)('Hanos Venlo', ['qty' => 1.0, 'unit_code' => 'l'], 6.59);

    $kette = $this->svc->rangliste($this->gp, $this->rootTeam);

    expect($kette->first()->id)->toBe($mitQty->id)                                   // 3,59 €/l gewinnt
        ->and($kette->last()->id)->toBe($ohneQty->id)                                // NULL ans ENDE — nicht SQLite-NULLS-FIRST!
        ->and($this->svc->pickLeadLa($this->gp, $this->rootTeam))->toBe($mitQty->id);
});

it('GT-2-Essenz + V-27-Strategie: Stamm schlägt Preis — guenstigster_preis dreht es um', function () {
    $stammTeuer = ($this->mkLa)('Edna Backwaren', ['qty' => 1.0, 'unit_code' => 'kg'], 9.20);
    $fremdBillig = ($this->mkLa)('BOS Food', ['qty' => 1.0, 'unit_code' => 'kg'], 3.59);
    app(StammLieferantService::class)->setStamm($this->rootTeam, $stammTeuer->supplier_id, '10');

    expect($this->svc->pickLeadLa($this->gp, $this->rootTeam))->toBe($stammTeuer->id); // Stufe 0 schlägt Stufe 3

    app(TeamSettingsService::class)->update($this->rootTeam, ['lead_la_strategie' => 'guenstigster_preis']);
    expect($this->svc->pickLeadLa($this->gp, $this->rootTeam))->toBe($fremdBillig->id); // Stufe 0 übersprungen
});

it('GT-3: Phantom-GP ohne LAs ⇒ NULL, kein Fehler', function () {
    $phantom = $this->makeGp($this->rootTeam, 'Derivat ohne LA');

    expect($this->svc->pickLeadLa($phantom, $this->rootTeam))->toBeNull()
        ->and($this->svc->effektiverLead($phantom, $this->rootTeam))->toBeNull();
});

it('GT-4 (I3 weiche Kriterien): einziger Kandidat discontinued + unbepreist wird trotzdem Lead', function () {
    $einziger = ($this->mkLa)('Hanos Venlo', ['is_discontinued' => true]);

    expect($this->svc->pickLeadLa($this->gp, $this->rootTeam))->toBe($einziger->id);
});

it('GT-7 (I1 Determinismus): Gleichstand ⇒ Lieferantenname alphabetisch, dann supplier_item_id', function () {
    $berta = ($this->mkLa)('Berta GmbH', ['qty' => 1.0, 'unit_code' => 'kg'], 5.00);
    $anton = ($this->mkLa)('Anton GmbH', ['qty' => 1.0, 'unit_code' => 'kg'], 5.00);
    $anton2 = ($this->mkLa)('Anton GmbH', ['qty' => 1.0, 'unit_code' => 'kg'], 5.00);

    $kette = $this->svc->rangliste($this->gp, $this->rootTeam);

    expect($kette->pluck('id')->all())->toBe([$anton->id, $anton2->id, $berta->id]); // Anton vor Berta, kleinere id zuerst
});

it('GT-5 (I4): Lead entknüpfen ⇒ sofortige Neuwahl; letzten entknüpfen ⇒ NULL', function () {
    $x = ($this->mkLa)('Anton GmbH', ['qty' => 1.0, 'unit_code' => 'kg'], 2.00);
    $y = ($this->mkLa)('Berta GmbH', ['qty' => 1.0, 'unit_code' => 'kg'], 4.00);
    $this->gp->update(['n_las_total' => 2]);
    $this->svc->applyLeadLa($this->gp->refresh(), $this->rootTeam);
    expect($this->gp->fresh()->lead_la_supplier_item_id)->toBe($x->id);

    $this->svc->entknuepfen($this->rootTeam, $this->gp->fresh(), $x->id);
    $frisch = $this->gp->fresh();
    expect($frisch->lead_la_supplier_item_id)->toBe($y->id)
        ->and($frisch->n_las_total)->toBe(1);

    $this->svc->entknuepfen($this->rootTeam, $frisch, $y->id);
    expect($this->gp->fresh()->lead_la_supplier_item_id)->toBeNull();
});

it('GT-6 (I2): manueller Override validiert GP-Zugehörigkeit; NULL erlaubt', function () {
    $fremdesGp = $this->makeGp($this->rootTeam, 'Anderes GP');
    $fremderLa = ($this->mkLa)('BOS Food', [], null, '0', $fremdesGp);
    $eigener = ($this->mkLa)('Anton GmbH');

    expect(fn () => $this->svc->setLeadLa($this->rootTeam, $this->gp, $fremderLa->id))
        ->toThrow(RuntimeException::class, 'nicht mit GP');

    $this->svc->setLeadLa($this->rootTeam, $this->gp, $eigener->id);
    expect($this->gp->fresh()->lead_la_supplier_item_id)->toBe($eigener->id);

    $this->svc->setLeadLa($this->rootTeam, $this->gp, null);                 // Lead bewusst leeren
    expect($this->gp->fresh()->lead_la_supplier_item_id)->toBeNull();
});

it('V-27: Team-Sperre nimmt den LA aus der effektiven Kette — globaler Lead bleibt', function () {
    $billig = ($this->mkLa)('Anton GmbH', ['qty' => 1.0, 'unit_code' => 'kg'], 2.00);
    $zweiter = ($this->mkLa)('Berta GmbH', ['qty' => 1.0, 'unit_code' => 'kg'], 4.00);
    $this->svc->applyLeadLa($this->gp->refresh(), $this->rootTeam);

    $this->svc->sperren($this->childA, $this->gp, $billig->id);

    expect($this->svc->effektiverLead($this->gp, $this->childA)->id)->toBe($zweiter->id) // Kind weicht aus
        ->and($this->svc->effektiverLead($this->gp, $this->rootTeam)->id)->toBe($billig->id) // Root unberührt
        ->and($this->gp->fresh()->lead_la_supplier_item_id)->toBe($billig->id);             // global unverändert

    $this->svc->sperren($this->childA, $this->gp, $billig->id, false);                      // entsperren
    expect($this->svc->effektiverLead($this->gp, $this->childA)->id)->toBe($billig->id)
        ->and(FoodAlchemistGpLaPreference::count())->toBe(0);                               // Overlay aufgeräumt
});

it('V-27: Pin fixiert den effektiven Lead und überlebt die Bulk-Neuwahl', function () {
    $billig = ($this->mkLa)('Anton GmbH', ['qty' => 1.0, 'unit_code' => 'kg'], 2.00);
    $liebling = ($this->mkLa)('Berta GmbH', ['qty' => 1.0, 'unit_code' => 'kg'], 8.00);

    $this->svc->pinnen($this->childA, $this->gp, $liebling->id);
    expect($this->svc->effektiverLead($this->gp, $this->childA)->id)->toBe($liebling->id);

    $this->svc->applyLeadLa($this->gp->refresh(), $this->rootTeam);                  // „Bulk"-Neuwahl global
    expect($this->gp->fresh()->lead_la_supplier_item_id)->toBe($billig->id)          // global: Heuristik
        ->and($this->svc->effektiverLead($this->gp, $this->childA)->id)->toBe($liebling->id); // Pin überlebt

    $this->svc->pinnen($this->childA, $this->gp, null);                              // Pin lösen
    expect($this->svc->effektiverLead($this->gp, $this->childA)->id)->toBe($billig->id);
});

it('V-27: nur ein Pin pro GP/Team — neuer Pin löst den alten ab', function () {
    $a = ($this->mkLa)('Anton GmbH');
    $b = ($this->mkLa)('Berta GmbH');

    $this->svc->pinnen($this->childA, $this->gp, $a->id);
    $this->svc->pinnen($this->childA, $this->gp, $b->id);

    expect(FoodAlchemistGpLaPreference::where('gepinnt', true)->count())->toBe(1)
        ->and($this->svc->effektiverLead($this->gp, $this->childA)->id)->toBe($b->id);
});
