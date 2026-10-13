<?php

use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * „0 € ist kein Preis": Ein Lead-Artikel OHNE aktiven Preis ist eine Lücke. Vorher gab der €/g-Pfad
 * (float) NULL = 0.0 an preisProGramm weiter, und die Zeile zählte als „bepreist mit 0 €" — selbst wenn ein
 * anderer Artikel des GP einen Preis hatte. Der Stk-Zwilling prüfte aktiver_preis !== null schon.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Großhandel']);
    $this->la = fn (string $name) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->sup->id, 'designation' => $name, 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
    $this->gpMitLead = function (string $name, FoodAlchemistSupplierItem ...$las) {
        $gp = $this->makeGp($this->rootTeam, $name);
        foreach ($las as $la) {
            FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        }
        $gp->update(['lead_la_supplier_item_id' => $las[0]->id]);   // erster = Lead

        return $gp->refresh();
    };
    $this->rc = app(RecipeRecomputeService::class);
});

it('Lead ohne Preis, aber ein anderer Artikel des GP ist bepreist → dessen Preis zählt (Durchschnitt), nicht 0 €', function () {
    $leadOhne = ($this->la)('Kalbsfond Lead (ohne Preis)');
    $mitPreis = ($this->la)('Kalbsfond B');
    app(PriceService::class)->createFor($this->rootTeam, $mitPreis, 15.84);
    $gp = ($this->gpMitLead)('Kalbsfond', $leadOhne, $mitPreis);

    $r = $this->makeRecipe($this->rootTeam, 'Sauce');
    $this->makeIngredient($r, 'Kalbsfond', $gp, '1000');
    $this->rc->recomputePipeline($r->id);

    expect((float) $r->fresh()->ek_total_eur)->toBe(15.84)
        ->and($r->fresh()->ek_price_basis?->value)->toBe('avg')
        ->and((int) $r->fresh()->ek_n_ingredients_priced)->toBe(1);
});

it('Lead ohne Preis und kein anderer bepreister Artikel → Zeile ist NICHT bepreist (Lücke), kein 0-€-Preis', function () {
    $gp = ($this->gpMitLead)('Trüffel', ($this->la)('Trüffel (ohne Preis)'));
    $r = $this->makeRecipe($this->rootTeam, 'Trüffelbutter');
    $this->makeIngredient($r, 'Trüffel', $gp, '50');
    $this->rc->recomputePipeline($r->id);

    expect((int) $r->fresh()->ek_n_ingredients_priced)->toBe(0)
        ->and((int) $r->fresh()->ek_n_ingredients_total)->toBe(1)
        ->and($r->fresh()->ek_total_eur)->toBeNull()
        ->and($this->rc->zeilenKosten($r->fresh(), $this->rootTeam))->toBe([$r->ingredients()->value('id') => null]);
});

it('Lead mit Preis bleibt unverändert der Preis (keine Wirkung auf den Normalfall)', function () {
    $lead = ($this->la)('Butter Lead');
    $b = ($this->la)('Butter B');
    app(PriceService::class)->createFor($this->rootTeam, $lead, 10.00);
    app(PriceService::class)->createFor($this->rootTeam, $b, 30.00);
    $gp = ($this->gpMitLead)('Butter', $lead, $b);
    $r = $this->makeRecipe($this->rootTeam, 'Beurre blanc');
    $this->makeIngredient($r, 'Butter', $gp, '100');
    $this->rc->recomputePipeline($r->id);

    expect((float) $r->fresh()->ek_total_eur)->toBe(1.00)->and($r->fresh()->ek_price_basis?->value)->toBe('lead');
});
