<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\WordingResolver;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Concept-Preisdarstellung (Dominique 2026-08-25): `gesamt` (ein Summenpreis fürs Concept —
 * heutiges Verhalten) vs. `einzel` (jedes direkte Kind zeigt seinen eigenen Preis, kein
 * Concept-Summenpreis — Auswahl à la carte wie Kuchen/Fingerfood). Reine Concept-Eigenschaft;
 * Foodbook/Format/Speisekarte geben nur durch. Default `gesamt` = non-breaking.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->wsvc = app(WordingResolver::class);
    $this->fbsvc = app(FoodbookService::class);

    // Zwei-Gericht-Auswahl-Concept mit gegebener Preisdarstellung (Closure an $this gebunden,
    // damit die protected Fixture-Helfer erreichbar bleiben).
    $this->einzelSetup = function (string $display): FoodAlchemistConcept {
        $a = $this->makeRecipe($this->rootTeam, 'Kuchen A', ['is_sales_recipe' => true, 'sales_net' => 2.0, 'ek_total_eur' => 0.5]);
        $b = $this->makeRecipe($this->rootTeam, 'Kuchen B', ['is_sales_recipe' => true, 'sales_net' => 2.5, 'ek_total_eur' => 0.75]);
        $c = $this->makeConcept($this->rootTeam, 'Kuchenauswahl', ['kind' => 'concept', 'status' => 'active', 'price_display' => $display]);
        $this->makeConceptSlot($c, ['sales_recipe_id' => $a->id, 'position' => 1, 'wording' => 'Kuchen A']);
        $this->makeConceptSlot($c, ['sales_recipe_id' => $b->id, 'position' => 2, 'wording' => 'Kuchen B']);

        return $c->fresh();
    };
});

it('Default ist gesamt (non-breaking) — Bestand ohne gesetztes Feld bleibt Summenpreis', function () {
    $c = $this->makeConcept($this->rootTeam, 'Menü', ['kind' => 'concept', 'status' => 'active']);
    expect($c->fresh()->price_display)->toBe('gesamt')
        ->and($c->fresh()->istEinzelpreis())->toBeFalse();
});

it('einzel: gerichtZeilen hängt je Gericht VK + EK an; gesamt lässt beide weg', function () {
    $einzel = ($this->einzelSetup)('einzel');
    $zeilen = collect($this->wsvc->gerichtZeilen($einzel))->where('type', 'gericht')->values();
    expect($zeilen)->toHaveCount(2)
        ->and((float) $zeilen[0]['preis'])->toBe(2.0)
        ->and((float) $zeilen[1]['preis'])->toBe(2.5)
        // EK je Gericht (für die interne Sicht) — aus ek_total_eur der Fixture.
        ->and((float) $zeilen[0]['ek'])->toBe(0.5)
        ->and((float) $zeilen[1]['ek'])->toBe(0.75);

    $gesamt = ($this->einzelSetup)('gesamt');
    $zeilenG = collect($this->wsvc->gerichtZeilen($gesamt))->where('type', 'gericht')->values();
    expect($zeilenG)->toHaveCount(2)
        ->and($zeilenG[0]['preis'] ?? null)->toBeNull()
        ->and($zeilenG[0]['ek'] ?? null)->toBeNull()
        ->and($zeilenG[1]['preis'] ?? null)->toBeNull();
});

it('Foodbook-Dokument: einzel-Block trägt das einzelpreise-Flag + Preise je Gericht-Zeile', function () {
    $einzel = ($this->einzelSetup)('einzel');
    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'code' => 'FB-PD', 'label' => 'PD',
        'jahr' => 2027, 'personen' => 10, 'status' => 'draft']);
    $kap = $fb->kapitel()->create(['team_id' => $this->rootTeam->id, 'title' => 'Kuchen', 'position' => 0]);
    $kap->blocks()->create(['team_id' => $this->rootTeam->id, 'type' => 'concept_ref',
        'concept_id' => $einzel->id, 'position' => 0, 'visible' => true]);

    $daten = $this->fbsvc->dokumentDaten($this->rootTeam, $fb->fresh(), intern: true);
    $block = collect($daten['kapitel'])->firstWhere('title', 'Kuchen')['bloecke'][0];

    expect($block['einzelpreise'])->toBeTrue()
        ->and(collect($block['gerichte'])->where('type', 'gericht')->pluck('preis')->filter()->count())->toBe(2);
});

it('Concepter-Editor: setPreisDisplay persistiert die Preisdarstellung', function () {
    $c = $this->makeConcept($this->rootTeam, 'Auswahl', ['kind' => 'concept', 'status' => 'active']);

    Livewire::test(Editor::class)->call('oeffnen', 'concepts', $c->id)
        ->call('setPreisDisplay', 'einzel')
        ->assertHasNoErrors();

    expect(FoodAlchemistConcept::find($c->id)->price_display)->toBe('einzel');

    // Ungültiger Wert fällt auf gesamt zurück (Enum-Guard).
    Livewire::test(Editor::class)->call('oeffnen', 'concepts', $c->id)
        ->call('setPreisDisplay', 'quatsch');
    expect(FoodAlchemistConcept::find($c->id)->price_display)->toBe('gesamt');
});
