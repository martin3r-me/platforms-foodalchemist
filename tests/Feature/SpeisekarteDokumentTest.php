<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Speisekarte Stufe B — das Druck-Dokument-Blade rendert (HTML-Preview) mit Rubriken,
 * Positionen, Brutto-Preis, Allergen-Legende + LMIV-Disclaimer.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->karten = app(SpeisekarteService::class);
});

it('Stufe B: dokumente.speisekarte-Blade rendert Karte + Preis + Allergen-Legende', function () {
    $g = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'skd1', 'name' => 'Wiener Schnitzel', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 21.00, 'ek_total_eur' => 7.00,
    ]);
    $g->forceFill(['allergens_confidence' => 'high', 'allergen_gluten' => 'enthalten'])->save();

    $karte = $this->karten->create($this->rootTeam, ['name' => 'Abendkarte']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge']);
    $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $g->id]);

    $data = $this->karten->dokumentDaten($this->rootTeam, $karte->refresh());
    $html = view('foodalchemist::dokumente.speisekarte', $data + ['istPdf' => false])->render();

    expect($html)
        ->toContain('Abendkarte')
        ->toContain('Wiener Schnitzel')
        ->toContain('Hauptgänge')
        ->toContain('MwSt')            // Brutto-Preis-Hinweis
        ->toContain('Allergene')       // Legende
        ->toContain('LMIV');           // Disclaimer
    // Brutto-Preis 21,00 × 1,19 = 24,99 taucht auf
    expect($html)->toContain('24,99');
});

// ── Werkstrang M Phase D-Renderer (Spec 40 §6): Wahl-Gruppe „A oder B" im Druck ───────────────

it('Phase D-Renderer: gleiche variant_group_id erscheint als „oder" zwischen Positionen', function () {
    $a = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'skvg-a', 'name' => 'Rinderfilet', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 30.00,
    ]);
    $b = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'skvg-b', 'name' => 'Lachsfilet', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 28.00,
    ]);
    $karte = $this->karten->create($this->rootTeam, ['name' => 'Menükarte']);
    $r = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgang']);
    // Beide in derselben Wahl-Gruppe (1), benachbart.
    $this->karten->addPosition($this->rootTeam, $r->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $a->id, 'variant_group_id' => 1]);
    $this->karten->addPosition($this->rootTeam, $r->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $b->id, 'variant_group_id' => 1]);

    $data = $this->karten->dokumentDaten($this->rootTeam, $karte->refresh());
    $html = view('foodalchemist::dokumente.speisekarte', $data + ['istPdf' => false])->render();

    // Spezifisch die „oder"-Trennzelle (nicht der LMIV-Disclaimer-Text).
    expect($html)->toContain('Rinderfilet')->toContain('Lachsfilet')->toContain('>oder</td>');
});

it('Phase D-Renderer: ohne Wahl-Gruppe KEIN „oder"-Trenner', function () {
    $a = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sknovg-a', 'name' => 'Rinderfilet', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 30.00,
    ]);
    $b = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sknovg-b', 'name' => 'Lachsfilet', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 28.00,
    ]);
    $karte = $this->karten->create($this->rootTeam, ['name' => 'Menükarte']);
    $r = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgang']);
    $this->karten->addPosition($this->rootTeam, $r->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $a->id]);
    $this->karten->addPosition($this->rootTeam, $r->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $b->id]);

    $data = $this->karten->dokumentDaten($this->rootTeam, $karte->refresh());
    $html = view('foodalchemist::dokumente.speisekarte', $data + ['istPdf' => false])->render();

    expect($html)->not->toContain('>oder</td>');
});
