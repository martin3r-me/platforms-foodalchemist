<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\LaCandidateFinder;
use Platform\FoodAlchemist\Services\LaFirstGpService;
use Platform\FoodAlchemist\Services\RecipeExtractService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 95 Stufe 2 · Doktrin LA-First (Dominique 03.11.: „die GP entstehen aus den Lieferantenartikeln“): ein GP entsteht nur aus einem
 * WIRKLICH passenden Artikel — sonst kein GP, die Zutat bleibt offen. Pilot KOCHBuch_2026: Mondamin → „Grießbrei Mondamin“, Ingwerpüree →
 * „WOK Ginger & Lime“, Ei hartgekocht → „Gepocheerd Scharrel Ei“.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs Culinar']);
    $this->la = fn (string $designation, array $struktur = [], ?string $brand = null) => tap(FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id, 'designation' => $designation, 'qty' => 1.0, 'unit_code' => 'kg',
        'brand' => $brand,
    ]), function ($la) use ($struktur) {
        if ($struktur !== []) {
            FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id] + $struktur);
        }
    });
    $this->f = fn () => app(LaCandidateFinder::class);
    // Der Finder schlägt genau diesen Artikel vor (wie auf io per Embedding) — geprüft wird die Annahme, nicht die Suche.
    $this->finderSchlaegtVor = function (FoodAlchemistSupplierItem $la) {
        $mock = Mockery::mock(LaCandidateFinder::class, [
            app(\Platform\FoodAlchemist\Services\Matching\TokenEngine::class), app(\Platform\FoodAlchemist\Services\Matching\MatchHeuristics::class),
            app(\Platform\FoodAlchemist\Services\TerminologyService::class), app(\Platform\FoodAlchemist\Services\StammLieferantService::class),
            app(\Platform\FoodAlchemist\Services\SupplierItemService::class), app(\Platform\FoodAlchemist\Services\LeadLaStrategieResolver::class),
        ])->makePartial();
        $la->setAttribute('score', 0.8);
        $mock->shouldReceive('find')->andReturn(collect([$la]));
        app()->instance(LaCandidateFinder::class, $mock);
    };
});

it('Regel 2: Zutat-Grundwort = erstes Produktwort nach Abzug der Marke — Marke vorn trifft, Marke hinten als Zutat nicht', function () {
    expect(($this->f)()->passtFuerMint('Frischkäse', ($this->la)('Buko Frischkäse Natur 200 g', [], 'Buko')))->toBeTrue()
        ->and(($this->f)()->passtFuerMint('Frischkäse', ($this->la)('200G Frischkäse Natur')))->toBeTrue()
        ->and(($this->f)()->passtFuerMint('Rinderfilet', ($this->la)('Filet vom Rind')))->toBeTrue()
        // Gegenproben: das Zutatwort steht nur hinten bzw. als Teil eines anderen Produkts
        ->and(($this->f)()->passtFuerMint('Mondamin', ($this->la)('Grießbrei 2-3 P. Mondamin')))->toBeFalse()
        ->and(($this->f)()->passtFuerMint('Ingwerpüree', ($this->la)('WOK Ginger & Lime, Ingwerpüree mit Limettenblatt')))->toBeFalse()
        ->and(($this->f)()->passtFuerMint('Frischkäse', ($this->la)('Buko Frischkäse Natur 200 g')))->toBeFalse();   // Marke unbekannt → Kopf „buko“
});

it('Regel 3: Zubereitung im Artikel (gegart, pochiert), die die Zutat nicht nennt, ist ein Widerspruch — genannt nicht', function () {
    $pochiert = ($this->la)('Ei Scharrel', ['processing' => 'pochiert', 'main_ingredient_slug' => 'ei']);
    $roh = ($this->la)('Ei Bodenhaltung M', ['processing' => null, 'main_ingredient_slug' => 'ei']);
    $geschaelt = ($this->la)('Knoblauch geschält', ['processing' => 'geschält', 'main_ingredient_slug' => 'knoblauch']);

    expect(($this->f)()->passtFuerMint('Ei', $pochiert))->toBeFalse()
        ->and(($this->f)()->passtFuerMint('Ei, pochiert', $pochiert))->toBeTrue()
        ->and(($this->f)()->passtFuerMint('Ei', $roh))->toBeTrue()
        ->and(($this->f)()->passtFuerMint('Knoblauch', $geschaelt))->toBeTrue()   // Rüstarbeit, keine Zubereitung
        ->and(($this->f)()->passtFuerMint('Ei', ($this->la)('Gepocheerd Scharrel Ei', ['processing' => 'pochiert'])))->toBeFalse();
});

it('Regel 2: Zustand und Geschmack der Struktur dürfen der Zutat nicht widersprechen', function () {
    $limette = ($this->la)('Ingwerpüree Limette', ['main_ingredient_slug' => 'ingwer', 'flavor_ingredient_slugs' => json_encode(['ingwer', 'limette'])]);
    $tk = ($this->la)('Erbsen fein', ['condition' => 'TK', 'main_ingredient_slug' => 'erbse']);

    expect(($this->f)()->passtFuerMint('Ingwerpüree', $limette))->toBeFalse()
        ->and(($this->f)()->passtFuerMint('Ingwerpüree mit Limette', $limette))->toBeTrue()
        ->and(($this->f)()->passtFuerMint('Erbsen, frisch', $tk))->toBeFalse()
        ->and(($this->f)()->passtFuerMint('Erbsen', $tk))->toBeTrue();
});

it('Regel 1 · MINT_FROM_LA und mintFromLa: kein passender Artikel → kein GP, die Zutat bleibt offen', function () {
    ($this->finderSchlaegtVor)(($this->la)('Grießbrei 2-3 P. Mondamin'));
    $vorher = FoodAlchemistGp::count();

    $gp = app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'Mondamin');
    $res = app(ToolRegistry::class)->get('foodalchemist.gps.MINT_FROM_LA')->execute(['zutat' => 'Mondamin'], new ToolContext($this->user, $this->rootTeam));

    expect($gp)->toBeNull()
        ->and($res->data['gp_id'] ?? $res->data['gp']['id'] ?? null)->toBeNull()
        ->and(FoodAlchemistGp::count())->toBe($vorher);
});

it('Regel 1 · Gegenprobe: ein passender Artikel mintet weiter ein GP', function () {
    ($this->finderSchlaegtVor)(($this->la)('Rinderbeinscheibe frisch'));

    $gp = app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'Rinderbeinscheibe');

    expect($gp)->not->toBeNull();
});

it('Regel 4 · ENRICH-Mint: der Anker läuft nicht mehr synchron im Lauf', function () {
    ($this->finderSchlaegtVor)(($this->la)('Rinderbeinscheibe frisch'));
    $recipe = $this->makeRecipe($this->rootTeam, 'Rinderbrühe');
    $unit = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $recipe->id, 'raw_text' => 'Rinderbeinscheibe',
        'quantity' => '500', 'unit_vocab_id' => $unit->id, 'position' => 1]);
    $bulk = Mockery::mock(\Platform\FoodAlchemist\Services\BulkEnrichService::class)->makePartial();
    $bulk->shouldNotReceive('ankerNachziehen');
    app()->instance(\Platform\FoodAlchemist\Services\BulkEnrichService::class, $bulk);

    $r = app(\Platform\FoodAlchemist\Services\RecipeOneShotService::class)->minteFehlendeGps($this->rootTeam, $recipe->fresh());

    expect($r['minted'])->toBe(1)
        ->and($r['anker'])->toHaveKey('nachgelagert');
});

it('Regel 5 · EXTRACT gibt der KI die Bestands-Kandidaten mit, der Text bleibt wörtlich', function () {
    FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => 'Frischkäse: frisch, Natur', 'gp_key' => 's95b-1||', 'status' => 'approved',
        'commodity_group_code' => '06', 'condition' => 'frisch', 'main_ingredient_slug' => 'frischkaese']);
    $gesehen = null;
    $this->mock(AiGatewayService::class, function ($m) use (&$gesehen) {   // keine Arrow-Fn: sie fängt $gesehen als Kopie
        $m->shouldReceive('propose')->once()
            ->withArgs(function (string $key, array $ctx) use (&$gesehen) {
                $gesehen = $ctx;

                return $key === 'recipe.extract';
            })
            ->andReturn(new AiProposal(['typ' => 'basisrezept', 'name' => 'Aufstrich', 'zutaten' => [['text' => 'Frischkäse', 'quantity' => '500', 'unit' => 'g']],
                'preparation' => 'Rühren.', 'komponenten' => []], 0.9, 'Mock', [], 'extract'));
    });

    app(RecipeExtractService::class)->extrahiere($this->rootTeam, "Aufstrich\n500 g Frischkäse\nRühren.");

    expect(array_column($gesehen['gp_kandidaten']['treffer'] ?? [], 'name'))->toContain('Frischkäse: frisch, Natur')
        ->and($gesehen['gp_kandidaten']['hinweis'])->toContain('wörtlich')
        ->and($gesehen['roh_text'])->toContain('500 g Frischkäse');
});
