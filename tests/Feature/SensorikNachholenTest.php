<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Jobs\SensorikNachholenJob;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\SensorikService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 (Dominique 16.10.): Neue Basisrezepte bekommen ihre KI-Sensorik (Texturen) automatisch,
 * aber nur aus dem Speicherpfad mit Nutzer — nie aus Bulk-Recompute, Import oder bei KI aus.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->basis = function (array $attrs = []) {
        $r = $this->makeRecipe($this->rootTeam, 'Garnitur: Brotchips', $attrs + ['is_sales_recipe' => false]);
        $this->makeIngredient($r, 'Brot', $this->makeGp($this->rootTeam, 'Brot: Weizen '.$r->id), '100', 1);

        return $r;
    };
    $this->speichern = fn ($r) => app(RecipeService::class)->update($this->rootTeam, $r->id, ['preparation' => 'Brot in Scheiben schneiden, im Ofen rösten.']);
});

it('Speichern eines Basisrezepts mit Zutaten und Zubereitung stößt die Sensorik an — auf der Standard-Queue, verzögert', function () {
    Queue::fake();
    $this->actingAs($this->user);
    $r = ($this->basis)();

    ($this->speichern)($r);

    Queue::assertPushed(SensorikNachholenJob::class, function ($job) use ($r) {
        return $job->recipeId === $r->id && $job->queue === null        // ohne Konfiguration: Standard-Schlange (io-Worker --queue=default)
            && $job->delay !== null && $job->uniqueId() === 'sensorik-nachholen-'.$r->id;
    });
});

it('Gegenproben: kein Nutzer, KI aus, Gericht, ohne Zubereitung, schon bewertet, Bulk-Recompute, Direkt-Anlage → nichts', function () {
    Queue::fake();
    $r = ($this->basis)();

    ($this->speichern)($r);                                                      // kein Nutzer (Import/Befehl)
    $this->actingAs($this->user);
    app(TeamSettingsService::class)->update($this->rootTeam, ['ai_active' => false]);
    ($this->speichern)($r);                                                      // KI aus
    app(TeamSettingsService::class)->update($this->rootTeam, ['ai_active' => true]);

    $gericht = ($this->basis)(['is_sales_recipe' => true]);
    ($this->speichern)($gericht);                                                // Gericht/VK
    $ohne = ($this->basis)(['preparation' => null]);
    app(RecipeService::class)->update($this->rootTeam, $ohne->id, ['name' => 'Garnitur: Brotchips neu']);   // ohne Zubereitung
    DB::table('foodalchemist_recipe_taste_vectors')->insert(['recipe_id' => $r->id, 'source' => 'ai', 'source_hash' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    ($this->speichern)($r);                                                      // schon bewertet

    $neu = ($this->basis)(['preparation' => 'Rösten.']);
    FoodAlchemistRecipeIngredient::query()->where('recipe_id', $neu->id)->first()->update(['quantity' => '120']);   // Direkt-Schreiben
    app(RecipeRecomputeService::class)->recomputeMany([$neu->id]);              // Bulk-Recompute

    Queue::assertNotPushed(SensorikNachholenJob::class);
});

it('Job: bewertet, schreibt Texturen und zieht das Aromenprofil samt verwendender Rezepte nach', function () {
    DB::table('foodalchemist_vocab_textures')->insertOrIgnore(['slug' => 'knusprig', 'display_de' => 'Knusprig', 'created_at' => now(), 'updated_at' => now()]);
    $r = ($this->basis)(['preparation' => 'Brot in Scheiben schneiden, im Ofen rösten.']);
    $gericht = $this->makeRecipe($this->rootTeam, 'Suppe mit Brotchips', ['is_sales_recipe' => true]);
    FoodAlchemistRecipeIngredient::create(['team_id' => $gericht->team_id, 'recipe_id' => $gericht->id, 'referenced_recipe_id' => $r->id,
        'raw_text' => $r->name, 'quantity' => '30', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1]);
    $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')->once()->with('recipe.sensorik', Mockery::any(), Mockery::any())
        ->andReturn(new AiProposal(['geschmack' => array_fill_keys(SensorikService::DIMS, 0.2), 'texturen' => [['slug' => 'knusprig', 'intensity' => 0.9]]], 0.8)));

    (new SensorikNachholenJob((int) $this->rootTeam->id, (int) $this->user->id, (int) $r->id))->handle(app(SensorikService::class));

    $profil = json_decode((string) DB::table('foodalchemist_recipe_profile')->where('recipe_id', $r->id)->value('eigenschaften'), true);
    expect(DB::table('foodalchemist_recipe_textures')->where('recipe_id', $r->id)->count())->toBe(1)
        ->and($profil['knusprig'] ?? null)->toBe(['stufe' => 2.7, 'quelle' => 'ki_textur'])
        ->and(DB::table('foodalchemist_recipe_profile')->where('recipe_id', $gericht->id)->exists())->toBeTrue();   // Eltern nachgezogen

    // zweiter Lauf: schon bewertet → kein KI-Call (Mock erlaubt nur einen)
    (new SensorikNachholenJob((int) $this->rootTeam->id, (int) $this->user->id, (int) $r->id))->handle(app(SensorikService::class));
});
