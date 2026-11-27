<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.5 Schritt 6a, Gruppe Ausgabe/Produktion: Leser der Spiegelfelder am Rezept lesen die Standard-Darreichung.
 *
 * Jeder Fall setzt den Spiegel am Rezept absichtlich ABWEICHEND (direkt in der Tabelle, an jeder Synchronisierung vorbei):
 * Liest eine Stelle noch den Spiegel, fällt das hier auf. Nach 6c gibt es die Spalten nicht mehr; dann fällt der
 * Spiegel-Teil der Vorbereitung weg, die Erwartungen bleiben.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $this->svc = app(SpeiseplanService::class);
    $this->teller = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'teller', 'label' => 'Teller']);
    $this->kind = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'kinderportion', 'label' => 'Kinderportion']);

    $this->gericht = function (string $key, ?float $standardG, float $spiegelG) {
        $r = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => ucfirst($key),
            'status' => 'approved', 'is_sales_recipe' => true, 'ek_total_eur' => 10.0, 'yield_kg' => 2.0]);
        FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'serving_form_id' => $this->teller->id,
            'is_standard' => true, 'quantity_per_unit_g' => $standardG, 'unit_count' => 1, 'ek_portion' => 2.0, 'sales_net' => 5.0, 'price_mode' => 'auto']);
        // 98 · 3.5 6c: Spiegel-Teil der Vorbereitung entfällt (Spalten gedroppt), die Erwartungen bleiben

        return $r->fresh();
    };
    $this->plan = $this->svc->create($this->rootTeam, ['name' => 'KW 49', 'zyklus_wochen' => 1, 'start_date' => '2026-11-30']);
    $this->mo = '2026-11-30';
    $this->portionG = fn ($e) => $this->svc->zellenKennzahlen($this->rootTeam, $this->plan->fresh(), 'mittag',
        \Illuminate\Support\Carbon::parse($this->mo))['eintraege'][$e->id]['portion_g'];
});

it('Speiseplan-Zelle: gewählte Form ohne Grammatur fällt auf die Standard-Darreichung zurück, nicht auf den Rezept-Spiegel', function () {
    $g = ($this->gericht)('ragout', 250.0, 999.0);
    $kinder = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $g->id, 'serving_form_id' => $this->kind->id,
        'is_standard' => false, 'quantity_per_unit_g' => null, 'unit_count' => 1, 'ek_portion' => 1.0, 'sales_net' => 3.0, 'price_mode' => 'auto']);
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'mahlzeit' => 'mittag', 'recipe_id' => $g->id]);
    $this->svc->setEintragDarreichung($this->rootTeam, $e->id, $kinder->id);

    expect(($this->portionG)($e))->toBe(250.0);   // vorher 999 (Spiegel)
});

it('Speiseplan-Zelle: Konzept-Eintrag summiert die Standard-Grammaturen seiner Gerichte, nicht die Spiegel', function () {
    $a = ($this->gericht)('vorspeise', 100.0, 999.0);
    $b = ($this->gericht)('hauptgang', 200.0, 999.0);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Menü']);
    foreach ([$a, $b] as $i => $r) {
        FoodAlchemistConceptSlot::create(['team_id' => $this->rootTeam->id, 'concept_id' => $c->id, 'type' => 'gericht',
            'recipe_id' => $r->id, 'position' => $i]);
    }
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'mahlzeit' => 'mittag', 'concept_id' => $c->id]);

    expect(($this->portionG)($e))->toBe(300.0);   // vorher 1998 (Spiegel)
});
