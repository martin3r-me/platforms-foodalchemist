<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\Matching\MatchLeitplanken;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Support\ZutatFreitext;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Matcher-Paket 11.11.2026 (Neuabgleich der Pilot-Rezepte 09.11. + Probelauf Excel 10.11.; Entscheide Dominique 11.11.):
 * Schlussregeln auch unter „selbst gemacht“, Kraut-„-blätter“, Maß-Adverb eines Zuschnitts, Temperatur-Hinweise, Synonyme,
 * Speisestärke = Maisstärke, Pesto ist Basiskomponente (ENRICH band „7-Kräuter-Pesto“ an Bärlauch-Pesto TK).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.semantic_search.enabled' => false]);
    Queue::fake();
    $this->gp = fn (string $name, string $slug, ?string $zustand = null, string $wg = '10') => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 'mp-' . bin2hex(random_bytes(4)) . '||', 'status' => 'approved', 'requires_la' => true,
        'commodity_group_code' => $wg, 'condition' => $zustand, 'main_ingredient_slug' => $slug]);
    $this->match = function (string $t, string $convenience = 'standard') {
        $lp = MatchLeitplanken::aus(['convenience' => $convenience]);

        return app(IngredientMatchService::class)->matchIngredient($this->rootTeam, $t, null, $lp['mode'], $lp['pref'], $lp['preferRaw'], $lp['bio'],
            convenience: $lp['convenience'], zubereitung: $lp['zubereitung']);
    };
});

it('unter „selbst gemacht“ gilt Kandidat 1 wie im gp_first-Lauf (Gochugaru blieb offen)', function () {
    $g = ($this->gp)('Gochugaru Paprika / Chili: trocken, Gewürzmischung', 'gochugaru', 'trocken');

    expect(($this->match)('Gochugaru', 'from_scratch')['gp_id'] ?? null)->toBe($g->id)
        ->and(($this->match)('Gochugaru')['gp_id'] ?? null)->toBe($g->id);
});

it('unter „selbst gemacht“ keine ungenannte Bio-Variante (Paprikapulver edelsüß)', function () {
    $konv = ($this->gp)('Paprikapulver: trocken, edelsüß', 'paprikapulver', 'trocken');
    ($this->gp)('Paprikapulver edelsüß: trocken / (Bio)', 'paprikapulver', 'trocken');

    expect(($this->match)('Paprikapulver edelsüß', 'from_scratch')['gp_id'] ?? null)->toBe($konv->id);
});

it('„-blätter“ eines Krauts ist das Kraut, „Lorbeerblätter“ bleibt das Blatt-GP', function (string $zeile, string $ziel, string $slug, ?string $stoerer) {
    $treffer = ($this->gp)($ziel, $slug, 'frisch', '01');
    if ($stoerer !== null) {
        ($this->gp)($stoerer, $slug . '_x', null, '11');
    }

    expect(($this->match)($zeile)['gp_id'] ?? null)->toBe($treffer->id);
})->with([
    ['Petersilienblätter', 'Petersilie glatt: ganz, frisch', 'petersilie', 'Petersilienöl'],
    ['Minzblätter', 'Minze: frisch', 'minze', null],
    ['Basilikumblätter', 'Basilikum: ganz, frisch', 'basilikum', null],
    ['Lorbeerblätter', 'Lorbeerblatt: trocken', 'lorbeerblatt', null],
]);

it('das Maß-Adverb eines Zuschnitts zählt nicht als Ware: „Schalotten, fein gewürfelt“ = „Schalotten gewürfelt“', function () {
    $ganz = ($this->gp)('Schalotte: ganz, frisch', 'schalotte', 'frisch', '01');
    ($this->gp)('Schalotte: gewürfelt, TK', 'schalotte', 'TK', '01');

    expect(($this->match)('Schalotten, fein gewürfelt', 'from_scratch')['gp_id'] ?? null)->toBe($ganz->id)
        ->and(($this->match)('Schalotten gewürfelt', 'from_scratch')['gp_id'] ?? null)->toBe($ganz->id);
});

it('Temperatur-Hinweise fallen weg, nur als ganzes Segment', function () {
    expect(ZutatFreitext::ohneHinweise('Wasser (lauwarm)'))->toBe('Wasser')
        ->and(ZutatFreitext::ohneHinweise('Butter, kalt'))->toBe('Butter')
        ->and(ZutatFreitext::ohneHinweise('Milch (eiskalt)'))->toBe('Milch')
        ->and(ZutatFreitext::ohneHinweise('Kaltgepresstes Rapsöl'))->toBe('Kaltgepresstes Rapsöl');
});

it('Synonyme: Kokosmilch, Weißer Balsamico, Mangopüree/Mangomark', function (string $zeile, string $gp, string $slug, string $wg) {
    $ziel = ($this->gp)($gp, $slug, null, $wg);

    expect(($this->match)($zeile)['gp_id'] ?? null)->toBe($ziel->id);
})->with([
    ['Kokosmilch', 'Kokosnussmilch: konserviert', 'kokosnussmilch', '12'],
    ['Weißer Balsamico', 'Balsamico Essig: hell', 'balsamico_essig', '12'],
    ['Mangopüree', 'Fruchtpüree Mango: TK, ungezuckert', 'fruchtpueree_mango', '02'],
    ['Mangomark', 'Fruchtpüree Mango: TK, ungezuckert', 'fruchtpueree_mango', '02'],
]);

it('Speisestärke und Mondamin = Maisstärke (§5, Entscheid Dominique 11.11.)', function () {
    $mais = ($this->gp)('Maisstärke: trocken', 'maisstaerke', 'trocken', '05');
    ($this->gp)('Maisstärke: trocken / (Bio)', 'maisstaerke', 'trocken', '05');
    ($this->gp)('Roux: trocken, dunkel / (Mondamin)', 'roux', 'trocken', '13');

    expect(($this->match)('Speisestärke')['gp_id'] ?? null)->toBe($mais->id)
        ->and(($this->match)('Mondamin')['gp_id'] ?? null)->toBe($mais->id);
});

it('Pesto ist eine Basiskomponente: die Anreicherung bindet „7-Kräuter-Pesto“ nie an ein fremdes Pesto-GP', function () {
    ($this->gp)('Pesto Bärlauch: TK / (Achenbach)', 'pesto_baerlauch', 'TK', '13');
    $r = $this->makeRecipe($this->rootTeam, 'Aufstrich: Sieben Kräuter', ['status' => 'draft', 'is_sales_recipe' => false]);
    $zeile = FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id,
        'raw_text' => '7-Kräuter-Pesto', 'quantity' => '100', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1]);

    $ergebnis = app(RecipeOneShotService::class)->minteFehlendeGps($this->rootTeam, $r->fresh());

    expect($ergebnis['basisrezept_luecken'] ?? 0)->toBe(1)->and($zeile->fresh()->gp_id)->toBeNull()
        ->and(app(IngredientMatchService::class)->basiskomponenteOhneZukauf($this->rootTeam, 'Kräuterpesto'))->toBeTrue();
});

it('Heller Essig = Balsamico hell, Schwarze Oliven = entsteint (§5, Entscheid Dominique 11.11.)', function () {
    $hell = ($this->gp)('Balsamico Essig: hell', 'balsamico_essig', null, '12');
    ($this->gp)('Balsamico Essig: hell, im Holzfass gereift', 'balsamico_essig', null, '12');
    ($this->gp)('Weißweinessig', 'weissweinessig', null, '12');
    $entsteint = ($this->gp)('Olive schwarz: entsteint, konserviert', 'olive_schwarz', 'konserviert', '01');
    ($this->gp)('Olive schwarz: Ringe, konserviert', 'olive_schwarz', 'konserviert', '01');
    ($this->gp)('Schwarze Olive: konserviert, gefüllt mit Frischkäse / (Kluth)', 'olive_schwarz', 'konserviert', '01');

    expect(($this->match)('Heller Essig')['gp_id'] ?? null)->toBe($hell->id)
        ->and(($this->match)('Schwarze Oliven')['gp_id'] ?? null)->toBe($entsteint->id);
});

it('„Senfkörner, eingelegt“ ist das Basisrezept Senfkaviar, nie die trockene Senfsaat', function () {
    ($this->gp)('Senfsaat / Senfsamen: trocken', 'senfsaat', 'trocken', '10');
    $m = app(IngredientMatchService::class);

    expect(ZutatFreitext::rezeptBegriff('Senfkörner, eingelegt'))->toBe('Senfkaviar')
        ->and(ZutatFreitext::rezeptBegriff('eingelegte Senfkörner'))->toBe('Senfkaviar')
        ->and(ZutatFreitext::rezeptBegriff('Senfkörner'))->toBe('Senfkörner')
        ->and($m->basiskomponenteOhneZukauf($this->rootTeam, 'Senfkörner, eingelegt'))->toBeTrue()
        ->and(($this->match)('Senfkörner, eingelegt')['gp_id'] ?? null)->toBeNull();
});

it('Kraut-Blätter: nur ein GP-Produktwort zählt („Pandan“ ≠ Marke „Panda“), und die Gewürzmenge macht sie nicht gemahlen', function () {
    ($this->gp)('Austernsauce / Oystersauce: konserviert / (Panda)', 'austernsauce', 'konserviert', '12');
    $frisch = ($this->gp)('Koriander: ganz, frisch', 'koriander', 'frisch', '01');
    $gemahlen = ($this->gp)('Koriander: gemahlen, trocken', 'koriander', 'trocken', '10');
    $m = app(IngredientMatchService::class);
    $lp = MatchLeitplanken::aus([]);
    $mit = fn (string $t) => $m->matchIngredient($this->rootTeam, $t, null, $lp['mode'], $lp['pref'], $lp['preferRaw'], $lp['bio'],
        convenience: $lp['convenience'], einheit: 'g', zubereitung: $lp['zubereitung'], menge: 5.0)['gp_id'] ?? null;

    expect($mit('Pandanblätter'))->toBeNull()
        ->and($mit('Korianderblätter'))->toBe($frisch->id)
        ->and($mit('Koriander'))->toBe($gemahlen->id);   // ohne „-blätter“ bleibt die Gewürzmengen-Regel
});
