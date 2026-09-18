<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 53 Teil B — Knopf „KI-Produktfoto" in Rezept-/VK-Modal. EnrichRecipeJob im
 * `nurProduktfoto`-Modus (Basisrezept-Modal hat KEINEN stepId — anders als die Kaskaden-Kanäle,
 * darum eigener Cache-basierter Doppel-Enqueue-Guard statt `deferred.bilder`).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

// ── EnrichRecipeJob nurProduktfoto ──────────────────────────────────────────

it('EnrichRecipeJob nurProduktfoto: ruft NUR produktFoto() auf, keine Anreicherung, keine Schrittfotos', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $recipe = $this->makeRecipe($this->rootTeam, 'Nur-Produktfoto-Test');

    $this->mock(RecipeImageService::class, function ($mock) {
        $mock->shouldReceive('loescheKiFotos')->once()->with(\Mockery::any(), \Mockery::any(), [RecipeImageService::FEATURE_PRODUKTFOTO]);
        $mock->shouldReceive('produktFoto')->once();
        $mock->shouldNotReceive('erzeugeFuerRezept');
        $mock->shouldNotReceive('schrittFoto');
    });

    (new EnrichRecipeJob($this->rootTeam->id, (int) Auth::id(), $recipe->id, nurProduktfoto: true))
        ->handle(app(\Platform\FoodAlchemist\Services\RecipeOneShotService::class));

    $stand = Cache::get(EnrichRecipeJob::produktfotoCacheKey($this->rootTeam->id, $recipe->id));
    expect($stand['status'] ?? null)->toBe('done');
});

it('EnrichRecipeJob nurProduktfoto: ersetzt NUR das KI-Produktfoto, Schrittfotos bleiben stehen', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $recipe = $this->makeRecipe($this->rootTeam, 'Ersetzt-Nur-Produktfoto');

    // Bestehendes KI-Produktfoto + KI-Schrittfoto simulieren (Discriminator = Call-Log).
    $altesProdukt = FoodAlchemistRecipeStepPhoto::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $recipe->id, 'pfad' => 'alt-produkt.png', 'is_result' => true,
    ]);
    $schrittFoto = FoodAlchemistRecipeStepPhoto::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $recipe->id, 'pfad' => 'schritt.png', 'is_result' => false,
    ]);
    DB::table('foodalchemist_ai_call_log')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $this->rootTeam->id, 'feature' => RecipeImageService::FEATURE_PRODUKTFOTO,
        'tier' => 'I', 'model' => 'gpt-image-1.5', 'prompt_hash' => 'x', 'target_table' => 'foodalchemist_recipe_step_photos',
        'target_id' => $altesProdukt->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('foodalchemist_ai_call_log')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $this->rootTeam->id, 'feature' => RecipeImageService::FEATURE_SCHRITTFOTOS,
        'tier' => 'I', 'model' => 'gpt-image-1.5', 'prompt_hash' => 'y', 'target_table' => 'foodalchemist_recipe_step_photos',
        'target_id' => $schrittFoto->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->mock(\Platform\Core\Services\ImageGenerationService::class, function ($mock) {
        $mock->shouldReceive('generateAndStore')->once()->andReturnUsing(function (
            string $prompt, string $contextType, int $contextId, int $userId, int $teamId, array $options,
        ) {
            $token = 'ki-neu-'.\Illuminate\Support\Str::random(8);
            $file = \Platform\Core\Models\ContextFile::create([
                'token' => $token, 'team_id' => $teamId, 'user_id' => $userId,
                'context_type' => $contextType, 'context_id' => $contextId,
                'disk' => 'public', 'path' => "foodalchemist/rezepte/{$contextId}/{$token}.webp",
                'file_name' => "{$token}.webp", 'original_name' => "{$token}.png",
                'mime_type' => 'image/webp', 'file_size' => 1234, 'width' => 1024, 'height' => 1024,
                'keep_original' => false,
            ]);

            return ['id' => $file->id, 'revised_prompt' => $prompt];
        });
    });

    (new EnrichRecipeJob($this->rootTeam->id, (int) Auth::id(), $recipe->id, nurProduktfoto: true))
        ->handle(app(\Platform\FoodAlchemist\Services\RecipeOneShotService::class));

    expect(FoodAlchemistRecipeStepPhoto::whereKey($altesProdukt->id)->exists())->toBeFalse('altes KI-Produktfoto muss ersetzt sein')
        ->and(FoodAlchemistRecipeStepPhoto::whereKey($schrittFoto->id)->exists())->toBeTrue('Schrittfoto darf NICHT gelöscht werden')
        ->and(FoodAlchemistRecipeStepPhoto::where('recipe_id', $recipe->id)->where('is_result', true)->count())->toBe(1);
});

it('EnrichRecipeJob nurProduktfoto: Fehler landet im Cache, nicht in deferred.bilder (kein stepId)', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Fehler-Test');
    $this->mock(RecipeImageService::class, function ($mock) {
        $mock->shouldReceive('loescheKiFotos')->once();
        $mock->shouldReceive('produktFoto')->once()->andThrow(new \RuntimeException('Provider aus.'));
    });

    (new EnrichRecipeJob($this->rootTeam->id, (int) Auth::id(), $recipe->id, nurProduktfoto: true))
        ->handle(app(\Platform\FoodAlchemist\Services\RecipeOneShotService::class));

    $stand = Cache::get(EnrichRecipeJob::produktfotoCacheKey($this->rootTeam->id, $recipe->id));
    expect($stand['status'] ?? null)->toBe('failed')
        ->and($stand['error'] ?? null)->toBe('Provider aus.');
});

// ── Recipe-Modal ─────────────────────────────────────────────────────────

it('Recipe-Modal: „KI-Produktfoto" hat data-ki-action + wire:target', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Produktfoto-Knopf-Basis');
    $html = Livewire::test(RecipeModal::class)
        ->call('oeffnen', $rezept->id)
        ->call('tabLaden', 'preparation')
        ->html();

    expect($html)->toContain('data-ki-action="kiProduktfoto"')
        ->toContain('wire:target="kiProduktfoto"');
});

it('Recipe-Modal: kiProduktfoto dispatcht EnrichRecipeJob mit nurProduktfoto=true', function () {
    Queue::fake();
    $rezept = $this->makeRecipe($this->rootTeam, 'Produktfoto-Dispatch-Basis');

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $rezept->id)
        ->call('kiProduktfoto')
        ->assertSet('produktfotoLaeuft', true);

    Queue::assertPushed(EnrichRecipeJob::class, fn ($j) => $j->recipeId === $rezept->id && $j->nurProduktfoto === true);
});

it('Recipe-Modal: Doppel-Klick dispatcht NICHT zweimal, solange der Cache queued/running zeigt', function () {
    Queue::fake();
    $rezept = $this->makeRecipe($this->rootTeam, 'Produktfoto-Doppelklick-Basis');

    $c = Livewire::test(RecipeModal::class)->call('oeffnen', $rezept->id);
    $c->call('kiProduktfoto');
    $c->call('kiProduktfoto');   // zweiter Klick — Cache steht auf queued/running

    Queue::assertPushed(EnrichRecipeJob::class, 1);
});

it('Recipe-Modal: kein wire:poll im Ruhezustand, erscheint nach dem Klick', function () {
    Queue::fake();
    $rezept = $this->makeRecipe($this->rootTeam, 'Produktfoto-Poll-Basis');

    $c = Livewire::test(RecipeModal::class)->call('oeffnen', $rezept->id)->call('tabLaden', 'preparation');
    expect($c->html())->not->toContain('pruefeProduktfotoErgebnis');

    $c->call('kiProduktfoto');
    expect($c->html())->toContain('wire:poll.2s="pruefeProduktfotoErgebnis"')
        ->toContain('KI-Produktfoto wird erzeugt');
});

it('Recipe-Modal: pruefeProduktfotoErgebnis zeigt den Fehler aus dem Job', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Produktfoto-Fehler-Basis');
    Cache::put(EnrichRecipeJob::produktfotoCacheKey($this->rootTeam->id, $rezept->id), ['status' => 'failed', 'error' => 'Kaputt.'], now()->addMinutes(5));

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $rezept->id)
        ->set('produktfotoLaeuft', true)
        ->call('pruefeProduktfotoErgebnis')
        ->assertSet('produktfotoLaeuft', false)
        ->assertSet('produktfotoFehler', 'Kaputt.');
});

// ── VK-Modal ─────────────────────────────────────────────────────────────

it('VK-Modal: „KI-Produktfoto" hat data-ki-action + wire:target', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Produktfoto-Knopf-VK', ['is_sales_recipe' => true]);
    $html = Livewire::test(VkModal::class)->call('oeffnen', $rezept->id)->html();

    expect($html)->toContain('data-ki-action="kiProduktfoto"')
        ->toContain('wire:target="kiProduktfoto"');
});

it('VK-Modal: kiProduktfoto dispatcht EnrichRecipeJob mit nurProduktfoto=true, Doppel-Klick-Guard greift', function () {
    Queue::fake();
    $rezept = $this->makeRecipe($this->rootTeam, 'Produktfoto-Dispatch-VK', ['is_sales_recipe' => true]);

    $c = Livewire::test(VkModal::class)->call('oeffnen', $rezept->id);
    $c->call('kiProduktfoto')->assertSet('produktfotoLaeuft', true);
    $c->call('kiProduktfoto');

    Queue::assertPushed(EnrichRecipeJob::class, fn ($j) => $j->recipeId === $rezept->id && $j->nurProduktfoto === true);
    Queue::assertPushed(EnrichRecipeJob::class, 1);
});
