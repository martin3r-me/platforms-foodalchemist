<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * E3 (#508): Revise-Re-Grounding. syncIngredients war ein reiner Persister — eine
 * KI-überarbeitete Zutat OHNE gp_id/referenced_recipe_id landete als
 * match_method='unmatched', wodurch EK-/Allergen-Aggregation bis zum Hand-Mapping
 * brach. Jetzt läuft jede solche Zeile durch den GL-04-Resolver (deterministisch,
 * kein Embedding nötig). Kein Treffer über der Schwelle ⇒ bleibt unmatched
 * (Hard-Stop-UI). Bestehende zuvor unmatched Zeilen werden bei Re-Sync rehabilitiert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(RecipeService::class);
    $this->g = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ]);
    $this->rezept = $this->svc->create($this->rootTeam, ['name' => 'Rinderbraten: Test']);

    $this->mkGp = fn (string $name) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'gp_key' => 'e3|' . mb_strtolower(str_replace([' ', ',', ':'], ['-', '', ''], $name)),
        'name' => $name, 'status' => 'approved', 'is_platzhalter' => false,
    ]);

    $this->firstIngredient = fn () => $this->rezept->refresh()->ingredients()->first();
});

it('groundet eine KI-Zutat ohne gp_id auf ein existierendes GP (statt unmatched)', function () {
    $gp = ($this->mkGp)('Karotten: frisch');

    $this->svc->syncIngredients($this->rootTeam, $this->rezept->id, [
        ['id' => null, 'gp_id' => null, 'display_name' => 'Karotten', 'raw_text' => '500 g Karotten',
         'quantity' => '500', 'unit_vocab_id' => $this->g->id],
    ]);

    $z = ($this->firstIngredient)();
    expect((int) $z->gp_id)->toBe((int) $gp->id)
        ->and($z->match_method?->value ?? $z->match_method)->toBe('gp_v2_fk')
        ->and((float) $z->match_confidence)->toBeGreaterThan(0.0);
});

it('lässt eine Zutat ohne Treffer unmatched (Hard-Stop bleibt)', function () {
    ($this->mkGp)('Karotten: frisch');

    $this->svc->syncIngredients($this->rootTeam, $this->rezept->id, [
        ['id' => null, 'gp_id' => null, 'display_name' => 'Quijibo Wurzel XYZ', 'raw_text' => 'Quijibo Wurzel XYZ',
         'quantity' => '10', 'unit_vocab_id' => $this->g->id],
    ]);

    $z = ($this->firstIngredient)();
    expect($z->gp_id)->toBeNull()
        ->and($z->match_method?->value ?? $z->match_method)->toBe('unmatched');
});

it('rehabilitiert eine bestehende unmatched Zeile beim Re-Sync, sobald das GP existiert', function () {
    // 1) Erst-Sync ohne passendes GP → unmatched.
    $this->svc->syncIngredients($this->rootTeam, $this->rezept->id, [
        ['id' => null, 'gp_id' => null, 'display_name' => 'Sellerieknolle', 'raw_text' => 'Sellerieknolle',
         'quantity' => '200', 'unit_vocab_id' => $this->g->id],
    ]);
    $z = ($this->firstIngredient)();
    expect($z->match_method?->value ?? $z->match_method)->toBe('unmatched');

    // 2) GP wird angelegt, Zeile mit ihrer id erneut gesynct (weiter ohne gp_id).
    $gp = ($this->mkGp)('Sellerieknolle: frisch');
    $this->svc->syncIngredients($this->rootTeam, $this->rezept->id, [
        ['id' => $z->id, 'gp_id' => null, 'display_name' => 'Sellerieknolle', 'raw_text' => 'Sellerieknolle',
         'quantity' => '200', 'unit_vocab_id' => $this->g->id],
    ]);

    $z2 = ($this->firstIngredient)();
    expect((int) $z2->id)->toBe((int) $z->id)               // dieselbe Zeile
        ->and((int) $z2->gp_id)->toBe((int) $gp->id)
        ->and($z2->match_method?->value ?? $z2->match_method)->toBe('gp_v2_fk');
});

it('respektiert ein explizit gesetztes gp_id (kein Re-Grounding-Override)', function () {
    $richtig = ($this->mkGp)('Kalbfleisch: frisch');
    ($this->mkGp)('Karotten: frisch');   // Distraktor, den der Matcher NICHT wählen soll

    $this->svc->syncIngredients($this->rootTeam, $this->rezept->id, [
        ['id' => null, 'gp_id' => $richtig->id, 'display_name' => 'Karotten', 'raw_text' => 'Karotten',
         'quantity' => '300', 'unit_vocab_id' => $this->g->id],
    ]);

    // gp_id war gesetzt → Grounding überschreibt nicht (bleibt beim expliziten GP).
    expect((int) ($this->firstIngredient)()->gp_id)->toBe((int) $richtig->id);
});

it('matchVorschau zeigt den künftigen Grounding-Status je Zutat (Hard-Stop-Vorschau)', function () {
    $gpK = ($this->mkGp)('Karotten: frisch');
    ($this->mkGp)('Sellerieknolle: frisch');

    // Eine bereits verknüpfte Bestands-Zutat anlegen.
    $this->svc->syncIngredients($this->rootTeam, $this->rezept->id, [
        ['id' => null, 'gp_id' => $gpK->id, 'display_name' => 'Karotten', 'raw_text' => 'Karotten',
         'quantity' => '100', 'unit_vocab_id' => $this->g->id],
    ]);
    $r = $this->svc->detailAnySicht($this->rootTeam, $this->rezept->id);
    $bestehendeId = $r->ingredients->first()->id;

    $zutaten = [
        ['id' => $bestehendeId, 'text' => 'Karotten', 'quantity' => 100, 'einheit_slug' => 'g'],   // matched
        ['id' => null, 'text' => 'Sellerieknolle', 'quantity' => 50, 'einheit_slug' => 'g'],        // grounded → GP
        ['id' => null, 'text' => 'Quijibo Wurzel XYZ', 'quantity' => 10, 'einheit_slug' => 'g'],    // hardstop
    ];

    $vorschau = (new \Platform\FoodAlchemist\Livewire\Recipes\RecipeModal())
        ->matchVorschau($this->rootTeam, $r, $zutaten);

    expect($vorschau[0]['status'])->toBe('matched')
        ->and($vorschau[1]['status'])->toBe('grounded')->and($vorschau[1]['kind'])->toBe('gp')
        ->and($vorschau[2]['status'])->toBe('hardstop')
        ->and($vorschau[2]['primaer'])->toBeIn(['gp_anlegen', 'basisrezept_anlegen']);
});
