<?php

use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\MatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M3-08: MatchService v1 — exact (EAN/artno-Dubletten) + fuzzy (GL-04-Kern)
 * für LA-Verknüpfen-Vorschläge.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(MatchService::class);
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'BOS Food']);

    $this->mkLa = function (string $bez, array $extra = [], $gpZuordnung = null) {
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
            'designation' => $bez, ...$extra,
        ]);
        FoodAlchemistSupplierItemStructure::create([
            'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gpZuordnung?->id,
        ]);

        return $la;
    };
});

it('exact: identische EAN eines gemappten LAs liefert dessen GP mit Score 1.0', function () {
    $gp = $this->makeGp($this->rootTeam, 'Limettensaft');
    ($this->mkLa)('Limettensaft Profi 1l', ['ean_packaging' => '4001234567890'], $gp);
    $unmapped = ($this->mkLa)('Limettensaft EW 0,75l', ['ean_ordering' => '4001234567890']);

    $v = $this->svc->vorschlaegeFuerLa($unmapped, $this->rootTeam);

    expect($v)->toHaveCount(1)
        ->and($v[0]['gp']->id)->toBe($gp->id)
        ->and($v[0]['score'])->toBe(1.0)
        ->and($v[0]['band'])->toBe(MatchBand::Exact)
        ->and($v[0]['methode'])->toBe('exact_ean');
});

it('exact: gleiche article_number beim selben Lieferanten (Dublette) liefert das GP', function () {
    $gp = $this->makeGp($this->rootTeam, 'Agar Agar');
    ($this->mkLa)('Agar Agar 500 g', ['article_number' => '23306'], $gp);
    $unmapped = ($this->mkLa)('Agar Agar, E 406, 500 g', ['article_number' => '23306']);

    $v = $this->svc->vorschlaegeFuerLa($unmapped, $this->rootTeam);

    expect($v[0]['methode'])->toBe('exact_artno')
        ->and($v[0]['gp']->id)->toBe($gp->id);
});

it('fuzzy: Name-Containment-Floor hebt Kopf==Query auf 0.90 (Band exact); Schwellen filtern', function () {
    $treffer = $this->makeGp($this->rootTeam, 'Limettensaft: konserviert');
    $treffer->update(['hauptzutat_slug' => 'limettensaft']);
    $fremd = $this->makeGp($this->rootTeam, 'Weizenmehl: trocken, Type 1050');
    $fremd->update(['hauptzutat_slug' => 'weizenmehl']);

    $unmapped = ($this->mkLa)('Limettensaft');

    $v = $this->svc->vorschlaegeFuerLa($unmapped, $this->rootTeam);

    expect($v)->toHaveCount(1)                                  // Weizenmehl < 0.50 fliegt raus
        ->and($v[0]['gp']->id)->toBe($treffer->id)
        ->and($v[0]['score'])->toBeGreaterThanOrEqual(0.90)
        ->and($v[0]['band'])->toBe(MatchBand::Exact)
        ->and($v[0]['methode'])->toBe('fuzzy_name');
});

it('fuzzy respektiert D1-Sichtbarkeit: Kind-eigene GPs erscheinen Root nicht', function () {
    $kindGp = $this->makeGp($this->childA, 'Limettensaft: konserviert');
    $unmapped = ($this->mkLa)('Limettensaft');

    expect($this->svc->vorschlaegeFuerLa($unmapped, $this->rootTeam))->toBeEmpty()
        ->and($this->svc->vorschlaegeFuerLa($unmapped, $this->childA)->first()['gp']->id)->toBe($kindGp->id);
});

it('exact gewinnt vor fuzzy und dedupliziert das GP', function () {
    $gp = $this->makeGp($this->rootTeam, 'Limettensaft: konserviert');
    ($this->mkLa)('Limettensaft alt', ['ean_packaging' => '400999'], $gp);
    $unmapped = ($this->mkLa)('Limettensaft', ['ean_ordering' => '400999']);

    $v = $this->svc->vorschlaegeFuerLa($unmapped, $this->rootTeam);

    expect($v)->toHaveCount(1)
        ->and($v[0]['methode'])->toBe('exact_ean');             // nicht zusätzlich als fuzzy_name
});
