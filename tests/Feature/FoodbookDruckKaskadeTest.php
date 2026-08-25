<?php

use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #3 (Bug-Runde 2026-08): Foodbook-Druck „Richtung B" — Produktions-Kaskaden-Anhang je Gericht
 * (optional, ?kaskade=1) + Kapitel-Filter (?kapitel=…). EK/Food-Cost je Knoten nur in der internen
 * Projektion (Kundensicht: Struktur+Mengen ohne Kosten). Getestet wird die Service-Datenschicht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $this->svc = app(FoodbookService::class);

    $this->makeFb = fn () => FoodAlchemistFoodbook::create([
        'team_id' => $this->rootTeam->id, 'code' => 'FB-T', 'label' => 'Test-FB',
        'jahr' => 2027, 'customer' => 'Kunde', 'personen' => 10, 'status' => 'draft',
    ]);
});

it('#3: mitKaskade hängt den Produktions-Baum je Gericht an; EK nur intern', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Gericht X', ['status' => 'draft', 'is_sales_recipe' => true]);
    $sub = $this->makeRecipe($this->rootTeam, 'Sub-Basis', ['status' => 'draft', 'is_sales_recipe' => false]);
    FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $gericht->id, 'referenced_recipe_id' => $sub->id,
        'raw_text' => 'Sub', 'quantity' => '100', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1,
    ]);

    $fb = ($this->makeFb)();
    $kap = $fb->kapitel()->create(['team_id' => $this->rootTeam->id, 'title' => 'Kap 1', 'position' => 0]);
    $kap->blocks()->create(['team_id' => $this->rootTeam->id, 'type' => 'recipe_ref',
        'sales_recipe_id' => $gericht->id, 'quantity' => 1, 'position' => 0, 'visible' => true]);

    // Kundensicht: Kaskade da, EK-Flag aus
    $kunde = $this->svc->dokumentDaten($this->rootTeam, $fb->fresh(), intern: false, kapitelFilter: [], mitKaskade: true);
    expect($kunde['kaskaden'])->toHaveCount(1)
        ->and((int) $kunde['kaskaden'][0]['recipe']['id'])->toBe($gericht->id)
        ->and($kunde['kaskaden'][0]['optionen']['ek'])->toBeFalse();

    // Intern: EK-Flag an
    $intern = $this->svc->dokumentDaten($this->rootTeam, $fb->fresh(), intern: true, kapitelFilter: [], mitKaskade: true);
    expect($intern['kaskaden'][0]['optionen']['ek'])->toBeTrue();

    // Ohne Flag: kein Anhang (bestehendes Verhalten unverändert)
    $ohne = $this->svc->dokumentDaten($this->rootTeam, $fb->fresh(), intern: false, kapitelFilter: [], mitKaskade: false);
    expect($ohne['kaskaden'])->toBe([]);
});

it('#5b: §-Codes PRO GERICHT + Legende (nur real vorkommende Allergene/Zusatzstoffe)', function () {
    // Gericht mit Gluten (enthalten) + Milch (Spuren) — der Rest bleibt nicht_enthalten (makeRecipe-Default).
    $gericht = $this->makeRecipe($this->rootTeam, 'Pasta Allergen', [
        'is_sales_recipe' => true, 'allergen_gluten' => 'enthalten', 'allergen_milk' => 'spuren',
    ]);
    $fb = ($this->makeFb)();
    $kap = $fb->kapitel()->create(['team_id' => $this->rootTeam->id, 'title' => 'Kap', 'position' => 0]);
    $kap->blocks()->create(['team_id' => $this->rootTeam->id, 'type' => 'recipe_ref',
        'sales_recipe_id' => $gericht->id, 'quantity' => 1, 'position' => 0, 'visible' => true]);

    $data = $this->svc->dokumentDaten($this->rootTeam, $fb->fresh());
    // Codes hängen am recipe_ref-Block (Einzelgericht = eigenes Gericht), nicht auf Konzept-Ebene.
    $block = collect($data['kapitel'])->firstWhere('title', 'Kap')['bloecke'][0];
    expect($block['codes'])->not->toBeEmpty()
        ->and(collect($block['codes'])->contains(fn ($c) => str_contains($c, '*')))->toBeTrue();  // Milch = Spuren → *
    // Legende führt genau die vorkommenden Allergene (Gluten + Milch), keine der nicht_enthaltenen.
    $algLabels = collect($data['legende']['allergene'])->pluck('label')->all();
    expect(count($algLabels))->toBe(2);

    // HTML: Codes am Gericht + Legende-Block „Allergene" ganz unten.
    $html = view('foodalchemist::dokumente.foodbook', $data + ['istPdf' => false])->render();
    expect($html)->toContain('Pasta Allergen')->toContain('class="legende"')->toContain('Allergene');
});

it('#3: kapitelFilter beschränkt die gerenderten Kapitel', function () {
    $fb = ($this->makeFb)();
    $k1 = $fb->kapitel()->create(['team_id' => $this->rootTeam->id, 'title' => 'Vorspeisen', 'position' => 0]);
    $k2 = $fb->kapitel()->create(['team_id' => $this->rootTeam->id, 'title' => 'Hauptgang', 'position' => 1]);

    $alle = $this->svc->dokumentDaten($this->rootTeam, $fb->fresh());
    $nur1 = $this->svc->dokumentDaten($this->rootTeam, $fb->fresh(), intern: false, kapitelFilter: [$k1->id], mitKaskade: false);

    expect(collect($alle['kapitel'])->pluck('title')->all())->toContain('Vorspeisen', 'Hauptgang');
    expect(collect($nur1['kapitel'])->pluck('title')->all())->toContain('Vorspeisen')->not->toContain('Hauptgang');
});

it('#5a: das Foodbook-Dokument rendert die Produktions-Kaskade NICHT mehr (die lebt im Report)', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Gericht Render', ['status' => 'draft', 'is_sales_recipe' => true]);
    $this->makeIngredient($gericht, 'Salz', null, '5', 1);

    $fb = ($this->makeFb)();
    $kap = $fb->kapitel()->create(['team_id' => $this->rootTeam->id, 'title' => 'Kap', 'position' => 0]);
    $kap->blocks()->create(['team_id' => $this->rootTeam->id, 'type' => 'recipe_ref',
        'sales_recipe_id' => $gericht->id, 'quantity' => 1, 'position' => 0, 'visible' => true]);

    // #5a: das Dokument zeigt das Gericht + den Allergen-Toggle, aber KEINE Produktions-Kaskade mehr.
    $data = $this->svc->dokumentDaten($this->rootTeam, $fb->fresh()) + ['deklaration' => true];
    $html = view('foodalchemist::dokumente.foodbook', $data + ['istPdf' => false])->render();
    expect($html)->toContain('Gericht Render')
        ->toContain('ohne Allergene')            // der neue Deklarations-Schalter
        ->not->toContain('Produktions-Kaskade'); // Kaskade ist raus (jetzt im foodbooks.report)
});
