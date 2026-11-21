<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Jobs\StueckgewichtNachAnlageJob;
use Platform\FoodAlchemist\Models\FoodAlchemistGpForm;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\GpFormService;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Stückgewicht bei loser Ware (Dominique 11.11.): ein GP, das aus einem lose pro kg verkauften Artikel angelegt wird, bekommt sein
 * Stückgewicht per KI im Hintergrund — Karton, Stück-Artikel, ein weiterer Artikel oder ein gepflegtes Stückgewicht lösen nichts aus.
 * Die Umrechnung selbst (Stück → Gramm über piece_default_g / Form „stk“) gab es schon; sie bekam bei loser Ware nur keinen Wert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    config(['foodalchemist.ai.provider' => 'core']);
    FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'stk'], ['display_de' => 'Stück', 'dimension' => 'count']);
    $this->lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Obst & Gemüse']);
    $this->artikel = fn (string $bez, float $qty, string $unit, ?string $bestell) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->lieferant->id, 'article_number' => $bez, 'designation' => $bez,
        'qty' => $qty, 'unit_code' => $unit, 'ordering_unit' => $bestell]);
});

it('GP aus loser Ware (kg): erster Artikel löst die Stückgewicht-Schätzung aus', function () {
    Queue::fake();
    $gp = $this->makeGp($this->rootTeam, 'Aprikose: ganz, frisch');
    $la = ($this->artikel)('O+G Aprikosen Kal.A (40-45mm)', 1.0, 'kg', 'KG');

    app(LeadLaService::class)->verknuepfen($this->rootTeam, $gp, (int) $la->id);

    Queue::assertPushed(StueckgewichtNachAnlageJob::class, fn ($job) => $job->gpId === (int) $gp->id);
});

it('Karton, Stück-Artikel, gepflegtes Stückgewicht oder ein weiterer Artikel lösen nichts aus', function () {
    Queue::fake();
    $karton = $this->makeGp($this->rootTeam, 'Apfel Elstar: ganz, frisch');
    app(LeadLaService::class)->verknuepfen($this->rootTeam, $karton, (int) ($this->artikel)('Elstar 13 kg Karton', 13.0, 'kg', 'KAR')->id);

    $stueck = $this->makeGp($this->rootTeam, 'Ananas Baby: ganz, frisch');
    app(LeadLaService::class)->verknuepfen($this->rootTeam, $stueck, (int) ($this->artikel)('Base Ananas', 0.035, 'kg', 'STK')->id);

    $gepflegt = $this->makeGp($this->rootTeam, 'Zitrone: frisch');
    app(GpFormService::class)->setForm($this->rootTeam, (int) $gepflegt->id, 'stk', 110.0, 'manual');
    app(LeadLaService::class)->verknuepfen($this->rootTeam, $gepflegt, (int) ($this->artikel)('Zitronen lose', 1.0, 'kg', 'KG')->id);

    Queue::assertNotPushed(StueckgewichtNachAnlageJob::class);

    $zweiter = $this->makeGp($this->rootTeam, 'Pfirsich: frisch');
    app(LeadLaService::class)->verknuepfen($this->rootTeam, $zweiter, (int) ($this->artikel)('Pfirsiche Karton', 5.0, 'kg', 'KAR')->id);
    app(LeadLaService::class)->verknuepfen($this->rootTeam, $zweiter, (int) ($this->artikel)('Pfirsiche lose', 1.0, 'kg', 'KG')->id);

    Queue::assertNotPushed(StueckgewichtNachAnlageJob::class);
});

it('Job: KI setzt das Stückgewicht (Herkunft ki) und ein Rezept mit „4 Stk“ rechnet danach mit Gramm', function () {
    $gp = $this->makeGp($this->rootTeam, 'Aprikose: ganz, frisch');
    $rezept = $this->makeRecipe($this->rootTeam, 'Chutney: Aprikose');
    FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $rezept->id, 'gp_id' => $gp->id,
        'raw_text' => '4 Aprikosen', 'quantity' => '4', 'position' => 1, 'unit_vocab_id' => FoodAlchemistVocabEinheit::where('slug', 'stk')->value('id')]);
    $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')->once()
        ->withArgs(fn (string $key) => $key === 'gp.zaehl_einheiten')
        ->andReturn(new AiProposal(['einheiten' => [['unit' => 'stk', 'gewicht_g' => 45]]], 0.8, 'Mock')));
    $recompute = app(RecipeRecomputeService::class);
    $zeile = fn () => FoodAlchemistRecipeIngredient::with(['unit', 'gp'])->where('recipe_id', $rezept->id)->first();
    $vorher = $recompute->bruttoMasseG($zeile());

    (new StueckgewichtNachAnlageJob((int) $this->rootTeam->id, (int) auth()->id(), (int) $gp->id))->handle(app(GpFormService::class), $recompute);

    expect($vorher)->toBe(0.0)
        ->and($recompute->bruttoMasseG($zeile()))->toBe(180.0)
        ->and(FoodAlchemistGpForm::where('gp_id', $gp->id)->where('form_slug', 'stk')->value('source'))->toBe('ki')
        ->and((float) $gp->refresh()->piece_default_g)->toBe(45.0);
});
