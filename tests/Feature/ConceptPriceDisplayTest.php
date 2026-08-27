<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\LeitstelleService;
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

it('Foodbook-Editor: detail() lädt price_display → istEinzelpreis am Block-Concept greift (Regression detail-Subset-Falle)', function () {
    // detail() lud das Concept mit Spalten-Subset OHNE price_display → der Editor-Chip las
    // istEinzelpreis() auf einer Instanz ohne die Spalte → immer gesamt. Diese Regression fixiert,
    // dass price_display im Editor-Load enthalten ist.
    $einzel = ($this->einzelSetup)('einzel');
    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'code' => 'FB-DET', 'label' => 'Det',
        'jahr' => 2027, 'personen' => 10, 'status' => 'draft']);
    $kap = $fb->kapitel()->create(['team_id' => $this->rootTeam->id, 'title' => 'K', 'position' => 0]);
    $kap->blocks()->create(['team_id' => $this->rootTeam->id, 'type' => 'concept_ref',
        'concept_id' => $einzel->id, 'position' => 0, 'visible' => true]);

    $geladen = $this->fbsvc->detail($this->rootTeam, $fb->id);
    $block = $geladen->chapters->first()->blocks->first();
    expect($block->concept->istEinzelpreis())->toBeTrue();
});

it('Kapitel-Board: depth-first Baum-Reihenfolge — Kind direkt unter Eltern trotz position-Drift', function () {
    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'code' => 'FB-TREE', 'label' => 'Tree',
        'jahr' => 2027, 'personen' => 10, 'status' => 'draft']);
    // Flache position-Reihenfolge (A, B, A-Kind, B-Kind) folgt NICHT der Baumstruktur —
    // das Board muss depth-first umsortieren: A, A-Kind, B, B-Kind.
    $a = $fb->kapitel()->create(['team_id' => $this->rootTeam->id, 'title' => 'A', 'position' => 0]);
    $b = $fb->kapitel()->create(['team_id' => $this->rootTeam->id, 'title' => 'B', 'position' => 1]);
    $fb->kapitel()->create(['team_id' => $this->rootTeam->id, 'title' => 'A-Kind', 'parent_id' => $a->id, 'position' => 2]);
    $fb->kapitel()->create(['team_id' => $this->rootTeam->id, 'title' => 'B-Kind', 'parent_id' => $b->id, 'position' => 5]);

    $board = app(LeitstelleService::class)->kapitelBoard($this->rootTeam, $fb->fresh());
    expect(collect($board)->pluck('titel')->all())->toBe(['A', 'A-Kind', 'B', 'B-Kind']);
});

it('Kapitel-Fortschritt: Default offen · Setter persistiert · Enum-Guard', function () {
    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'code' => 'FB-FS', 'label' => 'FS',
        'jahr' => 2027, 'personen' => 10, 'status' => 'draft']);
    $kap = $fb->kapitel()->create(['team_id' => $this->rootTeam->id, 'title' => 'K', 'position' => 0]);

    // Default = offen im Board.
    $board = app(LeitstelleService::class)->kapitelBoard($this->rootTeam, $fb->fresh());
    expect($board[0]['fortschritt'])->toBe('offen');

    // Setter persistiert.
    Livewire::test(\Platform\FoodAlchemist\Livewire\Foodbooks\Index::class)
        ->call('kapitelFortschritt', $kap->id, 'fertig');
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::find($kap->id)->fortschritt)->toBe('fertig');

    // Enum-Guard: ungültiger Wert wird ignoriert (bleibt beim letzten gültigen).
    Livewire::test(\Platform\FoodAlchemist\Livewire\Foodbooks\Index::class)
        ->call('kapitelFortschritt', $kap->id, 'quatsch');
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::find($kap->id)->fortschritt)->toBe('fertig');
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
