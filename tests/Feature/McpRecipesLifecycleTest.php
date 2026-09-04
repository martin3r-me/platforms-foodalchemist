<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D2a: Basisrezept-Lifecycle (recipes.DELETE/STATUS/DUPLICATE/TEMPLATE_TOGGLE/RECOMPUTE).
 * Basisrezept-Scoping (is_sales_recipe=false), Owner-Guard und Delete-Confirm.
 * Hinweis: Rezepte haben team_id NOT NULL (keine globalen) — der „visible-but-not-owned"-Fall
 * läuft als childA gegen ein root-eigenes (via Ancestry sichtbares) Rezept.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $name, array $a) => $this->registry->get($name)->execute($a, $this->kontext);
    $this->runChild = fn (string $name, array $a) => $this->registry->get($name)->execute($a, $this->childKontext);
    $this->mkRecipe = fn (array $over = []) => FoodAlchemistRecipe::create(array_merge([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'rk_' . bin2hex(random_bytes(4)),
        'name' => 'Fond: Test', 'status' => 'draft', 'yield_kg' => 1.0, 'is_sales_recipe' => false,
    ], $over));
});

it('Registry-Smoke: recipes.DELETE/STATUS/DUPLICATE/TEMPLATE_TOGGLE/RECOMPUTE/REPLACE registriert', function () {
    foreach (['DELETE', 'STATUS', 'DUPLICATE', 'TEMPLATE_TOGGLE', 'RECOMPUTE', 'REPLACE'] as $v) {
        $tool = $this->registry->get("foodalchemist.recipes.{$v}");
        expect($tool)->not->toBeNull($v);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $v);
    }
});

it('recipes.STATUS: einzeln + bulk (eigene Basisrezepte); ungültiger Status → VALIDATION_ERROR', function () {
    $a = ($this->mkRecipe)();
    $b = ($this->mkRecipe)();

    $ein = ($this->run)('foodalchemist.recipes.STATUS', ['id' => $a->id, 'status' => 'approved']);
    expect($ein->success)->toBeTrue()->and($ein->data['status'])->toBe('approved');
    expect($a->refresh()->status->value)->toBe('approved');

    $bulk = ($this->run)('foodalchemist.recipes.STATUS', ['ids' => [$a->id, $b->id], 'status' => 'review']);
    expect($bulk->success)->toBeTrue()->and($bulk->data['aktualisiert'])->toBe(2);

    expect(($this->run)('foodalchemist.recipes.STATUS', ['id' => $a->id, 'status' => 'quatsch'])->errorCode)->toBe('VALIDATION_ERROR');
});

it('recipes.STATUS: VK-Gericht → NOT_FOUND (nur Basisrezepte); fremd-Team (Ancestry) → ACCESS_DENIED', function () {
    $vk = ($this->mkRecipe)(['is_sales_recipe' => true, 'name' => 'HG: Gericht']);
    expect(($this->run)('foodalchemist.recipes.STATUS', ['id' => $vk->id, 'status' => 'approved'])->errorCode)->toBe('NOT_FOUND');

    // root-eigenes Rezept, aus Sicht von childA sichtbar (Ancestry) aber nicht eigen
    $rootRez = ($this->mkRecipe)();
    expect(($this->runChild)('foodalchemist.recipes.STATUS', ['id' => $rootRez->id, 'status' => 'approved'])->errorCode)->toBe('ACCESS_DENIED');
});

it('recipes.DELETE: confirm-Pflicht, löscht eigenes Basisrezept; VK → NOT_FOUND', function () {
    $r = ($this->mkRecipe)();
    expect(($this->run)('foodalchemist.recipes.DELETE', ['id' => $r->id])->errorCode)->toBe('CONFIRM_REQUIRED');

    $ok = ($this->run)('foodalchemist.recipes.DELETE', ['id' => $r->id, 'confirm' => true]);
    expect($ok->success)->toBeTrue()->and($ok->data['deleted'])->toBeTrue();
    expect(FoodAlchemistRecipe::find($r->id))->toBeNull();

    $vk = ($this->mkRecipe)(['is_sales_recipe' => true]);
    expect(($this->run)('foodalchemist.recipes.DELETE', ['id' => $vk->id, 'confirm' => true])->errorCode)->toBe('NOT_FOUND');
});

it('recipes.DUPLICATE: kopiert (auch fremd-sichtbare Vorlage → eigene Kopie); leerer Name → VALIDATION_ERROR', function () {
    // root-eigene Vorlage; childA dupliziert → Kopie gehört childA
    $vorlage = ($this->mkRecipe)(['name' => 'Fond: Vorlage']);
    $dup = ($this->runChild)('foodalchemist.recipes.DUPLICATE', ['id' => $vorlage->id, 'name' => 'Fond: Kind-Kopie']);
    expect($dup->success)->toBeTrue()->and($dup->data['name'])->toBe('Fond: Kind-Kopie');
    expect((int) FoodAlchemistRecipe::find($dup->data['id'])->team_id)->toBe((int) $this->childA->id);

    expect(($this->run)('foodalchemist.recipes.DUPLICATE', ['id' => $vorlage->id, 'name' => '  '])->errorCode)->toBe('VALIDATION_ERROR');
});

it('recipes.TEMPLATE_TOGGLE + RECOMPUTE: eigenes ok; fremd-Team → ACCESS_DENIED', function () {
    $r = ($this->mkRecipe)();

    $t1 = ($this->run)('foodalchemist.recipes.TEMPLATE_TOGGLE', ['id' => $r->id, 'is_template' => true]);
    expect($t1->success)->toBeTrue()->and($t1->data['is_template'])->toBeTrue();

    $rc = ($this->run)('foodalchemist.recipes.RECOMPUTE', ['id' => $r->id]);
    expect($rc->success)->toBeTrue()->and($rc->data['recomputed'])->toBeTrue()->and($rc->data['betroffene_rezepte'])->toBeGreaterThanOrEqual(1);

    $rootRez = ($this->mkRecipe)();
    expect(($this->runChild)('foodalchemist.recipes.TEMPLATE_TOGGLE', ['id' => $rootRez->id])->errorCode)->toBe('ACCESS_DENIED');
    expect(($this->runChild)('foodalchemist.recipes.RECOMPUTE', ['id' => $rootRez->id])->errorCode)->toBe('ACCESS_DENIED');
});

/**
 * recipes.REPLACE (2026-09-04) — Pendant zu gps.REPLACE. Schreibt NUR in eigene Eltern;
 * `from` darf geerbt sein (das from-Rezept selbst wird nie verändert).
 */
it('recipes.REPLACE: hängt eigene Verwendungen um, confirm ist Pflicht, geerbte Eltern bleiben', function () {
    $alt = ($this->mkRecipe)(['name' => 'Fond: Kalb']);
    $neu = ($this->mkRecipe)(['name' => 'Fond: Kalb klar']);
    $eigen = FoodAlchemistRecipe::create(['team_id' => $this->childA->id, 'recipe_key' => 'rk_' . bin2hex(random_bytes(4)),
        'name' => 'Suppe (Kind A)', 'status' => 'draft', 'yield_kg' => 1.0, 'is_sales_recipe' => false]);
    $master = ($this->mkRecipe)(['name' => 'Suppe (Master)']);
    $zEigen = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->childA->id, 'recipe_id' => $eigen->id, 'referenced_recipe_id' => $alt->id,
        'raw_text' => '300 g Fond', 'quantity' => '300', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1,
    ]);
    $zMaster = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $master->id, 'referenced_recipe_id' => $alt->id,
        'raw_text' => '300 g Fond', 'quantity' => '300', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1,
    ]);

    // ohne confirm: nichts passiert
    expect(($this->runChild)('foodalchemist.recipes.REPLACE', ['from_id' => $alt->id, 'to_id' => $neu->id])->errorCode)
        ->toBe('CONFIRM_REQUIRED');
    expect((int) $zEigen->refresh()->referenced_recipe_id)->toBe($alt->id);

    // als childA: eigene Zeile wandert, die Master-Zeile nicht
    $res = ($this->runChild)('foodalchemist.recipes.REPLACE', ['from_id' => $alt->id, 'to_id' => $neu->id, 'confirm' => true]);
    expect($res->success)->toBeTrue()
        ->and($res->data['zeilen'])->toBe(1)
        ->and($res->data['geerbt_unberuehrt'])->toBe(1);
    expect((int) $zEigen->refresh()->referenced_recipe_id)->toBe($neu->id)
        ->and((int) $zMaster->refresh()->referenced_recipe_id)->toBe($alt->id);

    // Ziel = Quelle → VALIDATION_ERROR; unsichtbares Ziel → NOT_FOUND
    expect(($this->run)('foodalchemist.recipes.REPLACE', ['from_id' => $alt->id, 'to_id' => $alt->id, 'confirm' => true])->errorCode)
        ->toBe('VALIDATION_ERROR');
    expect(($this->run)('foodalchemist.recipes.REPLACE', ['from_id' => $alt->id, 'to_id' => 999999, 'confirm' => true])->errorCode)
        ->toBe('NOT_FOUND');
});
