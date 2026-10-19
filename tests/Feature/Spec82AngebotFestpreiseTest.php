<?php

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\AngebotEinfrierService;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\EinfrierKosten;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Support\AngebotFestpreise;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Eingefrorenes Angebot = feste Preise (Spec 82 §12 B3, Dominique 19.10.): alle Gerichte beim Versand eingefroren (VK je Form, EK,
 * Aufschlagsklasse); Einzelgericht- und Paket-Positionen lesen den eingefrorenen VK, ohne VK eingefroren bleibt der Preis eine Lücke;
 * nach dem Verwerfen gilt wieder live.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->koch = $this->makeUser($this->rootTeam, 'Koch', 'owner');
    $this->actingAs($this->koch);
    $teller = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'teller', 'label' => 'Teller']);
    $buffet = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'buffet', 'label' => 'Buffet']);
    $this->gericht = $this->makeRecipe($this->rootTeam, 'Rinderfilet', ['is_sales_recipe' => true, 'sales_unit_count' => 1]);
    $this->std = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $teller->id,
        'is_standard' => true, 'unit_count' => 1, 'price_mode' => 'fixed', 'sales_net' => 20.0, 'price_override_reason' => 'Test', 'created_via' => 'test']);
    $this->buffetForm = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $buffet->id,
        'is_standard' => false, 'unit_count' => 1, 'price_mode' => 'fixed', 'sales_net' => 15.0, 'price_override_reason' => 'Test', 'created_via' => 'test']);

    $this->angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Gala', 'personen' => 10]);
    $this->kapitel = app(OfferCompositionService::class)->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Hauptgang']);
    $this->block = $this->kapitel->blocks()->create(['team_id' => $this->rootTeam->id, 'type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'position' => 1, 'visible' => true]);
    $this->vk = fn () => app(OfferCompositionService::class)->blockPreis($this->block->fresh(), null, $this->rootTeam);

    // Kostenschicht festlegen (eigenes Gericht, EK vollständig) — die Einfrierung soll ihr Format nicht von ihr abhängig machen
    $this->kosten = function (bool $vollstaendig) {
        app()->instance(EinfrierKosten::class, new class($vollstaendig) extends EinfrierKosten {
            public function __construct(private bool $voll) {}

            public function fuer(FoodAlchemistRecipe $gericht, Team $team): array
            {
                return ['ek_summe' => 6.0, 'ek_per_kg' => 30.0, 'n_priced' => 2, 'n_total' => 2, 'vollstaendig' => $this->voll, 'grund' => $this->voll ? null : 'zutaten_ohne_preis',
                    'fehlende' => [], 'vk' => $this->voll ? 20.0 : null, 'berechnet_at' => now()->toIso8601String()];
            }
        });
    };
    $this->svc = fn () => app()->make(AngebotEinfrierService::class);
});

it('nach dem Einfrieren ändert ein neuer VK das Angebot nicht; nach dem Verwerfen gilt wieder live', function () {
    ($this->kosten)(true);
    $e = ($this->svc)()->einfrieren($this->rootTeam, $this->koch, $this->angebot);

    $z = $e->zeilen->first();
    expect($z->presentation_id)->toBe((int) $this->std->id)
        ->and(array_map('floatval', $z->vk_je_form))->toBe([(int) $this->std->id => 20.0, (int) $this->buffetForm->id => 15.0]);

    $this->std->forceFill(['sales_net' => 28.0])->save();   // Preis steigt nach dem Versand
    app()->forgetScopedInstances();
    expect(($this->vk)()['vk_pp'])->toBe(20.0)->and(($this->vk)()['eingefroren'] ?? false)->toBeTrue();

    ($this->svc)()->verwerfen($this->rootTeam, $this->koch, $this->angebot);
    app()->forgetScopedInstances();
    expect(($this->vk)()['vk_pp'])->toBe(28.0);
});

it('eingefroren ohne VK (EK unvollständig): der Preis bleibt eine Lücke, kein Rückfall auf live', function () {
    ($this->kosten)(false);
    ($this->svc)()->einfrieren($this->rootTeam, $this->koch, $this->angebot);
    app()->forgetScopedInstances();

    $p = ($this->vk)();
    expect($p['vk_fehlt'])->toBeTrue()->and($p['vk_pp'])->toBe(0.0)->and($p['eingefroren'] ?? false)->toBeTrue();
});

it('Paket-Position: der Slot mit Buffet-Form zeigt den eingefrorenen Buffet-VK', function () {
    ($this->kosten)(true);
    $concept = $this->makeConcept($this->rootTeam, 'Buffet-Menü');
    $slot = $this->makeConceptSlot($concept, ['sales_recipe_id' => $this->gericht->id, 'quantity' => 1]);
    $slot->forceFill(['presentation_id' => $this->buffetForm->id])->save();
    $paket = $this->kapitel->blocks()->create(['team_id' => $this->rootTeam->id, 'type' => 'concept_ref', 'concept_id' => $concept->id, 'position' => 2, 'visible' => true]);
    ($this->svc)()->einfrieren($this->rootTeam, $this->koch, $this->angebot);
    $this->buffetForm->forceFill(['sales_net' => 19.0])->save();
    app()->forgetScopedInstances();

    $preis = app(OfferCompositionService::class)->blockPreis($paket->fresh(), null, $this->rootTeam);
    $live = app(ConceptService::class)->preisCockpit($concept->fresh(), null, $this->rootTeam);   // ohne Angebots-Kontext: live

    expect($preis['vk_pp'])->toBe(15.0)->and((float) $live['price_per_person'])->toBe(19.0);
});

it('Kontext: ohne Einfrierung keine Festpreise, gericht() unterscheidet Form und Standard', function () {
    $fest = app(AngebotFestpreise::class);
    expect($fest->fuerAngebot((int) $this->angebot->id))->toBeNull();

    $map = [7 => ['vk' => 10.0, 'ek_summe' => 3.0, 'vollstaendig' => true, 'je_form' => [70 => 8.0, 71 => null]]];
    expect($fest->gericht(7, 70, $map)['vk'])->toBe(8.0)
        ->and($fest->gericht(7, 71, $map)['vk'])->toBeNull()
        ->and($fest->gericht(7, 99, $map)['vk'])->toBe(10.0)
        ->and($fest->gericht(8, null, $map))->toBeNull();
});
