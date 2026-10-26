<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistStammLieferant;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamFavorit;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Marken-Varianten im Matcher (Kurator 26.10.): GPs, die sich nur in der Marken-Klammer unterscheiden (§5 Ausnahme 3), starten mit gleichem Score —
 * die Klammer zählt nicht, ein GP ohne Klammer (marke_offen) hat keinen Vorteil. Bei Gleichstand: (a) Favorit des Teams, (b) Lead-Artikel vom
 * Stamm-Lieferanten der Team-Kette, (c) wie bisher (Variante, freigegeben, ID).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->gp = fn (string $name, ?string $zusatz = null, string $status = 'approved') => FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => $name,
        'gp_key' => 'k_' . bin2hex(random_bytes(4)), 'status' => $status, 'requires_la' => true, 'commodity_group_code' => '13', 'condition' => 'konserviert',
        'main_ingredient_slug' => 'sahnesauce', 'main_ingredient_display' => 'Sahnesauce', 'zusatz' => $zusatz]);
    $this->altoni = ($this->gp)('Sahnesauce: konserviert / (Altoni)', 'Altoni');          // niedrigste ID
    $this->holly = ($this->gp)('Sahnesauce: konserviert / (Hollyfood)', 'Hollyfood');
    $this->matcher = fn (string $zeile = 'Sahnesauce') => app(IngredientMatchService::class)->matchIngredient($this->rootTeam, $zeile);
});

it('ohne Favorit und Stamm entscheidet die bisherige Logik (gleiche Variante, beide freigegeben → kleinere ID)', function () {
    expect(($this->matcher)()['gp_id'])->toBe($this->altoni->id);
});

it('(a) Favorit des Teams gewinnt den Gleichstand — auch gegen die kleinere ID und unabhängig vom Prompt-Block', function () {
    FoodAlchemistTeamFavorit::create(['team_id' => $this->rootTeam->id, 'gp_id' => $this->holly->id, 'rang' => 1, 'ausgeschlossen' => false]);

    expect(($this->matcher)()['gp_id'])->toBe($this->holly->id);
});

it('(b) ohne Favorit: Lead-Artikel vom Stamm-Lieferanten der WG gewinnt; (a) schlägt (b)', function () {
    $stamm = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hollyfood GmbH']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $stamm->id, 'designation' => 'Sahnesauce Hollyfood', 'article_number' => 'H1']);
    $this->holly->update(['lead_la_supplier_item_id' => $la->id]);
    FoodAlchemistStammLieferant::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $stamm->id, 'commodity_group_code' => '13']);

    expect(($this->matcher)()['gp_id'])->toBe($this->holly->id);

    FoodAlchemistTeamFavorit::create(['team_id' => $this->rootTeam->id, 'gp_id' => $this->altoni->id, 'rang' => 1, 'ausgeschlossen' => false]);
    expect(($this->matcher)()['gp_id'])->toBe($this->altoni->id);   // Favorit vor Stamm
});

it('Score: Marken-Klammer zählt nicht — Varianten mit Marke und ohne Klammer (marke_offen) haben denselben Score', function () {
    $offen = ($this->gp)('Sahnesauce: konserviert');
    $offen->update(['marke_offen' => true, 'rezepturware' => true]);

    $scores = collect(app(IngredientMatchService::class)->candidatesFor($this->rootTeam, 'Sahnesauce', null, 5))
        ->where('kind', 'gp')->pluck('score', 'id');
    expect($scores[$this->altoni->id])->toBe($scores[$this->holly->id])->and($scores[$offen->id])->toBe($scores[$this->altoni->id]);
});

it('Kandidatenliste: bei gleichem Score steht der Favorit vorn, ohne Favorit die bisherige Reihenfolge', function () {
    $ohne = collect(app(IngredientMatchService::class)->candidatesFor($this->rootTeam, 'Sahnesauce', null, 5))->where('kind', 'gp')->pluck('id')->values()->all();
    expect($ohne[0])->toBe($this->altoni->id);

    FoodAlchemistTeamFavorit::create(['team_id' => $this->rootTeam->id, 'gp_id' => $this->holly->id, 'rang' => 1, 'ausgeschlossen' => false]);
    $mit = collect(app(IngredientMatchService::class)->candidatesFor($this->rootTeam, 'Sahnesauce', null, 5))->where('kind', 'gp')->pluck('id')->values()->all();
    expect($mit[0])->toBe($this->holly->id);
});

it('Paar X ⇄ unX: nennt die Zutat keins, gewinnt „ungesalzen“ gegen „gesalzen / (Marke)“ — auch gegen die kleinere ID; nennt sie „gesalzen“, gilt das', function () {
    $erdnuss = fn (string $name, ?string $zusatz = null) => FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => $name,
        'gp_key' => 'k_' . bin2hex(random_bytes(4)), 'status' => 'approved', 'requires_la' => true, 'commodity_group_code' => '06', 'condition' => 'trocken',
        'main_ingredient_slug' => 'erdnuss', 'main_ingredient_display' => 'Erdnüsse', 'zusatz' => $zusatz]);
    $gesalzen = $erdnuss('Erdnüsse: geröstet, gesalzen, trocken / (Ültje)', 'Ültje');      // kleinere ID; Reihenfolge Stufe 2 (Form vor Zustand)
    $ungesalzen = $erdnuss('Erdnüsse: geröstet, ungesalzen, trocken');

    expect(($this->matcher)('150 g Erdnüsse, geröstet')['gp_id'])->toBe($ungesalzen->id)
        ->and(collect(app(IngredientMatchService::class)->candidatesFor($this->rootTeam, 'Erdnüsse, geröstet', null, 5))->where('kind', 'gp')->pluck('id')->first())
        ->toBe($ungesalzen->id)
        ->and(($this->matcher)('30 g Erdnüsse, geröstet, gesalzen')['gp_id'])->toBe($gesalzen->id);
});

it('nameOhneMarke: Zusatz aus der Spalte, Bestand ohne Spalte; Bio/Vegan/Glutenfrei/Laktosefrei bleiben', function () {
    $n = app(GpNamingService::class);

    expect($n->nameOhneMarke('Sahnesauce: konserviert / (Altoni)', 'Altoni'))->toBe('Sahnesauce: konserviert')
        ->and($n->nameOhneMarke('Sahnesauce: konserviert / (Altoni) / (Bio)', 'Altoni'))->toBe('Sahnesauce: konserviert / (Bio)')
        ->and($n->nameOhneMarke('Sojasauce: konserviert / (Megachef) / (Glutenfrei)'))->toBe('Sojasauce: konserviert / (Glutenfrei)')
        ->and($n->nameOhneMarke('Ketchup / (Heinz)'))->toBe('Ketchup')
        ->and($n->nameOhneMarke('Butter: frisch / (Bio) / (Laktosefrei)'))->toBe('Butter: frisch / (Bio) / (Laktosefrei)')
        ->and($n->nameOhneMarke('Tofu: frisch / (Vegan)', ''))->toBe('Tofu: frisch / (Vegan)');
});

it('nameOhneMarke passt zum Renderer (Stufe 2, Form vor Zustand): mit Marke gerendert, Marke entfernt = ohne Marke gerendert; Flags bleiben', function () {
    $n = app(GpNamingService::class);
    $felder = ['hauptzutat' => 'Räucherlachs', 'condition' => 'geräuchert', 'form' => 'geschnitten', 'bio' => true, 'glutenfrei' => true];
    $mit = $n->renderGpName($felder + ['zusatz' => 'Friedrichs']);

    expect($mit)->toContain('/ (Friedrichs)')
        ->and($n->nameOhneMarke($mit, 'Friedrichs'))->toBe($n->renderGpName($felder))
        ->and($n->nameOhneMarke($mit))->toBe($n->renderGpName($felder));
});
