<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\LaFirstGpService;
use Platform\FoodAlchemist\Support\ZutatFreitext;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 95 · Rezeptimport (Pilot KOCHBuch_2026, 02.11.): EXTRACT reicht den Zutatentext treu durch („Crème fraîche (1 EL)“,
 * „Butter, Raumtemperatur“), GENERATE liefert saubere Namen. Der gemeinsame Resolver muss beides gleich behandeln:
 * Hinweis-Zusätze weg, Akzente gefaltet, Rohform vor Geschmacksvariante, Draw und Mint mit demselben Schutz.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->nr = 0;
    $this->gp = fn (string $name, string $wg, ?string $zustand, ?string $slug, ?string $zusatz = null) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 's95-' . (++$this->nr) . '||', 'status' => 'approved', 'requires_la' => true,
        'commodity_group_code' => $wg, 'condition' => $zustand, 'main_ingredient_slug' => $slug, 'zusatz' => $zusatz]);
    $this->match = fn (string $text) => app(IngredientMatchService::class)->matchIngredient($this->rootTeam, $text);
});

it('ohneHinweise: Menge, „optional“, „Raumtemperatur“ als ganzes Segment weg — Prozent, Type, Bestimmungswort und Stück bleiben', function (string $text, string $erwartet) {
    expect(ZutatFreitext::ohneHinweise($text))->toBe($erwartet);
})->with([
    ['Crème fraîche (1 EL)', 'Crème fraîche'],
    ['Butter, Raumtemperatur', 'Butter'],
    ['Brauner Zucker (optional)', 'Brauner Zucker'],
    ['Zucker (ca. 50 g), optional', 'Zucker'],
    ['Chili (optional, nach Belieben)', 'Chili'],
    // Gegenproben
    ['Milch (1,5 %)', 'Milch (1,5 %)'],
    ['Öl (Raps)', 'Öl (Raps)'],
    ['Mehl (Type 550)', 'Mehl (Type 550)'],
    ['Zimt (2 Stück)', 'Zimt (2 Stück)'],
    ['Parmesan, frisch gerieben', 'Parmesan, frisch gerieben'],
    ['Frischkäse', 'Frischkäse'],
    ['Babyspinat', 'Babyspinat'],
]);

it('Zutat mit Hinweis-Zusatz trifft dasselbe GP mit demselben Band wie ohne (GP ohne Slug, Suchtext ≥ 2 Wörter)', function (string $mit, string $ohne) {
    ($this->gp)('Rohrzucker Braun: trocken', '09', 'trocken', null);
    ($this->gp)('Butter Süßrahm: frisch', '06', 'frisch', null);
    ($this->gp)('Kümmel Öl: konserviert', '11', 'konserviert', null);
    $a = ($this->match)($mit);
    $b = ($this->match)($ohne);

    expect($b['gp_id'])->not->toBeNull()
        ->and($a['gp_id'])->toBe($b['gp_id'])
        ->and($a['status'])->toBe($b['status'])
        ->and($a['score'])->toBe($b['score']);
})->with([
    ['Rohrzucker braun (optional)', 'Rohrzucker braun'],
    ['Butter Süßrahm, Raumtemperatur', 'Butter Süßrahm'],
    ['Kümmel Öl (1 EL)', 'Kümmel Öl'],
]);

it('Akzente gefaltet (GL-04 A-5): „Crème fraîche (1 EL)“ trifft „Creme Fraiche: frisch“ automatisch', function () {
    $cf = ($this->gp)('Creme Fraiche: frisch', '06', 'frisch', null);

    $t = ($this->match)('Crème fraîche (1 EL)');

    expect($t['gp_id'])->toBe($cf->id)
        ->and(IngredientMatchService::istAutomatischVerdrahtbar($t))->toBeTrue();
});

it('Rohform vor Geschmacksvariante: „Frischkäse“ → Natur, nie Grillpaprika — genannt bleibt genannt', function () {
    $grill = ($this->gp)('Frischkäse: Grillpaprika, frisch, streichfähig', '06', 'frisch', 'frischkaese');   // kleinste ID, wie auf io
    $natur = ($this->gp)('Frischkäse: frisch, Natur', '06', 'frisch', 'frischkaese');
    ($this->gp)('Frischkäse: Kräuter, frisch', '06', 'frisch', 'frischkaese');

    $t = ($this->match)('Frischkäse');

    expect($t['gp_id'])->toBe($natur->id)
        ->and(IngredientMatchService::istAutomatischVerdrahtbar($t))->toBeTrue()
        ->and(($this->match)('Frischkäse Grillpaprika')['gp_id'])->toBe($grill->id);
});

it('Draw: andere Hauptzutat und ungenannte Marke/Geschmack werden nie gezogen; ohne Team wie bisher', function () {
    $hippe = ($this->gp)('Hippe: Parmesan, gebacken, trocken', '09', 'trocken', 'hippe');
    $parmesan = ($this->gp)('Parmesan: gereift, frisch', '06', 'frisch', 'parmesan');
    $creme = ($this->gp)('Weißwein-Creme: Pulver, trocken / (Dr. Oetker)', '13', 'trocken', 'weisswein_creme', 'Dr. Oetker');
    $wein = ($this->gp)('Weißwein: zum Kochen', '15', null, 'weisswein');
    $svc = app(IngredientMatchService::class);
    $liste = fn (FoodAlchemistGp ...$gps) => array_map(fn ($g) => ['kind' => 'gp', 'id' => $g->id, 'score' => 1.0, 'origin' => 'both'], $gps);

    expect(array_column($svc->drawKandidaten($liste($hippe, $parmesan), 'gp', 'hybrid', $this->rootTeam, 'Parmesan'), 'id'))->toBe([$parmesan->id])
        ->and(array_column($svc->drawKandidaten($liste($creme, $wein), 'gp', 'hybrid', $this->rootTeam, 'Weißwein'), 'id'))->toBe([$wein->id])
        ->and(array_column($svc->drawKandidaten($liste($creme, $wein), 'gp', 'hybrid', $this->rootTeam, 'Weißwein-Creme Dr. Oetker'), 'id'))->toBe([$creme->id, $wein->id])
        ->and(array_column($svc->drawKandidaten($liste($hippe, $parmesan), 'gp', 'hybrid'), 'id'))->toBe([$hippe->id, $parmesan->id]);
});

it('gps.MATCH zieht mit demselben Schutz: „Parmesan“ nie „Hippe: Parmesan“', function () {
    $hippe = ($this->gp)('Hippe: Parmesan, gebacken, trocken', '09', 'trocken', 'hippe');
    $parmesan = ($this->gp)('Grana Padano: gereift, Laib, frisch', '06', 'frisch', 'grana_padano');   // lexikalisch kein Treffer, nur semantisch
    $mock = Mockery::mock(IngredientMatchService::class, [app(\Platform\FoodAlchemist\Services\Matching\TokenEngine::class),
        app(\Platform\FoodAlchemist\Services\Matching\MatchHeuristics::class)])->makePartial();
    $mock->shouldReceive('matchIngredient')->andReturn(['target' => 'none', 'status' => 'no_match', 'gp_id' => null, 'gp_name' => null,
        'recipe_id' => null, 'recipe_name' => null, 'score' => 0.4]);
    $mock->shouldReceive('candidatesFor')->andReturn([
        ['kind' => 'gp', 'id' => $hippe->id, 'name' => $hippe->name, 'score' => 1.0, 'origin' => 'both'],
        ['kind' => 'gp', 'id' => $parmesan->id, 'name' => $parmesan->name, 'score' => 0.8, 'origin' => 'semantic'],
    ]);
    app()->instance(IngredientMatchService::class, $mock);

    $res = app(ToolRegistry::class)->get('foodalchemist.gps.MATCH')->execute(['zutat' => 'Parmesan'], new ToolContext($this->user, $this->rootTeam));

    expect($res->data['best_match']['gp_id'] ?? null)->not->toBe($hippe->id);
});

it('Mint: kein neues GP mit Hinweis im Namen — der Bestand gewinnt, „(1 EL)“ landet nie im GP-Namen', function () {
    $cf = ($this->gp)('Creme Fraiche: frisch', '06', 'frisch', null);
    $vorher = FoodAlchemistGp::count();

    $gp = app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'Crème fraîche (1 EL)');

    expect($gp?->id)->toBe($cf->id)
        ->and(FoodAlchemistGp::count())->toBe($vorher)
        ->and(FoodAlchemistGp::where('name', 'like', '%(1 EL)%')->count())->toBe(0);
});

it('Spec 95b: „(½ TL)“ und Bereiche „(2–3 EL)“ sind Mengen', function (string $text, string $erwartet) {
    expect(ZutatFreitext::ohneHinweise($text))->toBe($erwartet);
})->with([
    ['Salz (½ TL)', 'Salz'],
    ['Limettensaft (2–3 EL)', 'Limettensaft'],
    ['Zucker (1-2 TL)', 'Zucker'],
    ['Orangensaft (½ Stk.)', 'Orangensaft (½ Stk.)'],   // Stück bleibt (ganze Frucht)
]);

it('Spec 95b: „Mango“ trifft Mango, nie „Mangold“ — Kompositum mit gleichem Anfang verliert den Gleichstand am Kopf-Boden', function () {
    ($this->gp)('Mangold: Streifen 10 mm, frisch', '01', 'frisch', 'mangold');   // kleinste ID, wie auf io
    $mango = ($this->gp)('Mango: ganz, frisch', '02', 'frisch', 'mango');
    ($this->gp)('Mango: Würfel 15 mm, frisch', '02', 'frisch', 'mango');

    expect(($this->match)('Mango')['gp_id'])->toBe($mango->id);
});
