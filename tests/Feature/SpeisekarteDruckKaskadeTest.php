<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #3 (Bug-Runde 2026-08): Speisekarte-Druck „Richtung B" — Produktions-Kaskaden-Anhang je Gericht
 * (?kaskade=1) + Rubrik-Filter (?rubrik=…). EK nur intern (Kundensicht ohne Kosten). Gleicher Kern
 * wie Foodbook (report-recipe-node + ReportExportService).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $this->karten = app(SpeisekarteService::class);
});

it('#3: mitKaskade hängt den Produktions-Baum an; EK nur intern; Blade rendert', function () {
    $g = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'skk-c', 'name' => 'Gericht SK', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 20.0, 'ek_total_eur' => 6.0,
    ]);
    $sub = $this->makeRecipe($this->rootTeam, 'Sub SK', ['status' => 'draft', 'is_sales_recipe' => false]);
    FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $g->id, 'referenced_recipe_id' => $sub->id,
        'raw_text' => 'Sub', 'quantity' => '100', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1,
    ]);

    $karte = $this->karten->create($this->rootTeam, ['name' => 'Testkarte']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge']);
    $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $g->id]);

    // Kundensicht
    $data = $this->karten->dokumentDaten($this->rootTeam, $karte->refresh(), intern: false, rubrikFilter: [], mitKaskade: true);
    expect($data['kaskaden'])->toHaveCount(1)
        ->and((int) $data['kaskaden'][0]['recipe']['id'])->toBe($g->id)
        ->and($data['kaskaden'][0]['optionen']['ek'])->toBeFalse();
    $html = view('foodalchemist::dokumente.speisekarte', $data + ['istPdf' => false])->render();
    expect($html)->toContain('Produktions-Kaskade')->toContain('Gericht SK')->not->toContain('EK gesamt');

    // Interne Sicht: EK sichtbar
    $dataI = $this->karten->dokumentDaten($this->rootTeam, $karte->refresh(), intern: true, rubrikFilter: [], mitKaskade: true);
    expect($dataI['kaskaden'][0]['optionen']['ek'])->toBeTrue();
    $htmlI = view('foodalchemist::dokumente.speisekarte', $dataI + ['istPdf' => false])->render();
    expect($htmlI)->toContain('EK gesamt');

    // Ohne Flag: kein Anhang
    $ohne = $this->karten->dokumentDaten($this->rootTeam, $karte->refresh(), intern: false, rubrikFilter: [], mitKaskade: false);
    expect($ohne['kaskaden'])->toBe([]);
});

it('#3: rubrikFilter beschränkt die gerenderten Rubriken', function () {
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $r1 = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Vorspeisen']);
    $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge']);

    $alle = $this->karten->dokumentDaten($this->rootTeam, $karte->refresh());
    $nur1 = $this->karten->dokumentDaten($this->rootTeam, $karte->refresh(), intern: false, rubrikFilter: [$r1->id], mitKaskade: false);

    expect(collect($alle['rubriken'])->pluck('title')->all())->toContain('Vorspeisen', 'Hauptgänge');
    expect(collect($nur1['rubriken'])->pluck('title')->all())->toContain('Vorspeisen')->not->toContain('Hauptgänge');
});
