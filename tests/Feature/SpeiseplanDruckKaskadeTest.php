<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #3 (Bug-Runde 2026-08): Speiseplan-Druck „Richtung B" — Produktions-Kaskaden-Anhang je Wochen-
 * Gericht (?kaskade=1). Filter ist hier Mahlzeit+Woche (bestehend). EK nur intern. Gleicher Kern
 * (report-recipe-node + ReportExportService) wie Foodbook/Speisekarte.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $this->plan = app(SpeiseplanService::class);
});

it('#3: mitKaskade hängt den Produktions-Baum je Wochen-Gericht an; EK nur intern; Blade rendert', function () {
    $g = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'spk-c', 'name' => 'Gericht SP', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 4.0, 'ek_total_eur' => 1.5,
    ]);
    $sub = $this->makeRecipe($this->rootTeam, 'Sub SP', ['status' => 'draft', 'is_sales_recipe' => false]);
    FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $g->id, 'referenced_recipe_id' => $sub->id,
        'raw_text' => 'Sub', 'quantity' => '100', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1,
    ]);

    $sp = $this->plan->create($this->rootTeam, ['name' => 'Woche', 'start_date' => '2026-07-06']);
    $this->plan->addEintrag($this->rootTeam, $sp->id, ['entry_date' => '2026-07-06', 'mahlzeit' => 'mittag', 'sales_recipe_id' => $g->id]);

    // Kundensicht
    $data = $this->plan->dokumentDaten($this->rootTeam, $sp->refresh(), 'mittag', '2026-07-06', intern: false, mitKaskade: true);
    expect($data['kaskaden'])->toHaveCount(1)
        ->and((int) $data['kaskaden'][0]['recipe']['id'])->toBe($g->id)
        ->and($data['kaskaden'][0]['optionen']['ek'])->toBeFalse();
    $html = view('foodalchemist::dokumente.speiseplan', $data + ['istPdf' => false])->render();
    expect($html)->toContain('Produktions-Kaskade')->toContain('Gericht SP')->not->toContain('EK gesamt');

    // Interne Sicht: EK sichtbar
    $dataI = $this->plan->dokumentDaten($this->rootTeam, $sp->refresh(), 'mittag', '2026-07-06', intern: true, mitKaskade: true);
    expect($dataI['kaskaden'][0]['optionen']['ek'])->toBeTrue();
    $htmlI = view('foodalchemist::dokumente.speiseplan', $dataI + ['istPdf' => false])->render();
    expect($htmlI)->toContain('EK gesamt');

    // Ohne Flag: kein Anhang
    $ohne = $this->plan->dokumentDaten($this->rootTeam, $sp->refresh(), 'mittag', '2026-07-06', intern: false, mitKaskade: false);
    expect($ohne['kaskaden'])->toBe([]);
});
