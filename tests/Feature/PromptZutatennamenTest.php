<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Pilot-Befund 09.11.2026 (Handkäs, io Rezept 22): Eine Zeile wurde per recipe_ingredients.PUT
 * auf ein anderes GP umgehängt — raw_text und display_name trugen danach noch den alten
 * Import-Text („Hippe: Parmesan, gebacken, trocken“). Die KI-Prompts der Anreicherung lasen
 * raw_text und bekamen die falsche Zutat. Zu beweisen: Prompts lesen den Namen, den Editor
 * und recipes.GET zeigen (Unterrezept > GP > Anzeigename > Rohtext).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $GLOBALS['prompt_namen'] = [];
    app()->singleton(FakeAiProvider::class, fn () => new class extends FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            $GLOBALS['prompt_namen'][] = collect($messages)->last()['content'];

            return ['content' => json_encode(['werte' => ['description' => 'Fixture.'], 'confidence' => 0.8, 'reasoning' => 'Fixture']), 'model' => 'fake', 'usage' => []];
        }
    });

    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'handkaes-prompt', 'name' => 'Handkäs: mit Musik', 'status' => 'draft',
    ]);
    $this->zeile = $this->makeIngredient($this->rezept, 'Hippe: Parmesan, gebacken, trocken',
        $this->makeGp($this->rootTeam, 'Grana Padano: gereift'), '30', 1);
    $this->zeile->update(['display_name' => 'Hippe: Parmesan, gebacken, trocken']);   // Stand nach PUT: Texte alt, GP neu
});

it('anzeigeName folgt Unterrezept > GP > Anzeigename > Rohtext', function () {
    expect($this->zeile->fresh()->anzeigeName())->toBe('Grana Padano: gereift');

    $frei = $this->makeIngredient($this->rezept, 'Salz, Pfeffer', null, '1', 2);
    expect($frei->fresh()->anzeigeName())->toBe('Salz, Pfeffer');
    $frei->update(['display_name' => 'Salz']);
    expect($frei->fresh()->anzeigeName())->toBe('Salz');

    $unter = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'musik-prompt', 'name' => 'Marinade: Musik', 'status' => 'draft',
    ]);
    $frei->update(['referenced_recipe_id' => $unter->id]);
    expect($frei->fresh()->anzeigeName())->toBe('Marinade: Musik');
});

it('Beschreibung aus der Anreicherung bekommt den GP-Namen statt des alten Import-Texts', function () {
    $svc = app(BulkEnrichService::class);
    $runId = $svc->starte($this->rootTeam, [$this->rezept->id], ['description']);
    expect($svc->status($this->rootTeam, $runId))->not->toBeNull();

    $prompt = collect($GLOBALS['prompt_namen'])->first(fn ($p) => str_contains($p, '"zutaten"'));
    expect($prompt)->not->toBeNull()
        ->and($prompt)->toContain('Grana Padano')
        ->and($prompt)->not->toContain('Hippe');
});

it('Schritt-Kontext des One-Shot liest den GP-Namen statt des alten Import-Texts', function () {
    $m = new ReflectionMethod(RecipeOneShotService::class, 'stepKontext');
    $kontext = $m->invoke(app(RecipeOneShotService::class), $this->rezept->fresh());

    expect($kontext['zutaten'])->toBe(['Grana Padano: gereift']);
});
