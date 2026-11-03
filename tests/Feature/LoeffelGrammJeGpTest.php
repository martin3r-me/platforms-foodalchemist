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
