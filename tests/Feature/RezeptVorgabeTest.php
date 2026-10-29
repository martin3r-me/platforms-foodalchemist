<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\Matching\MatchLeitplanken;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeExtractService;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Leitplanken-Vorgabe am Rezept (Dominique 29.10.2026, EIN Weg): Eigenleistung und Niveau werden beim Anlegen einmal aus der Planung
 * übernommen, sind am Rezept änderbar (Editor, MCP) und die Weiche „selbst oder gekauft“ liest danach NUR das Rezept. Sub-Rezepte,
 * Gericht aus Basisrezept und Kopie erben; Editor-Neuanlage und Bestand bleiben ohne Vorgabe (Standard).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.semantic_search.enabled' => false]);
    Queue::fake();
    $this->g = $this->unitG($this->rootTeam);
    $this->fondGp = fn () => FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => 'Geflügelfond', 'gp_key' => 'rv-' . bin2hex(random_bytes(4)) . '||',
        'status' => 'approved', 'requires_la' => true, 'commodity_group_code' => '13', 'main_ingredient_slug' => 'gefluegelfond']);
    $this->fondZeile = fn (FoodAlchemistRecipe $r) => FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id,
        'raw_text' => 'Geflügelfond', 'quantity' => '500', 'unit_vocab_id' => $this->g->id, 'position' => 1]);
    $this->vorgabe = fn (FoodAlchemistRecipe $r) => $r->fresh()->only(['vorgabe_convenience', 'vorgabe_niveau']);
});

it('Werte: aus der Planung übernehmen, im Editor/MCP prüfen (unbekannt wirft), am Rezept lesen', function () {
    expect(MatchLeitplanken::vorgabeAus(['convenience' => 'teil_convenience', 'level' => 'klassisch']))
        ->toBe(['vorgabe_convenience' => 'teil_convenience', 'vorgabe_niveau' => 'klassisch'])
        ->and(MatchLeitplanken::vorgabeAus(['convenience' => '', 'level' => 'fine_dining']))->toBe(['vorgabe_convenience' => null, 'vorgabe_niveau' => null])
        ->and(MatchLeitplanken::vorgabeFelder(['vorgabe_niveau' => '', 'name' => 'x']))->toBe(['vorgabe_niveau' => null])
        ->and(MatchLeitplanken::ausRezept((object) ['vorgabe_convenience' => null, 'vorgabe_niveau' => 'gehoben']))->toBe(['level' => 'gehoben']);
    expect(fn () => MatchLeitplanken::vorgabeFelder(['vorgabe_convenience' => 'halb']))->toThrow(RuntimeException::class);
});

it('Import: Vorgabe aus den Leitplanken landet im Rezept, der Unterrezept-Stub erbt sie; ohne Leitplanke keine Vorgabe', function () {
    $import = app(RecipeExtractService::class);
    $rezept = fn (string $name) => ['typ' => 'basisrezept', 'name' => $name, 'preparation' => 'Kochen.', 'zutaten' => [['text' => 'Geflügelfond', 'quantity' => '500', 'unit' => 'g']]];

    $mit = $import->legeAn($this->rootTeam, $rezept('Rahmsauce gehoben'), parameter: ['convenience' => 'from_scratch', 'level' => 'gehoben']);
    $ohne = $import->legeAn($this->rootTeam, $rezept('Rahmsauce frei'));

    expect(($this->vorgabe)($mit['recipe']))->toBe(['vorgabe_convenience' => 'from_scratch', 'vorgabe_niveau' => 'gehoben'])
        ->and(($this->vorgabe)(FoodAlchemistRecipe::find($mit['stubs'][0]['id'])))->toBe(['vorgabe_convenience' => 'from_scratch', 'vorgabe_niveau' => 'gehoben'])
        ->and(($this->vorgabe)($ohne['recipe']))->toBe(['vorgabe_convenience' => null, 'vorgabe_niveau' => null]);
});

it('Kaskade: das Kind erbt die Vorgabe des Eltern-Rezepts (auch nach Änderung); ohne Vorgabe am Eltern bleiben die Lauf-Leitplanken', function () {
    $eltern = $this->makeRecipe($this->rootTeam, 'Rahmsauce Eltern', ['vorgabe_convenience' => 'voll_convenience', 'vorgabe_niveau' => 'klassisch']);
    $alt = $this->makeRecipe($this->rootTeam, 'Rahmsauce Bestand');
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'running', 'params' => ['level' => 'gehoben']]);
    $step = fn ($r) => FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $r->id, 'depth' => 0]);
    $zeile = ($this->fondZeile)($eltern);
    $kindVorgaben = fn ($s) => Closure::bind(fn () => $this->kindVorgaben($s, (int) $zeile->id, 'Geflügelfond'),
        app(RecipeDependencyWorkflowService::class), RecipeDependencyWorkflowService::class)();

    expect($kindVorgaben($step($eltern)))->toMatchArray(['convenience' => 'voll_convenience', 'level' => 'klassisch'])
        ->and($kindVorgaben($step($alt)))->not->toHaveKey('convenience')->not->toHaveKey('level');

    $eltern->update(['vorgabe_convenience' => null, 'vorgabe_niveau' => 'haute_cuisine']);   // im Editor geändert
    expect($kindVorgaben($step($eltern)))->toMatchArray(['convenience' => '', 'level' => 'haute_cuisine'])
        ->and(MatchLeitplanken::zubereitung(['convenience' => '', 'level' => 'haute_cuisine']))->toBe('eigen');
});

it('Anreicherung liest NUR das Rezept: Lauf-Leitplanken ändern nichts, die Änderung im Editor wirkt auf die nächste Anreicherung', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Geflügelrahmsauce', ['status' => 'draft', 'is_sales_recipe' => false]);
    $zeile = ($this->fondZeile)($r);
    $gp = ($this->fondGp)();
    $mint = fn (array $lauf = []) => app(RecipeOneShotService::class)->minteFehlendeGps($this->rootTeam, $r->fresh(), $lauf);

    expect($mint(['level' => 'klassisch'])['basisrezept_luecken'])->toBe(1)->and($zeile->fresh()->gp_id)->toBeNull();   // Planung zählt nicht

    Livewire::test(RecipeModal::class)->call('oeffnen', $r->id)->assertSet('form.vorgabe_niveau', '')
        ->set('form.vorgabe_niveau', 'klassisch')->call('speichern')->assertSet('fehler', null);
    expect(($this->vorgabe)($r)['vorgabe_niveau'])->toBe('klassisch');

    expect($mint()['basisrezept_luecken'] ?? 0)->toBe(0)->and($zeile->fresh()->gp_id)->toBe($gp->id);
});

it('ohne Vorgabe am Rezept: Unterrezept wie bisher (Lücke in der Anreicherung), Hinweis aufs Zukauf-GP im Matcher', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Rahmsauce ohne', ['status' => 'draft']);
    ($this->fondZeile)($r);
    ($this->fondGp)();
    $treffer = app(\Platform\FoodAlchemist\Services\IngredientMatchService::class)->matchIngredient($this->rootTeam, 'Geflügelfond',
        ...MatchLeitplanken::matcherArgsFuerRezept($r));

    expect(app(RecipeOneShotService::class)->minteFehlendeGps($this->rootTeam, $r->fresh())['basisrezept_luecken'])->toBe(1)
        ->and($treffer['basiskomponente'] ?? null)->toBe('stub')->and($treffer['hinweis'] ?? null)->toBe('Gibt es auch als Zukauf: Geflügelfond');
});

it('Gericht-Editor speichert die Vorgabe; „Keine Vorgabe“ leert sie; Gericht aus Basisrezept und Kopie erben', function () {
    $basis = $this->makeRecipe($this->rootTeam, 'Basis Sauce', ['vorgabe_convenience' => 'teil_convenience', 'vorgabe_niveau' => 'gehoben']);
    $vk = app(SalesRecipeService::class)->createFromBasis($this->rootTeam, $basis->id, 'Gericht mit Sauce');
    expect(($this->vorgabe)($vk))->toBe(['vorgabe_convenience' => 'teil_convenience', 'vorgabe_niveau' => 'gehoben']);

    Livewire::test(VkModal::class)->call('oeffnen', $vk->id)->assertSet('form.vorgabe_convenience', 'teil_convenience')
        ->set('form.vorgabe_convenience', 'from_scratch')->set('form.vorgabe_niveau', '')->call('speichern')->assertSet('fehler', null);
    expect(($this->vorgabe)($vk))->toBe(['vorgabe_convenience' => 'from_scratch', 'vorgabe_niveau' => null]);

    $kopie = app(RecipeService::class)->duplicate($this->rootTeam, $basis->id, 'Basis Sauce Kopie');
    expect(($this->vorgabe)($kopie))->toBe(['vorgabe_convenience' => 'teil_convenience', 'vorgabe_niveau' => 'gehoben']);
});

it('Editor-Neuanlage ohne Vorgabe; Stub mit Eltern erbt, Aufrufer-Vorgabe gewinnt', function () {
    $neu = app(RecipeService::class)->create($this->rootTeam, ['name' => 'Neu im Editor']);
    $eltern = $this->makeRecipe($this->rootTeam, 'Eltern', ['vorgabe_niveau' => 'klassisch']);
    $stub = app(RecipeService::class)->createSubRecipeStub($this->rootTeam, 'Kalbsjus', $eltern->id)['recipe'];
    $stub2 = app(RecipeService::class)->createSubRecipeStub($this->rootTeam, 'Wildjus', $eltern->id, ['vorgabe_niveau' => 'gehoben'])['recipe'];

    expect(($this->vorgabe)($neu))->toBe(['vorgabe_convenience' => null, 'vorgabe_niveau' => null])
        ->and(($this->vorgabe)($stub)['vorgabe_niveau'])->toBe('klassisch')
        ->and(($this->vorgabe)($stub2)['vorgabe_niveau'])->toBe('gehoben');
});

it('MCP: recipes.PUT setzt und leert die Vorgabe, prüft Werte; recipes.GET liefert sie', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Per MCP', ['status' => 'draft']);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $put = fn (array $a) => app(\Platform\FoodAlchemist\Tools\RecipesPutTool::class)->execute(['recipe_id' => $r->id] + $a, $ctx);

    expect($put(['vorgabe_convenience' => 'voll_convenience', 'vorgabe_niveau' => 'klassisch'])->success)->toBeTrue()
        ->and(($this->vorgabe)($r))->toBe(['vorgabe_convenience' => 'voll_convenience', 'vorgabe_niveau' => 'klassisch'])
        ->and($put(['vorgabe_niveau' => 'fine_dining'])->errorCode)->toBe('VALIDATION_ERROR')
        ->and($put(['vorgabe_convenience' => ''])->success)->toBeTrue()
        ->and(($this->vorgabe)($r)['vorgabe_convenience'])->toBeNull();

    $get = app(\Platform\FoodAlchemist\Tools\RecipesGetTool::class)->execute(['id' => $r->id], $ctx);
    expect($get->data)->toMatchArray(['vorgabe_convenience' => null, 'vorgabe_niveau' => 'klassisch']);
});
