<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\LaCandidateFinder;
use Platform\FoodAlchemist\Services\LaFirstGpService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Lauf 87 (demo, 10.10., Rezept 3798): „Rinderbeinscheiben: frisch" → GP „Rinderknochen: frisch" über den LA-First-
 * Mint. Der Finder nimmt den besten LA ohne Untergrenze (Embedding-Nähe, Wortanfang „Rind"). Ein automatischer Mint
 * braucht dasselbe Grundwort.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->mkLa = fn (string $designation) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'designation' => $designation, 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
    // Der Finder soll genau diesen LA vorschlagen (wie auf demo per Embedding) — geprüft wird die Annahme, nicht die Suche.
    $this->finderSchlaegtVor = function (FoodAlchemistSupplierItem $la) {
        $mock = Mockery::mock(LaCandidateFinder::class, [
            app(\Platform\FoodAlchemist\Services\Matching\TokenEngine::class),
            app(\Platform\FoodAlchemist\Services\Matching\MatchHeuristics::class),
            app(\Platform\FoodAlchemist\Services\TerminologyService::class),
            app(\Platform\FoodAlchemist\Services\StammLieferantService::class),
            app(\Platform\FoodAlchemist\Services\SupplierItemService::class),
            app(\Platform\FoodAlchemist\Services\LeadLaStrategieResolver::class),
        ])->makePartial();
        $la->setAttribute('score', 0.8);
        $mock->shouldReceive('find')->andReturn(collect([$la]));   // Mint wählt über bestMitGrundwort → find
        app()->instance(LaCandidateFinder::class, $mock);
    };
});

it('grundwortPasst: gleiches Grundwort ja, nur gleicher Wortanfang nein', function () {
    $f = app(LaCandidateFinder::class);

    expect($f->grundwortPasst('Rinderbeinscheiben: frisch', 'Rinderknochen frisch'))->toBeFalse()
        ->and($f->grundwortPasst('Rinderbeinscheiben: frisch', 'Rinderbeinscheibe 2 cm'))->toBeTrue()
        ->and($f->grundwortPasst('Rinderfilet', 'Filet vom Rind'))->toBeTrue()
        ->and($f->grundwortPasst('Zwiebeln', 'Zwiebel rot'))->toBeTrue()
        ->and($f->grundwortPasst('Sesampaste', 'Sesampaste'))->toBeTrue()
        ->and($f->grundwortPasst('frisch', 'Rinderknochen'))->toBeTrue();   // kein Produktwort → wie bisher
});

it('Mint: ein vorgeschlagener LA mit anderem Grundwort wird nicht genommen — auch nicht sein schon gemapptes GP', function () {
    $knochenGp = $this->makeGp($this->rootTeam, 'Rinderknochen: frisch');
    $la = ($this->mkLa)('Rinderknochen frisch');
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $knochenGp->id]);
    ($this->finderSchlaegtVor)($la);
    $vorher = FoodAlchemistGp::count();

    $gp = app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'Rinderbeinscheiben: frisch');

    expect($gp?->id)->not->toBe($knochenGp->id)
        ->and($gp)->toBeNull()                         // Lücke statt Knochen
        ->and(FoodAlchemistGp::count())->toBe($vorher);
});

it('Mint: passendes Grundwort wird weiter gemintet', function () {
    $la = ($this->mkLa)('Rinderbeinscheibe');
    ($this->finderSchlaegtVor)($la);

    $gp = app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'Rinderbeinscheiben: frisch');

    expect($gp)->toBeInstanceOf(FoodAlchemistGp::class)
        ->and(FoodAlchemistSupplierItemStructure::where('supplier_item_id', $la->id)->value('gp_id'))->toBe($gp->id);
});
