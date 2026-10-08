<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Services\WareneingangService;

/**
 * Spec 75 · gemeinsame Fixture für Wareneingang und Triple Match (geteilt als Trait, nicht als
 * globale Funktion — sonst stirbt der parallele Lauf). Chefs liefert Mehl (2 €) und Zucker (1 €)
 * im 1-kg-Sack, Hanos Butter (12 €). Zwei offene Chefs-Bestellungen an zwei Liefertagen, eine
 * Hanos-Bestellung; alle gesendet. Inhaber (owner) + Koch (Mitglied = Kuratieren).
 */
trait SeedsWareneingang
{
    protected function seedWareneingang(): void
    {
        $this->seedTeamHierarchy();
        $t = $this->rootTeam->id;
        $this->orders = app(OrderService::class);
        $this->svc = app(WareneingangService::class);
        $this->rechte = app(FaRechte::class);

        $this->inhaber = $this->makeUser($this->rootTeam, 'Inhaber', 'owner');
        // Mitglied = Kuratieren, der Normalfall „Küche bucht Lieferschein"
        $this->koch = $this->makeUser($this->rootTeam, 'Koch', 'member');

        $this->la = [];
        $mk = function (string $name, string $lieferant, float $preis) use ($t) {
            $sup = FoodAlchemistSupplier::firstOrCreate(['team_id' => $t, 'name' => $lieferant]);
            $gp = $this->makeGp($this->rootTeam, $name);
            $la = FoodAlchemistSupplierItem::create(['team_id' => $t, 'supplier_id' => $sup->id, 'designation' => $name.' 1kg',
                'article_number' => 'A-'.$name, 'qty' => 1.0, 'unit_code' => 'kg', 'packaging_unit' => 'Sack']);
            FoodAlchemistSupplierItemStructure::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
            FoodAlchemistPrice::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
            $gp->update(['lead_la_supplier_item_id' => $la->id]);
            $this->la[$name] = $la;
            $this->gp[$name] = $gp->refresh();

            return $sup;
        };
        $this->chefs = $mk('Mehl', 'Chefs', 2.0);
        $mk('Zucker', 'Chefs', 1.0);
        $this->hanos = $mk('Butter', 'Hanos', 12.0);
        $this->lager = FoodAlchemistInventoryLocation::create(['team_id' => $t, 'name' => 'Hauptlager', 'type' => 'warehouse', 'is_default' => true, 'is_active' => true]);

        $tag1 = now()->addDays(3)->toDateString();
        $tag2 = now()->addDays(4)->toDateString();
        $this->mehlLine = $this->orders->addManualLine($this->rootTeam, $this->la['Mehl']->id, 10, null, null, $tag1);
        $this->zuckerLine = $this->orders->addManualLine($this->rootTeam, $this->la['Zucker']->id, 2, null, null, $tag1);
        $this->mehl2Line = $this->orders->addManualLine($this->rootTeam, $this->la['Mehl']->id, 5, null, null, $tag2);
        $this->butterLine = $this->orders->addManualLine($this->rootTeam, $this->la['Butter']->id, 3, null, null, $tag1);
        foreach (FoodAlchemistOrder::all() as $o) {
            $this->orders->setStatus($this->rootTeam, $o->id, OrderStatus::Sent);
        }
        $this->order1 = $this->mehlLine->order()->first();
        $this->order2 = $this->mehl2Line->order()->first();
        $this->positionen = fn (array $vorschlag, array $mengen) => array_map(fn ($r) => ['order_line_id' => $r['order_line_id'],
            'qty_packs' => $mengen[$r['order_line_id']] ?? $r['qty_packs']], $vorschlag);
        $this->bestand = fn (string $gp) => (float) FoodAlchemistInventoryStock::where('gp_id', $this->gp[$gp]->id)->sum('qty_base');
    }
}
