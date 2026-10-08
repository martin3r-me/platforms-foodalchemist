<?php

use Platform\FoodAlchemist\Models\FoodAlchemistInventoryCountLine;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryMovement;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistPurchaseTransaction;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\InventurService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 66 · Inventur (Stufe 1): Zählliste vorbelegt, Soll + Bewertung eingefroren, Buchen setzt
 * den Bestand auf die gezählte Menge, Bestandswert zum Stichtag.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(InventurService::class);

    $lief = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs']);
    $this->gpMit = function (string $name, float $preis, string $unit = 'kg', float $qty = 1.0) use ($lief) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lief->id, 'designation' => $name . ' ' . $qty . $unit, 'article_number' => 'A-' . $name, 'qty' => $qty, 'unit_code' => $unit]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return $gp;
    };
    $this->mehl = ($this->gpMit)('Mehl', 2.00);          // 2 €/kg = 0,002 €/g
    $this->zucker = ($this->gpMit)('Zucker', 1.50);      // 1,50 €/kg
    $this->eier = ($this->gpMit)('Eier', 1.80, 'Stk', 6); // 0,30 €/Stk

    $this->lager = FoodAlchemistInventoryLocation::create(['team_id' => $this->rootTeam->id, 'name' => 'Hauptlager', 'type' => 'warehouse', 'is_default' => true, 'is_active' => true]);
    FoodAlchemistInventoryStock::create(['team_id' => $this->rootTeam->id, 'inventory_location_id' => $this->lager->id, 'gp_id' => $this->mehl->id, 'qty_base' => 5000, 'base_unit' => 'g']);
    // Zucker + Eier wurden eingekauft, haben aber noch keinen Bestand
    foreach ([[$this->zucker, 'kg'], [$this->eier, 'Stk']] as [$gp, $u]) {
        FoodAlchemistPurchaseTransaction::create(['team_id' => $this->rootTeam->id, 'gp_id' => $gp->id, 'designation_raw' => $gp->name, 'unit_code' => $u, 'qty' => 10, 'unit_price' => 1, 'line_total' => 10, 'purchased_at' => '2026-09-20', 'source' => 'fa_order', 'source_hash' => sha1($gp->name)]);
    }
});

it('Anlegen: Zählliste aus Bestand + Einkäufen, Soll und Bewertung eingefroren', function () {
    $c = $this->svc->anlegen($this->rootTeam, $this->lager->id, '2026-09-30');
    $lines = $c->lines()->with('gp')->get()->keyBy(fn ($l) => $l->gp->name);

    expect($lines->keys()->all())->toBe(['Eier', 'Mehl', 'Zucker'])
        ->and((float) $lines['Mehl']->qty_expected)->toBe(5000.0)
        ->and($lines['Mehl']->base_unit)->toBe('g')
        ->and((float) $lines['Mehl']->price_per_base)->toBe(0.002)
        ->and($lines['Eier']->base_unit)->toBe('Stk')
        ->and((float) $lines['Eier']->price_per_base)->toBe(0.3)
        ->and((float) $lines['Zucker']->qty_expected)->toBe(0.0);
});

it('Zählen in kg/l/Stk, Buchen setzt Bestand auf gezählt und bucht die Differenz', function () {
    $c = $this->svc->anlegen($this->rootTeam, $this->lager->id, '2026-09-30');
    $l = fn ($name) => $c->lines()->get()->first(fn ($x) => $x->gp_id === $this->{$name}->id);
    $this->svc->zaehlen($this->rootTeam, $l('mehl')->id, '4,5');      // 4,5 kg
    $this->svc->zaehlen($this->rootTeam, $l('eier')->id, '12');       // 12 Stk
    // Zucker nicht gezählt

    $gebucht = $this->svc->buchen($this->rootTeam, $c->id, $this->user->id);
    expect($gebucht->status)->toBe('gebucht')
        ->and((float) $gebucht->value_total)->toBe(12.6);              // 4500 g × 0,002 + 12 × 0,30

    $mehl = FoodAlchemistInventoryStock::where('gp_id', $this->mehl->id)->first();
    expect((float) $mehl->qty_base)->toBe(4500.0);
    $mv = FoodAlchemistInventoryMovement::where('gp_id', $this->mehl->id)->where('source', 'inventur')->first();
    expect($mv->direction)->toBe('out')->and((float) $mv->qty_base)->toBe(500.0);

    expect((float) FoodAlchemistInventoryStock::where('gp_id', $this->eier->id)->first()->qty_base)->toBe(12.0)
        ->and(FoodAlchemistInventoryStock::where('gp_id', $this->zucker->id)->exists())->toBeFalse();   // nicht gezählt → unberührt

    expect(fn () => $this->svc->zaehlen($this->rootTeam, $l('zucker')->id, '1'))->toThrow(\RuntimeException::class, 'bereits gebucht');
});

it('Position nachtragen, ungültige Menge abgelehnt, offene Inventur löschbar', function () {
    $c = $this->svc->anlegen($this->rootTeam, $this->lager->id, '2026-09-30');
    $neu = ($this->gpMit)('Öl', 4.00, 'l');
    $line = $this->svc->positionHinzu($this->rootTeam, $c->id, $neu->id);
    expect($line->base_unit)->toBe('ml')->and((float) $line->price_per_base)->toBe(0.004);

    expect(fn () => $this->svc->zaehlen($this->rootTeam, $line->id, 'viel'))->toThrow(\RuntimeException::class);

    $this->svc->loeschen($this->rootTeam, $c->id);
    expect(FoodAlchemistInventoryCountLine::where('inventory_count_id', $c->id)->count())->toBe(0);
});

it('Bestandswert zum Stichtag: jüngste gebuchte Inventur ≤ Stichtag je Lagerort', function () {
    $a = $this->svc->anlegen($this->rootTeam, $this->lager->id, '2026-08-31');
    $this->svc->zaehlen($this->rootTeam, $a->lines()->get()->first(fn ($x) => $x->gp_id === $this->mehl->id)->id, '10');
    $this->svc->buchen($this->rootTeam, $a->id);
    $b = $this->svc->anlegen($this->rootTeam, $this->lager->id, '2026-09-30');
    $this->svc->zaehlen($this->rootTeam, $b->lines()->get()->first(fn ($x) => $x->gp_id === $this->mehl->id)->id, '4');
    $this->svc->buchen($this->rootTeam, $b->id);

    expect($this->svc->bestandswert($this->rootTeam, '2026-09-15'))->toMatchArray(['wert' => 20.0, 'vollstaendig' => true])
        ->and($this->svc->bestandswert($this->rootTeam, '2026-10-01')['wert'])->toBe(8.0)
        ->and($this->svc->bestandswert($this->rootTeam, '2026-08-01')['vollstaendig'])->toBeFalse();
});

it('Team-strikt: Kind-Team sieht die Inventur der Zentrale nicht', function () {
    $c = $this->svc->anlegen($this->rootTeam, $this->lager->id, '2026-09-30');
    expect(fn () => $this->svc->detail($this->childA, $c->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
