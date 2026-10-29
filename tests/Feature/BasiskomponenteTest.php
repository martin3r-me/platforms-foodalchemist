<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\RecipeExtractService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Basiskomponenten im Rezepttext (Dominique 27.10. d): „Geflügelfond“, „Kalbsjus“, „Hollandaise“ sind meist ein Unterrezept. Erst den
 * Basisrezept-Bestand suchen, ohne Basisrezept einen Stub anlegen (Regelwerk Basisrezepte §4 F4.1), nie ein Zukauf-GP binden — außer mit
 * Zukauf-Signal im Text (Pulver, Paste, Glas, Marke, „Zukauf“) oder Convenience-Leitplanke.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.semantic_search.enabled' => false]);
    FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->gp = fn (string $name, string $slug, ?string $zustand, string $wg, ?string $zusatz = null) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 'bk-' . bin2hex(random_bytes(4)) . '||', 'status' => 'approved', 'requires_la' => true,
        'commodity_group_code' => $wg, 'condition' => $zustand, 'main_ingredient_slug' => $slug, 'zusatz' => $zusatz]);
    $this->fondGp = ($this->gp)('Geflügelfond: konserviert', 'gefluegelfond', 'konserviert', '13');
    $this->knorr = ($this->gp)('Geflügelfond: Pulver, trocken / (Knorr)', 'gefluegelfond', 'trocken', '13', 'Knorr');
    $this->m = fn (string $t, string $conv = 'standard') => app(IngredientMatchService::class)
        ->matchIngredient($this->rootTeam, $t, null, 'gp_first', 'fresh_first', false, 'conventional', convenience: $conv);
});

it('Geflügelfond: Basisrezept im Bestand gewinnt — auch im gp_first-Lauf und gegen das Zukauf-GP', function () {
    $sub = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'bk-fond', 'name' => 'Geflügelfond', 'status' => 'approved', 'is_sales_recipe' => false]);

    $r = ($this->m)('Geflügelfond');
    expect($r['target'])->toBe('sub_recipe')->and($r['recipe_id'])->toBe($sub->id)->and($r['basiskomponente'] ?? null)->toBe('rezept');
});

it('Geflügelfond ohne Basisrezept: kein Zukauf-GP, sondern Stub (Review mit Vorschlag)', function () {
    $r = ($this->m)('Geflügelfond');

    expect(IngredientMatchService::istAutomatischVerdrahtbar($r))->toBeFalse()
        ->and($r['basiskomponente'] ?? null)->toBe('stub')
        ->and($r['status'])->toBe(MatchBand::FuzzyLow);
});

it('Zukauf-Signal im Text („Pulver (Knorr)“) oder Convenience-Leitplanke → Zukauf-GP erlaubt', function () {
    $pulver = ($this->m)('Geflügelfond Pulver (Knorr)');
    $conv = ($this->m)('Geflügelfond', 'voll_convenience');

    expect(IngredientMatchService::istAutomatischVerdrahtbar($pulver))->toBeTrue()->and($pulver['gp_id'])->toBe($this->knorr->id)
        ->and($pulver)->not->toHaveKey('basiskomponente')
        ->and(IngredientMatchService::istAutomatischVerdrahtbar($conv))->toBeTrue()->and($conv['gp_id'])->toBe($this->fondGp->id);
});

it('Typ-Wort in Kaufware ist keine Basiskomponente: Würzsauce, Crème fraîche, Fruchtpüree (§3), Kartoffelpüree (§3)', function () {
    $soja = ($this->gp)('Sojasauce: konserviert', 'sojasauce', 'konserviert', '10');
    $heuristik = app(\Platform\FoodAlchemist\Services\Matching\MatchHeuristics::class);

    expect(($this->m)('Sojasauce')['gp_id'])->toBe($soja->id)
        ->and(IngredientMatchService::istAutomatischVerdrahtbar(($this->m)('Sojasauce')))->toBeTrue()
        ->and($heuristik->istBasiskomponente('Crème fraîche'))->toBeFalse()
        ->and($heuristik->istBasiskomponente('Mangopüree'))->toBeFalse()
        ->and($heuristik->istBasiskomponente('Apfelpüree'))->toBeFalse()
        ->and($heuristik->istBasiskomponente('Austernsauce'))->toBeFalse()
        ->and($heuristik->istBasiskomponente('Sweet-Chili-Sauce'))->toBeFalse()
        ->and($heuristik->istBasiskomponente('Kartoffelpüree'))->toBeFalse()
        ->and($heuristik->istBasiskomponente('Erbsenpüree'))->toBeTrue()
        ->and($heuristik->istBasiskomponente('Kalbsjus'))->toBeTrue()
        ->and($heuristik->istBasiskomponente('Hollandaise'))->toBeTrue()
        ->and($heuristik->istBasiskomponente('Pizzateig'))->toBeTrue()
        ->and($heuristik->istBasiskomponente('Rotweinsoße'))->toBeTrue()
        ->and($heuristik->istBasiskomponente('Vanillecreme'))->toBeTrue();
});

it('Typ-Wort, aber Ware außerhalb WG 13/09 (Balsamicocreme, Essig/Öl): normaler Abgleich, automatisch', function () {
    $creme = ($this->gp)('Balsamicocreme: flüssig', 'balsamicocreme', null, '11');
    $r = ($this->m)('Balsamicocreme');

    expect($r['gp_id'])->toBe($creme->id)->and(IngredientMatchService::istAutomatischVerdrahtbar($r))->toBeTrue()->and($r)->not->toHaveKey('basiskomponente');
});

it('Import: Geflügelfond ohne Basisrezept wird als Stub angelegt und verknüpft, mit Zukauf-Signal ans GP gebunden', function () {
    Queue::fake();
    $res = app(RecipeExtractService::class)->legeAn($this->rootTeam, ['typ' => 'basisrezept', 'name' => 'Geflügelrahmsauce Test', 'preparation' => 'Kochen.',
        'zutaten' => [['text' => 'Geflügelfond', 'quantity' => '500', 'unit' => 'g'], ['text' => 'Geflügelfond Pulver (Knorr)', 'quantity' => '10', 'unit' => 'g']]]);

    $zeilen = $res['recipe']->ingredients()->get()->keyBy('raw_text');
    $stub = FoodAlchemistRecipe::find($zeilen['Geflügelfond']->referenced_recipe_id);
    expect($stub)->not->toBeNull()->and($stub->status->value)->toBe('stub')->and($stub->name)->toBe('Geflügelfond')
        ->and($zeilen['Geflügelfond']->gp_id)->toBeNull()
        ->and($zeilen['Geflügelfond Pulver (Knorr)']->gp_id)->toBe($this->knorr->id)
        ->and($res['stubs'])->toHaveCount(1);
});

it('Aroma: Nussbutter (Typ-Präfix „Aromen & Öle“) wird beim Import zum Unterrezept-Stub, Kaufware-Aromen nicht', function () {
    $h = app(\Platform\FoodAlchemist\Services\Matching\MatchHeuristics::class);

    expect($h->istBasiskomponente('Aroma: Nussbutter'))->toBeTrue()
        ->and($h->istBasiskomponente('Matte: Petersilie'))->toBeTrue()
        ->and($h->istBasiskomponente('Vanillearoma'))->toBeFalse()
        ->and($h->istBasiskomponente('Malzextrakt'))->toBeFalse();
});

it('Aromen-Präfix nur für Aroma, Aromaöl, Tinktur, Matte — „Extrakt:“ bleibt Kaufware, „Öl: neutral“ das Standard-GP (Dominique 29.10.)', function () {
    $h = app(\Platform\FoodAlchemist\Services\Matching\MatchHeuristics::class);

    expect($h->istBasiskomponente('Aroma: Nussbutter'))->toBeTrue()
        ->and($h->istBasiskomponente('Aromaöl: Basilikum'))->toBeTrue()
        ->and($h->istBasiskomponente('Tinktur: Rosmarin'))->toBeTrue()
        ->and($h->istBasiskomponente('Extrakt: Vanille'))->toBeFalse()
        // „Öl: Kräuter“ ist Zubereitung (D5) — ob selbst oder gekauft, entscheiden die Leitplanken; „Öl: neutral“ ist das Standard-GP (LeitplankenZubereitungTest)
        ->and(app(\Platform\FoodAlchemist\Services\IngredientMatchService::class)->zubereitungsWeg($this->rootTeam, 'Öl: neutral'))->toBeNull();
});

it('Import: neue Unterrezept-Hüllen werden im Hintergrund ausrezeptiert (Job je Hülle, Eltern-Bezug), bleiben Entwurf', function () {
    Queue::fake();
    $res = app(RecipeExtractService::class)->legeAn($this->rootTeam, ['typ' => 'basisrezept', 'name' => 'Rahmsauce Stub', 'preparation' => 'Kochen.',
        'zutaten' => [['text' => 'Geflügelfond', 'quantity' => '500', 'unit' => 'g']]]);

    $stubId = (int) $res['stubs'][0]['id'];
    Queue::assertPushed(\Platform\FoodAlchemist\Jobs\StubAusrezeptierenJob::class, 1);
    Queue::assertPushed(\Platform\FoodAlchemist\Jobs\StubAusrezeptierenJob::class, fn ($j) => $j->stubId === $stubId && $j->elternId === (int) $res['recipe']->id);
    expect($res['ausrezeptiert'])->toHaveCount(1)->and($res['ausrezeptieren_offen'])->toBe([]);
});

it('Import: Obergrenze je Import — der Rest bleibt als Hülle offen (Knopf), 0 schaltet das Ausrezeptieren ab', function () {
    Queue::fake();
    $in = ['typ' => 'basisrezept', 'name' => 'Zwei Hüllen', 'preparation' => 'Kochen.',
        'zutaten' => [['text' => 'Geflügelfond', 'quantity' => '500', 'unit' => 'g'], ['text' => 'Kalbsjus', 'quantity' => '200', 'unit' => 'g']]];

    config(['foodalchemist.import.stubs_ausrezeptieren_max' => 1]);
    $res = app(RecipeExtractService::class)->legeAn($this->rootTeam, $in);
    Queue::assertPushed(\Platform\FoodAlchemist\Jobs\StubAusrezeptierenJob::class, 1);
    expect($res['stubs'])->toHaveCount(2)->and($res['ausrezeptiert'])->toHaveCount(1)->and($res['ausrezeptieren_offen'])->toHaveCount(1);

    Queue::fake();
    config(['foodalchemist.import.stubs_ausrezeptieren_max' => 0]);
    $in['name'] = 'Null Hüllen';
    app(RecipeExtractService::class)->legeAn($this->rootTeam, $in);
    Queue::assertNotPushed(\Platform\FoodAlchemist\Jobs\StubAusrezeptierenJob::class);
});

it('Ausrezeptieren füllt die vorhandene Hülle (kein Duplikat), Status wird Entwurf', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $stub = app(\Platform\FoodAlchemist\Services\RecipeService::class)->createSubRecipeStub($this->rootTeam, 'Geflügelfond')['recipe'];

    $res = app(\Platform\FoodAlchemist\Services\RecipeGeneratorService::class)->generiere($this->rootTeam, 'Unterrezept «Geflügelfond»', [
        'stub_fuellen_id' => $stub->id, 'titel_vorgabe' => 'Geflügelfond',
    ], ['name' => 'Geflügelfond', 'zutaten' => [['text' => 'Hühnerknochen', 'quantity' => 1000, 'unit' => 'g'], ['text' => 'Wasser', 'quantity' => 2000, 'unit' => 'g']], 'preparation' => 'Auskochen.'],
        createdVia: 'import_ki_unterrezept');

    expect((int) $res['recipe']->id)->toBe((int) $stub->id)
        ->and($stub->fresh()->status->value)->toBe('draft')
        ->and($stub->fresh()->ingredients()->count())->toBe(2)
        ->and(FoodAlchemistRecipe::where('team_id', $this->rootTeam->id)->where('name', 'Geflügelfond')->count())->toBe(1);
});
