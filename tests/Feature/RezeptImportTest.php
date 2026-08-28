<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\RecipeExtractService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Rezept-IMPORT (2026-08-22): bestehende Rezeptur (Rohtext) TREU extrahieren (recipe.extract) und
 * GEERDET als Draft anlegen (kiRezeptOverride-Pfad = Resolver + syncIngredients + Recompute).
 * Verschachtelte Quellen (Komponenten mit eigenen Zutaten) → verknüpfte Sub-Rezepte (§4).
 * Der Override-Pfad ruft KEINEN LLM — nur `extrahiere` tut das (hier gemockt).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    foreach (['g' => 1, 'ml' => 1, 'kg' => 1000, 'stk' => 50] as $slug => $g) {
        FoodAlchemistVocabEinheit::create([
            'team_id' => $this->rootTeam->id, 'slug' => $slug, 'display_de' => strtoupper($slug),
            'dimension' => $slug === 'ml' ? 'volume' : 'mass', 'default_in_g' => $g,
        ]);
    }

    // recipe.extract (der einzige LLM-Call des Imports) faken → liefert die vorgegebene Struktur.
    $this->mockExtract = function (array $werte) {
        $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')
            ->andReturn(new AiProposal($werte, 0.9, 'Mock', [], 'extract')));
    };
});

it('extrahiere strukturiert den Rohtext (treu, keine Erfindung)', function () {
    ($this->mockExtract)([
        'typ' => 'basisrezept', 'name' => 'Tomatensauce',
        'zutaten' => [['text' => 'Tomaten', 'quantity' => 500, 'unit' => 'g']],
        'preparation' => 'Kochen.', 'komponenten' => [],
    ]);

    $out = app(RecipeExtractService::class)->extrahiere($this->rootTeam, '500 g Tomaten, kochen.');

    expect($out['name'])->toBe('Tomatensauce')
        ->and($out['typ'])->toBe('basisrezept')
        ->and($out['zutaten'])->toHaveCount(1);
});

it('legeAn legt einen GEERDETEN Import-Draft an (created_via=import, Zutaten synchronisiert)', function () {
    $extrakt = [
        'typ' => 'basisrezept', 'name' => 'Tomatensauce',
        'zutaten' => [
            ['text' => 'Tomaten', 'quantity' => 500, 'unit' => 'g'],
            ['text' => 'Olivenöl', 'quantity' => 20, 'unit' => 'ml'],
        ],
        'preparation' => 'Kochen.',
    ];

    $res = app(RecipeExtractService::class)->legeAn($this->rootTeam, $extrakt);
    $r = $res['recipe'];

    expect($r->status->value)->toBe('draft')
        ->and($r->created_via)->toBe('import')
        ->and((int) $r->n_ingredients_total)->toBe(2)
        ->and((bool) $r->is_sales_recipe)->toBeFalse();
});

it('legeAn legt verschachtelte Komponenten als verknüpfte Sub-Rezepte an', function () {
    $extrakt = [
        'typ' => 'basisrezept', 'name' => 'Rahmgemüse',
        'zutaten' => [['text' => 'Karotten', 'quantity' => 300, 'unit' => 'g']],
        'preparation' => 'Anrichten.',
        'komponenten' => [
            ['name' => 'Rahmsauce', 'zutaten' => [['text' => 'Sahne', 'quantity' => 200, 'unit' => 'ml']], 'preparation' => 'Reduzieren.'],
        ],
    ];

    $res = app(RecipeExtractService::class)->legeAn($this->rootTeam, $extrakt);
    $parent = $res['recipe'];

    expect($res['sub_recipes'])->toHaveCount(1);
    $subId = (int) $res['sub_recipes'][0]['id'];
    $sub = FoodAlchemistRecipe::find($subId);
    expect($sub)->not->toBeNull()
        ->and($sub->created_via)->toBe('import');
    // Der Parent trägt eine Zutatenzeile, die auf das Sub-Rezept verweist (nicht flach aufgelöst).
    $verweise = $parent->ingredients()->pluck('referenced_recipe_id')->filter()->all();
    expect($verweise)->toContain($subId);
});

it('recipes.EXTRACT (MCP): dry_run nur Struktur, sonst geerdeter Draft', function () {
    ($this->mockExtract)([
        'typ' => 'basisrezept', 'name' => 'Jus',
        'zutaten' => [['text' => 'Kalbsknochen', 'quantity' => 1, 'unit' => 'kg']],
        'preparation' => 'Rösten.', 'komponenten' => [],
    ]);
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($this->user, $this->rootTeam);

    $dry = $registry->get('foodalchemist.recipes.EXTRACT')->execute(['raw_text' => 'x', 'dry_run' => true], $kontext);
    expect($dry->success)->toBeTrue()
        ->and($dry->data['dry_run'])->toBeTrue()
        ->and($dry->data['extrakt']['name'])->toBe('Jus');

    $post = $registry->get('foodalchemist.recipes.EXTRACT')->execute(['raw_text' => 'x'], $kontext);
    expect($post->success)->toBeTrue();
    $r = FoodAlchemistRecipe::find($post->data['recipe']['id']);
    expect($r->created_via)->toBe('import')
        ->and($r->status->value)->toBe('draft');
});

it('#6: Import mit leerem Namen (Quelle ohne Titel) → Nachfassen statt namenlosem Rezept; Bezeichnung kommt an', function () {
    // Quelltext ohne Titel → recipe.extract liefert name="" (nichts erfinden). Die Zutat-Bezeichnung
    // wird aber sehr wohl erfasst (belegt hier + im echten Call-Log 08-25).
    ($this->mockExtract)([
        'typ' => 'basisrezept', 'name' => '',
        'zutaten' => [['text' => 'Rindergulasch', 'quantity' => 500, 'unit' => 'g']],
        'preparation' => '', 'komponenten' => [],
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('importText', '500 g Rindergulasch')
        ->call('importExtrahieren')
        ->assertSet('importStep', 'vorschau')
        ->assertSet('importVorschau.zutaten.0.text', 'Rindergulasch')   // Bezeichnung KOMMT in der Vorschau an
        ->call('importAnlegen')
        ->assertSet('importStep', 'vorschau')                          // Guard: NICHT „fertig"
        ->assertSet('importMeldung', fn ($m) => is_string($m) && str_contains($m, 'Namen'));

    expect(FoodAlchemistRecipe::where('team_id', $this->rootTeam->id)->where('created_via', 'import')->count())->toBe(0);
});

it('Import-Tab (Livewire): extrahieren → Vorschau, anlegen → Draft', function () {
    ($this->mockExtract)([
        'typ' => 'basisrezept', 'name' => 'Pesto',
        'zutaten' => [['text' => 'Basilikum', 'quantity' => 50, 'unit' => 'g']],
        'preparation' => 'Mixen.', 'komponenten' => [],
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('importText', '50 g Basilikum, mixen.')
        ->call('importExtrahieren')
        ->assertSet('importStep', 'vorschau')
        ->call('importAnlegen')
        ->assertSet('importStep', 'fertig');
});
