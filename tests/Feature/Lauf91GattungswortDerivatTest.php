<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\LaCandidateFinder;
use Platform\FoodAlchemist\Services\LaFirstGpService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Lauf 91 (demo, 10.10.):
 *  1. „Öl" (Jus: Rind (Grund)) → per Mint „Lauchoel Groenn: trocken" — ein 3-Zeichen-Wort war kein Produktwort, die
 *     Grundwort-Prüfung ließ jeden LA durch. Ein §5-Default für generisches Öl gibt es (noch) nicht → Lücke.
 *  2. „Grapefruitsaft" → Derivat „Grapefruitsaft: frisch, Zeste" (Kopf-Floor 0,9) statt „Grapefruitsaft: konserviert".
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->la = fn (string $d) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => $d, 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
});

it('grundwortPasst: kurzes Gattungswort verlangt dasselbe Wort, kein Aroma-Kompositum', function () {
    $f = app(LaCandidateFinder::class);

    expect($f->grundwortPasst('Öl', 'Lauchoel Groenn trocken'))->toBeFalse()
        ->and($f->grundwortPasst('Öl', 'Rapsöl raffiniert'))->toBeFalse()
        ->and($f->grundwortPasst('Öl', 'Öl neutral 10 l'))->toBeTrue()
        ->and($f->grundwortPasst('frisch', 'Lauchoel'))->toBeTrue();   // nur Zustandswort — wie bisher
});

it('Mint: „Öl" bekommt kein Aromaöl (Finder schlägt es vor wie auf demo)', function () {
    $lauchoel = ($this->la)('Lauchoel Groenn');
    $lauchoel->setAttribute('score', 0.8);
    $mock = Mockery::mock(LaCandidateFinder::class, [
        app(\Platform\FoodAlchemist\Services\Matching\TokenEngine::class),
        app(\Platform\FoodAlchemist\Services\Matching\MatchHeuristics::class),
        app(\Platform\FoodAlchemist\Services\TerminologyService::class),
        app(\Platform\FoodAlchemist\Services\StammLieferantService::class),
        app(\Platform\FoodAlchemist\Services\SupplierItemService::class),
        app(\Platform\FoodAlchemist\Services\LeadLaStrategieResolver::class),
    ])->makePartial();
    $mock->shouldReceive('find')->andReturn(collect([$lauchoel]));
    app()->instance(LaCandidateFinder::class, $mock);
    $vorher = FoodAlchemistGp::count();

    expect(app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'Öl'))->toBeNull()
        ->and(FoodAlchemistGp::count())->toBe($vorher);
});

it('Matcher: ein Derivat-GP nur, wenn die Zeile seine Form nennt', function () {
    $derivat = $this->makeGp($this->rootTeam, 'Grapefruitsaft: frisch, Zeste');
    $derivat->update(['is_derivat' => true]);
    $saft = $this->makeGp($this->rootTeam, 'Grapefruitsaft: konserviert');
    $m = fn (string $q) => app(IngredientMatchService::class)->matchIngredient($this->rootTeam, $q);

    expect($m('Grapefruitsaft')['gp_id'])->toBe($saft->id)
        ->and($m('Grapefruitsaft, Zeste')['gp_id'])->toBe($derivat->id);
});

it('Hausstandard Jus/Fond: jeder Verkaufs-Cut aus WG 04 warnt, Knochen/Parüren/Karkasse/Flügel/Ochsenschwanz nicht (Rezept 3829)', function () {
    $regel = \Platform\FoodAlchemist\Models\FoodAlchemistRule::where('schluessel', 'basisrezept.hausstandard.fond_knochen')->firstOrFail();
    $wg = function (string $name, string $code = '04') { $gp = $this->makeGp($this->rootTeam, $name); $gp->update(['commodity_group_code' => $code]); return $gp; };
    $jus = $this->makeRecipe($this->rootTeam, 'Jus: Rind (Grund)', ['status' => 'draft']);
    $pos = 1;
    foreach (['Rinderhueften: frisch, pariert', 'Rinderknochen: frisch', 'Kalb: frisch, Parüren', 'Geflügel: frisch, Karkasse',
        'Hühnerflügel: frisch', 'Ochsenschwanz: frisch'] as $name) {
        $this->makeIngredient($jus, $name, $wg($name), '1000', $pos++);
    }
    $this->makeIngredient($jus, 'Zwiebeln: frisch, ganz', $wg('Zwiebeln: frisch, ganz', '01'), '500', $pos++);
    $befunde = collect(app(\Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter::class)
        ->deterministischeBefunde($this->rootTeam, (int) $jus->id))->where('rule_id', $regel->id)->values();

    expect($befunde->pluck('feld')->all())->toBe(['zutat:Rinderhueften: frisch, pariert']);
});

it('Hausstandard: der Regel-Block nennt „nur mit …" statt einer Verbotsliste', function () {
    $block = app(\Platform\FoodAlchemist\Services\Regeln\RegelPromptBlock::class)->fuerPromptKey('recipe.generator');

    expect($block)->toContain('nur mit knochen, karkasse, abschnitt')
        ->toContain('Falsch z. B.: „Rinderhueften: frisch, pariert“');
});
