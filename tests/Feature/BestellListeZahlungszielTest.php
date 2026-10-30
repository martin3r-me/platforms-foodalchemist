<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Orders\Index as OrdersIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\OrdersGetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Bestell-Liste (UI + orders.GET): Fälligkeit = Rechnungsdatum + Zahlungsziel des Lieferanten. Vorher lud listForTeam den
 * Lieferanten ohne `payment_term_days` — Zahlungsziel und Fälligkeit standen in der Liste immer leer, im Detail stimmten sie.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs Culinar', 'payment_term_days' => 14]);
    $this->order = FoodAlchemistOrder::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lieferant->id, 'status' => 'delivered',
        'invoice_number' => 'RE-4711', 'invoice_date' => '2026-10-01']);
});

it('orders.GET-Liste zeigt Zahlungsziel und Fälligkeit des Lieferanten', function () {
    $zeile = app(OrdersGetTool::class)->execute([], new ToolContext($this->user, $this->rootTeam))->data['orders'][0];

    expect($zeile)->toMatchArray(['id' => $this->order->id, 'payment_term_days' => 14, 'invoice_due_date' => '2026-10-15']);
});

it('Bestell-Browser zeigt dieselbe Fälligkeit', function () {
    $zeile = Livewire::test(OrdersIndex::class)->viewData('liste')->firstWhere('id', $this->order->id);

    expect($zeile)->toMatchArray(['payment_term_days' => 14, 'invoice_due_date' => '2026-10-15']);
});
