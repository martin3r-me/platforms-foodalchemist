<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Kurator/Dominique 03.11. (Korrektur der Pilot-Rezepte): `recipe_ingredients.PUT` ist ein Voll-Sync — beim Umhängen EINER Zeile erdete der
 * Resolver jede andere offene Zeile still mit („Gelbes Paprikapulver“ → „Paprikapulver: scharf“), und auch der Wiederherstellungs-PUT konnte
 * sie nicht wieder öffnen. Eine bestehende Zeile behält jetzt ihren Stand; nur neue Zeilen werden geerdet, und auch die nicht mit offen_halten.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.semantic_search.enabled' => false]);
    FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'g'], ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->paprika = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => 'Paprikapulver: trocken', 'gp_key' => 'oh-1||',
        'status' => 'approved', 'commodity_group_code' => '10', 'condition' => 'trocken', 'main_ingredient_slug' => 'paprikapulver']);
    $this->butter = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => 'Butter: frisch', 'gp_key' => 'oh-2||',
        'status' => 'approved', 'commodity_group_code' => '06', 'condition' => 'frisch', 'main_ingredient_slug' => 'butter']);
    $this->rezept = $this->makeRecipe($this->rootTeam, 'Aufstrich: Curry', ['status' => 'draft']);
    $this->offen = $this->makeIngredient($this->rezept, 'Butter', null, '10', 1);   // trifft „Butter: frisch“ exakt — ohne Schutz würde sie still geerdet
    $this->put = fn (array $zutaten) => app(ToolRegistry::class)->get('foodalchemist.recipe_ingredients.PUT')
        ->execute(['recipe_id' => $this->rezept->id, 'zutaten' => $zutaten], new ToolContext($this->user, $this->rootTeam));
});

it('eine bestehende offene Zeile bleibt beim Voll-Sync offen — sie wird nie still geerdet', function () {
    $res = ($this->put)([
        ['id' => $this->offen->id, 'name' => 'Butter', 'quantity' => 10, 'unit' => 'g'],
        ['name' => 'Paprikapulver', 'quantity' => 5, 'unit' => 'g', 'gp_id' => $this->paprika->id],
    ]);

    expect($res->success)->toBeTrue($res->error ?? '')
        ->and($this->offen->fresh()->gp_id)->toBeNull();
});

it('eine bestehende Zeile lässt sich zurück auf offen setzen', function () {
    $this->offen->update(['gp_id' => $this->butter->id]);

    ($this->put)([['id' => $this->offen->id, 'name' => 'Butter', 'quantity' => 10, 'unit' => 'g']]);

    expect($this->offen->fresh()->gp_id)->toBeNull();
});

it('eine NEUE Zeile ohne Zuordnung wird weiter geerdet — mit offen_halten nicht', function () {
    $andere = $this->makeRecipe($this->rootTeam, 'Butterbrot', ['status' => 'draft']);
    $put = fn (array $zutaten) => app(ToolRegistry::class)->get('foodalchemist.recipe_ingredients.PUT')
        ->execute(['recipe_id' => $andere->id, 'zutaten' => $zutaten], new ToolContext($this->user, $this->rootTeam));

    $put([['name' => 'Butter', 'quantity' => 50, 'unit' => 'g']]);
    expect($andere->ingredients()->first()?->gp_id)->toBe($this->butter->id);   // Gegenprobe: neue Zeile wird wie bisher geerdet

    $put([['name' => 'Butter', 'quantity' => 20, 'unit' => 'g', 'offen_halten' => true]]);
    $neu = $andere->ingredients()->get()->first(fn ($z) => (float) $z->quantity === 20.0);

    expect($neu)->not->toBeNull()
        ->and($neu->gp_id)->toBeNull();
});
