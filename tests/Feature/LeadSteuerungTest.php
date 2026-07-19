<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGpLaPreference;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\SupplierService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R9.2 — Lead-Lieferant-Steuerung: manueller Override (+ Begründung + Historie),
 * Recompute mit neuem Lead-EK, Ausweichquellen aus der Rangliste, Volumen-Proxy.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->lead = app(LeadLaService::class);
    $this->recipeSvc = app(RecipeService::class);
    $this->g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);

    $this->gp = $this->makeGp($this->rootTeam, 'Zwiebel');

    // Zwei LAs mit Struktur + Preis: A günstig (Aldi 1 €/kg), B teuer (Baldur 2 €/kg).
    $mkLa = function (string $supplierName, float $preis) {
        $sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => $supplierName]);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id,
            'designation' => 'Zwiebel ' . $supplierName, 'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $this->gp->id]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);

        return $la;
    };
    $this->laA = $mkLa('Aldi', 1.00);
    $this->laB = $mkLa('Baldur', 2.00);
    $this->gp->update(['n_las_total' => 2, 'lead_la_supplier_item_id' => $this->laA->id]);

    // Rezept nutzt das GP (1 kg) → EK über Lead-LA.
    $this->rezept = $this->recipeSvc->create($this->rootTeam, ['name' => 'Fond: Zwiebel']);
    $this->recipeSvc->syncIngredients($this->rootTeam, $this->rezept->id, [
        ['gp_id' => $this->gp->id, 'raw_text' => '1000 g Zwiebel', 'quantity' => '1000', 'unit_vocab_id' => $this->g->id],
    ]);
});

it('Override setzt Lead + Begründung, Recompute nutzt neuen Lead-EK, Historie fällt ab', function () {
    expect((float) $this->rezept->refresh()->ek_total_eur)->toBe(1.00);   // Lead A, 1 €/kg

    $this->lead->setLeadLa($this->rootTeam, $this->gp, $this->laB->id, 'bessere Liefertreue', recompute: true);

    expect((int) $this->gp->refresh()->lead_la_supplier_item_id)->toBe($this->laB->id)
        ->and((float) $this->rezept->refresh()->ek_total_eur)->toBe(2.00);  // Lead B, 2 €/kg

    // Begründung persistiert (Historie via LogsActivity auf der Pref-Zeile).
    $pref = FoodAlchemistGpLaPreference::where('team_id', $this->rootTeam->id)
        ->where('gp_id', $this->gp->id)->where('supplier_item_id', $this->laB->id)->first();
    expect($pref)->not->toBeNull()
        ->and($pref->reason)->toBe('bessere Liefertreue');

    // leadSteuerung spiegelt Override + Begründung.
    $st = $this->lead->leadSteuerung($this->gp->refresh(), $this->rootTeam);
    expect($st['lead_gesetzt_la_id'])->toBe($this->laB->id)
        ->and($st['override_reason'])->toBe('bessere Liefertreue');
});

it('leadSteuerung listet Ausweichquellen (Rangliste ab Rang 2)', function () {
    $st = $this->lead->leadSteuerung($this->gp, $this->rootTeam);

    // Günstigster (Aldi) ist Vorschlag/Lead-Kandidat, Baldur ist Ausweichquelle.
    expect($st['vorschlag_la_id'])->toBe($this->laA->id)
        ->and(collect($st['ausweichquellen'])->pluck('la_id')->all())->toContain($this->laB->id)
        ->and(collect($st['ausweichquellen'])->pluck('la_id')->all())->not->toContain($this->laA->id);
});

it('Volumen-Proxy zählt Nutzung je Lieferant über die Lead-LA', function () {
    // Lead ist LA-A (Aldi) → das Rezept zählt auf Aldi.
    $ranking = app(SupplierService::class)->volumenProxyRanking($this->rootTeam);
    $aldi = collect($ranking)->firstWhere('supplier_id', $this->laA->supplier_id);

    expect($aldi)->not->toBeNull()
        ->and($aldi['n_usages'])->toBeGreaterThanOrEqual(1)
        ->and($aldi['ist_proxy'])->toBeTrue();
});
