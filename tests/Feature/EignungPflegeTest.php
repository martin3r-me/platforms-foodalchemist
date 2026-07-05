<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\DetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M9-01k: Sektor-/Niveau-Eignung pflegen (Zeile = geeignet, unique inkl.
 * soft-deleted ⇒ Reaktivierung) + ✨ Eignung/Marketing im VK-Panel (GL-07,
 * Override-First beim Marketing).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk-eig', 'name' => 'FIN: Wrap',
        'status' => 'draft', 'is_sales_recipe' => true,
    ]);
});

it('setzeEignung/entferneEignung: Slug-Whitelist, Besitzer-Guard, Reaktivierung statt unique-Crash', function () {
    $svc = app(RecipeService::class);
    $svc->setzeEignung($this->rootTeam, $this->vk->id, 'sektor', 'care');
    expect(DB::table('foodalchemist_recipe_sector_suitability')->where('recipe_id', $this->vk->id)->whereNull('deleted_at')->count())->toBe(1);

    $svc->entferneEignung($this->rootTeam, $this->vk->id, 'sektor', 'care');
    expect(DB::table('foodalchemist_recipe_sector_suitability')->where('recipe_id', $this->vk->id)->whereNull('deleted_at')->count())->toBe(0);

    // Reaktivierung derselben Zeile (unique recipe+slug gilt inkl. soft-deleted)
    $svc->setzeEignung($this->rootTeam, $this->vk->id, 'sektor', 'care', 'ai_inferred', 0.8);
    $zeile = DB::table('foodalchemist_recipe_sector_suitability')->where('recipe_id', $this->vk->id)->whereNull('deleted_at')->first();
    expect($zeile->source)->toBe('ai_inferred')->and((float) $zeile->ai_confidence)->toBe(0.8);

    expect(fn () => $svc->setzeEignung($this->rootTeam, $this->vk->id, 'sektor', 'quatsch'))
        ->toThrow(RuntimeException::class, 'Slug');
    expect(fn () => $svc->setzeEignung($this->childA, $this->vk->id, 'level', 'gehoben'))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
});

it('Panel: ✨ Eignung übernimmt nur «geeignet»-Urteile aus dem Vokabular', function () {
    $this->mock(AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->twice()->andReturn(new AiProposal([
            'sektoren' => ['care' => ['eignung' => 'geeignet'], 'crew' => ['eignung' => 'ungeeignet'], 'fantasie' => ['eignung' => 'geeignet']],
            'niveaus' => ['klassisch' => ['eignung' => 'geeignet']],
        ], 0.85));
    });

    Livewire::test(DetailPanel::class, ['recipeId' => $this->vk->id])
        ->call('kiEignung')
        ->assertSet('kiFehler', null)
        ->assertSet('eignungVorschlag.slugs', ['care' => 'sektor', 'klassisch' => 'level'])
        ->call('eignungUebernehmen');

    expect(DB::table('foodalchemist_recipe_sector_suitability')->where('recipe_id', $this->vk->id)->whereNull('deleted_at')->value('sector_slug'))->toBe('care')
        ->and(DB::table('foodalchemist_recipe_level_suitability')->where('recipe_id', $this->vk->id)->whereNull('deleted_at')->value('level_slug'))->toBe('klassisch');
});

it('Panel: ✨ Marketing schreibt mit Lineage ki; manual blockt (Override-First)', function () {
    $this->mock(AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->andReturn(new AiProposal(['marketing_text' => 'Knusprig gewickelt.'], 0.9));
    });

    Livewire::test(DetailPanel::class, ['recipeId' => $this->vk->id])
        ->call('kiMarketing')
        ->call('marketingUebernehmen')
        ->assertSet('kiFehler', null);
    $r = $this->vk->fresh();
    expect($r->marketing_text)->toBe('Knusprig gewickelt.')->and($r->marketing_text_source)->toBe('ki');

    $r->update(['marketing_text_source' => 'manual']);
    Livewire::test(DetailPanel::class, ['recipeId' => $this->vk->id])
        ->call('kiMarketing')
        ->call('marketingUebernehmen')
        ->assertSet('kiFehler', fn ($f) => str_contains((string) $f, 'manuell gepflegt'));
});
