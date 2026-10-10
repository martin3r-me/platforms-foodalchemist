<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\ZukaufBasisrezeptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Dominique 10.10.: „Toast kann allein kein Zukauf sein. Ich stelle keinen Toast hin, ich mache einen Crunch daraus —
 * Baguette oder Toast reiche ich nicht als Scheibe zum Gericht.“ Brot wird im Gericht verarbeitete Komponente.
 */
beforeEach(function () {
    Queue::fake();
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->gp = fn (string $name, string $wg, string $sub) => tap($this->makeGp($this->rootTeam, $name))
        ->update(['commodity_group_code' => $wg, 'sub_category' => $sub]);
    $this->z = app(ZukaufBasisrezeptService::class);
});

it('Brot und Backwaren werden nie Zukauf — Knabbereien (Croutons) schon', function () {
    ($this->gp)('Toast', '09', '09.1 Brot & Broetchen');
    ($this->gp)('Baguette', '09', '09.1 Brot & Broetchen');
    ($this->gp)('Croutons', '09', '09.6 Knabbereien');

    expect($this->z->istFertigware($this->rootTeam, 'Toast'))->toBeFalse()
        ->and($this->z->erkenne($this->rootTeam, 'Crunch: Baguette'))->toBeNull()
        ->and($this->z->istFertigware($this->rootTeam, 'Croutons'))->toBeTrue()
        ->and($this->z->istBrotZeile($this->rootTeam, 'Toast'))->toBeTrue()
        ->and($this->z->istBrotZeile($this->rootTeam, 'Sauerteigbrot'))->toBeTrue()      // auch ohne GP, über das Brot-Wort
        ->and($this->z->istBrotZeile($this->rootTeam, 'Croutons'))->toBeFalse();
});

it('Brot als Unterrezept: normale Planung mit Verarbeitungs-Auftrag, kein Rüst-Basisrezept', function () {
    ($this->gp)('Toast', '09', '09.1 Brot & Broetchen');
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $eltern = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done']);
    $kind = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'parent_step_id' => $eltern->id, 'kind' => 'rezept', 'status' => 'geplant', 'label' => 'Toast', 'depth' => 1]);

    app(RecipeDependencyWorkflowService::class)->starteKind($this->rootTeam, $kind, (int) auth()->id(), 'Toast', [], false);

    Queue::assertPushed(GenerateRecipePlanJob::class, fn ($j) => ($j->params['brot_verarbeiten'] ?? null) === 'Toast'
        && ! isset($j->params['ruest_ware']));
    $prompt = app(\Platform\FoodAlchemist\Services\RecipeGenerationContextService::class)
        ->build($this->rootTeam, 'Toast', ['brot_verarbeiten' => 'Toast'], false)['prompt'];
    expect($prompt['brot_verarbeiten']['hinweis'])->toContain('nicht als Scheibe');
});

it('im Gericht ist eine Brot-Zeile nie ein Grundprodukt, sondern ein Basisrezept', function () {
    \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    ($this->gp)('Baguette: frisch', '09', '09.1 Brot & Broetchen');
    config(['foodalchemist.ai.provider' => 'fake']);

    $res = app(\Platform\FoodAlchemist\Services\RecipeGeneratorService::class)->generiere($this->rootTeam, 'Test', [], [
        'name' => '[HG] Rind | Kürbis | Baguette', 'zutaten' => [
            ['text' => 'Rinderfilet', 'quantity' => 150, 'unit' => 'g'], ['text' => 'Kürbis', 'quantity' => 120, 'unit' => 'g'],
            ['text' => 'Baguette', 'quantity' => 40, 'unit' => 'g']],
    ], vkModus: true);

    expect($res['recipe']->ingredients()->where('raw_text', 'Baguette')->first()->gp_id)->toBeNull()
        ->and(collect($res['offene'])->pluck('primaer')->all())->toContain('basisrezept_anlegen');
});

it('Brot-Angebot (Brotkorb, Brotkonfekt, Brot & Butter): die Brote bleiben Grundprodukte', function () {
    \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $baguette = ($this->gp)('Baguette: frisch', '09', '09.1 Brot & Broetchen');
    ($this->gp)('Laugenbrezel: frisch', '09', '09.1 Brot & Broetchen');
    config(['foodalchemist.ai.provider' => 'fake']);

    $korb = app(\Platform\FoodAlchemist\Services\RecipeGeneratorService::class)->generiere($this->rootTeam, 'Test', [], [
        'name' => '[BRO] Brotkorb | Baguette | Laugenbrezel', 'zutaten' => [
            ['text' => 'Baguette: frisch', 'quantity' => 40, 'unit' => 'g'], ['text' => 'Laugenbrezel: frisch', 'quantity' => 40, 'unit' => 'g']],
    ], vkModus: true)['recipe'];

    expect($korb->ingredients()->pluck('gp_id')->filter()->count())->toBe(2)
        ->and($this->z->istBrotAngebot($this->rootTeam, 'Brotkonfekt', []))->toBeTrue()
        ->and($this->z->istBrotAngebot($this->rootTeam, '[HG] Rinderfilet | Kürbis', ['Rinderfilet', 'Kürbis', 'Toast']))->toBeFalse()
        ->and($this->z->istBrotZeile($this->rootTeam, 'Panko'))->toBeTrue();
});
