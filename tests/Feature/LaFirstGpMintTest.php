<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\LaFirstGpService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/**
 * 07·M1 — LA-First-GP-Mint als geteilte Fähigkeit (aus dem Generator befreit).
 * Doktrin: kein GP ohne LA; Mint = tentative + LA-verknüpft; keine LA → kein GP.
 */
uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(LaFirstGpService::class);
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);

    $this->mkLa = fn (string $designation) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'designation' => $designation, 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
});

it('mintet ein tentatives, LA-verknüpftes GP für eine Lücke mit passender LA', function () {
    $la = ($this->mkLa)('Sesampaste');

    $gp = $this->svc->mintFromLa($this->rootTeam, 'Sesampaste');

    expect($gp)->toBeInstanceOf(FoodAlchemistGp::class)
        ->and($gp->status->value)->toBe('tentative')      // ReviewQueue-Quarantäne, Freigabe bleibt menschlich
        ->and($gp->team_id)->toBe($this->rootTeam->id)
        ->and($gp->requires_la)->toBeTrue();

    // LA ist verknüpft (Struktur-Zeile LA→GP) → Anreicherung fließt LA-abgeleitet.
    $struktur = FoodAlchemistSupplierItemStructure::where('supplier_item_id', $la->id)->first();
    expect($struktur)->not->toBeNull()
        ->and($struktur->gp_id)->toBe($gp->id);
});

it('verwendet ein bereits gemapptes GP wieder statt neu zu minten', function () {
    $bestand = $this->makeGp($this->rootTeam, 'Sesampaste: geröstet');
    $la = ($this->mkLa)('Sesampaste');
    FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $bestand->id,
    ]);

    $vorher = FoodAlchemistGp::count();
    $gp = $this->svc->mintFromLa($this->rootTeam, 'Sesampaste');

    expect($gp->id)->toBe($bestand->id)
        ->and(FoodAlchemistGp::count())->toBe($vorher);   // kein Neu-Anlegen
});

it('keine passende LA → KEIN GP (Doktrin), sondern null', function () {
    $vorher = FoodAlchemistGp::count();

    $gp = $this->svc->mintFromLa($this->rootTeam, 'Marsianische Nichtzutat');

    expect($gp)->toBeNull()
        ->and(FoodAlchemistGp::count())->toBe($vorher);
});

it('Generator-Integration: Lücke mit LA erhöht gp_neu_aus_la auf 1', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    foreach ([
        ['slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1],
    ] as $e) {
        FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, ...$e]);
    }
    ($this->mkLa)('Tahin');   // LA vorhanden, aber noch kein GP → Generator mintet LA-First

    $resultat = app(RecipeGeneratorService::class)->generiere(
        $this->rootTeam, 'Sesam-Dip', [], kiRezeptOverride: [
            'name' => 'Dip: Sesam',
            'zutaten' => [['text' => 'Tahin', 'quantity' => 100, 'unit' => 'g']],
        ],
    );

    expect($resultat['statistik']['gp_neu_aus_la'])->toBe(1)
        ->and($resultat['statistik']['offen'])->toBe(0);

    $zeile = $resultat['recipe']->ingredients()->first();
    expect($zeile->gp_id)->not->toBeNull()
        ->and(FoodAlchemistGp::find($zeile->gp_id)->status->value)->toBe('tentative');
});
