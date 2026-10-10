<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\LaCandidateFinder;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Lauf 88 (demo, 10.10., Rezept 3806 „Jus: Kalb"): „Lauch: frisch, ganz" blieb ohne GP.
 *  1. Der GP-Vorfilter LIKEte auch „frisch"/„ganz" → tausende Treffer, orderBy(id)->limit(300) schnitt die Lauch-GPs ab.
 *  2. Der Mint nahm nur Platz 1 der LA-Shortlist („Lauchzwiebeln frisch", zu Recht abgelehnt) — „Lauch geputzt" auf
 *     Platz 3 kam nie dran. In Lauf 87 hatte genau dieser Mint „Lauch: frisch" aus Lauchzwiebeln angelegt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->la = fn (string $d) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id, 'designation' => $d, 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
});

it('GP-Pool: Zustandswörter blähen den Vorfilter nicht auf — das Lauch-GP mit hoher ID wird gefunden', function () {
    $zeilen = [];
    for ($i = 1; $i <= 320; $i++) {
        $zeilen[] = ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'gp_key' => 'fuell|frisch-' . $i, 'name' => 'Fuellgemuese ' . $i . ': frisch, ganz',
            'status' => 'approved', 'is_platzhalter' => false, 'created_at' => now(), 'updated_at' => now()];
    }
    FoodAlchemistGp::insert($zeilen);
    $lauch = $this->makeGp($this->rootTeam, 'Lauch: frisch');   // höchste ID

    $m = app(IngredientMatchService::class)->matchIngredient($this->rootTeam, 'Lauch: frisch, ganz');

    expect($m['target'])->toBe('gp')->and($m['gp_id'])->toBe($lauch->id);
});

it('Mint-Auswahl: bester Kandidat mit passendem Grundwort nach Relevanz, nicht nur Platz 1', function () {
    $zwiebel = ($this->la)('Lauchzwiebeln frisch');
    $sprossen = ($this->la)('LAUCH SPROSSEN');
    $geputzt = ($this->la)('Lauch geputzt');
    foreach ([[$zwiebel, 0.709], [$sprossen, 0.672], [$geputzt, 0.737]] as [$la, $score]) {
        $la->setAttribute('score', $score);
    }
    $finder = Mockery::mock(LaCandidateFinder::class, [
        app(\Platform\FoodAlchemist\Services\Matching\TokenEngine::class),
        app(\Platform\FoodAlchemist\Services\Matching\MatchHeuristics::class),
        app(\Platform\FoodAlchemist\Services\TerminologyService::class),
        app(\Platform\FoodAlchemist\Services\StammLieferantService::class),
        app(\Platform\FoodAlchemist\Services\SupplierItemService::class),
        app(\Platform\FoodAlchemist\Services\LeadLaStrategieResolver::class),
    ])->makePartial();
    $finder->shouldReceive('find')->andReturn(collect([$zwiebel, $sprossen, $geputzt]));   // Reihenfolge wie demo (Einkauf)

    expect($finder->bestMitGrundwort($this->rootTeam, 'Lauch: frisch, ganz')?->id)->toBe($geputzt->id);
});

it('Grundwort: Synonyme Lauch↔Porree und Rote Bete↔Rote Rübe, Lauchzwiebel ist kein Lauch', function () {
    $f = app(LaCandidateFinder::class);

    expect($f->grundwortPasst('Lauch: frisch, ganz', 'Porree frisch'))->toBeTrue()
        ->and($f->grundwortPasst('Porree', 'Lauch geputzt'))->toBeTrue()
        ->and($f->grundwortPasst('Rote Bete: frisch', 'Rote Rübe roh'))->toBeTrue()
        ->and($f->grundwortPasst('Rote Rübe', 'Rote Bete vakuumiert'))->toBeTrue()
        ->and($f->grundwortPasst('Lauch: frisch, ganz', 'Lauchzwiebeln frisch'))->toBeFalse();
});
