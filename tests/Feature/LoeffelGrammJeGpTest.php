<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGpForm;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\GpFormService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Löffel je Grundprodukt (Dominique 03.11.: „für die Löffel müsste man hinterlegen, wie viel Gramm das ist“). EL/TL/Msp/Prise sind
 * Formen des GP wie die Zähl-Einheiten — die KI schätzt (Herkunft ki), der Mensch überschreibt. Die Gramm-Kaskade selbst prüft der
 * Wächter in EditorEkStueckBrueckeTest (Anzeige = Buchung, mit und ohne Löffel-Form).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $nutzer = $this->makeUser($this->rootTeam, 'Root User');
    $this->actingAs($nutzer);
    FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'tl'], ['display_de' => 'Teelöffel', 'dimension' => 'volume', 'default_in_ml' => 5]);
    FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'el'], ['display_de' => 'Esslöffel', 'dimension' => 'volume', 'default_in_ml' => 15]);
    FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'ml'], ['display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_ml' => 1]);
    FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'zehe'], ['display_de' => 'Zehe', 'dimension' => 'count']);
});

it('Löffel sind Formen, gleich welche Dimension das Vokabular ihnen gibt — andere Volumen-Einheiten nicht', function () {
    $slugs = GpFormService::formSlugs($this->rootTeam);

    expect($slugs)->toContain('tl')->toContain('el')->toContain('zehe')->not->toContain('ml');
});

it('KI-Schätzung schreibt Löffel-Formen mit Herkunft ki — eine manuelle Form bleibt', function () {
    $zimt = $this->makeGp($this->rootTeam, 'Zimt: gemahlen, trocken');
    app(GpFormService::class)->setForm($this->rootTeam, (int) $zimt->id, 'el', 7.0, 'manual');
    $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')->once()
        ->withArgs(fn (string $key, array $ctx) => $key === 'gp.zaehl_einheiten' && in_array('tl', $ctx['erlaubte_einheiten'], true))
        ->andReturn(new AiProposal(['einheiten' => [['unit' => 'tl', 'gewicht_g' => 2.5], ['unit' => 'el', 'gewicht_g' => 8.0]]], 0.8, 'Mock')));

    $n = app(GpFormService::class)->estimateKi($this->rootTeam, (int) $zimt->id);
    $formen = FoodAlchemistGpForm::where('gp_id', $zimt->id)->get()->keyBy('form_slug');

    expect($n)->toBe(1)
        ->and((float) $formen['tl']->gramm)->toBe(2.5)
        ->and($formen['tl']->source)->toBe('ki')
        ->and((float) $formen['el']->gramm)->toBe(7.0)    // Override-First
        ->and($formen['el']->source)->toBe('manual');
});

it('Import: eine TL-Zeile ohne TL-Gewicht am GP löst die Schätzung im Hintergrund aus — mit Gewicht nicht', function () {
    config(['foodalchemist.ai.provider' => 'core']);
    \Illuminate\Support\Facades\Queue::fake();
    $zimt = $this->makeGp($this->rootTeam, 'Zimt: gemahlen, trocken');
    $rezept = $this->makeRecipe($this->rootTeam, 'Zimtzucker');
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $rezept->id, 'gp_id' => $zimt->id,
        'raw_text' => '1 TL Zimt', 'quantity' => '1', 'position' => 1,
        'unit_vocab_id' => FoodAlchemistVocabEinheit::where('slug', 'tl')->value('id')]);

    expect(\Platform\FoodAlchemist\Support\LoeffelGewichtNachholen::fehlende($rezept))->toBe([(int) $zimt->id]);
    \Platform\FoodAlchemist\Support\LoeffelGewichtNachholen::ausloesen($this->rootTeam, $rezept);
    \Illuminate\Support\Facades\Queue::assertPushed(\Platform\FoodAlchemist\Jobs\LoeffelGewichtNachholenJob::class,
        fn ($job) => $job->recipeId === $rezept->id && $job->gpIds === [(int) $zimt->id]);

    app(GpFormService::class)->setForm($this->rootTeam, (int) $zimt->id, 'tl', 2.5, 'manual');
    expect(\Platform\FoodAlchemist\Support\LoeffelGewichtNachholen::fehlende($rezept))->toBe([]);
});

it('Job: KI schätzt das TL-Gewicht (Herkunft ki) und das Rezept rechnet danach mit 2,5 g statt 5 ml', function () {
    $zimt = $this->makeGp($this->rootTeam, 'Zimt: gemahlen, trocken');
    $rezept = $this->makeRecipe($this->rootTeam, 'Zimtzucker');
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $rezept->id, 'gp_id' => $zimt->id,
        'raw_text' => '2 TL Zimt', 'quantity' => '2', 'position' => 1,
        'unit_vocab_id' => FoodAlchemistVocabEinheit::where('slug', 'tl')->value('id')]);
    $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')->once()
        ->andReturn(new AiProposal(['einheiten' => [['unit' => 'tl', 'gewicht_g' => 2.5]]], 0.8, 'Mock')));
    $recompute = app(\Platform\FoodAlchemist\Services\RecipeRecomputeService::class);
    $zeile = fn () => \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::with(['unit', 'gp'])->where('recipe_id', $rezept->id)->first();
    $vorher = $recompute->bruttoMasseG($zeile());

    (new \Platform\FoodAlchemist\Jobs\LoeffelGewichtNachholenJob((int) $this->rootTeam->id, (int) auth()->id(), (int) $rezept->id, [(int) $zimt->id]))
        ->handle(app(GpFormService::class), $recompute);

    expect($vorher)->toBe(10.0)
        ->and($recompute->bruttoMasseG($zeile()))->toBe(5.0)
        ->and(FoodAlchemistGpForm::where('gp_id', $zimt->id)->where('form_slug', 'tl')->value('source'))->toBe('ki');
});
