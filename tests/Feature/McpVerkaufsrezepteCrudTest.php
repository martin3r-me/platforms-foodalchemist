<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D3a: Verkaufsrezepte (Gerichte) — GET/POST/PUT/DELETE/STATUS.
 * VK-Scoping (is_sales_recipe=true), Owner-Guard, Delete-Confirm, FK-Re-Auth.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->neuVk = fn (string $name = 'HG: Test') => $this->registry->get('foodalchemist.verkaufsrezepte.POST')->execute(['name' => $name], $this->kontext)->data['id'];
});

it('Registry-Smoke: verkaufsrezepte.GET/POST/PUT/DELETE/STATUS registriert', function () {
    foreach (['GET', 'POST', 'PUT', 'DELETE', 'STATUS'] as $v) {
        $tool = $this->registry->get("foodalchemist.verkaufsrezepte.{$v}");
        expect($tool)->not->toBeNull($v);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $v);
    }
});

it('POST (leer) legt VK an, GET liefert Detail (Darreichungen + Cockpit + Taxonomie)', function () {
    $leer = ($this->run)('foodalchemist.verkaufsrezepte.POST', ['name' => 'HG: Leer']);
    expect($leer->success)->toBeTrue();
    $vk = FoodAlchemistRecipe::find($leer->data['id']);
    expect((bool) $vk->is_sales_recipe)->toBeTrue();

    $get = ($this->run)('foodalchemist.verkaufsrezepte.GET', ['id' => $leer->data['id']]);
    expect($get->success)->toBeTrue()
        ->and($get->data['name'])->toBe('HG: Leer')
        ->and($get->data)->toHaveKeys(['presentations', 'cockpit', 'dish_main_group_id', 'sales_net_standard_mirror']);
});

it('POST mit fremd-sichtbarer/unbekannter basis_recipe_id → NOT_FOUND', function () {
    expect(($this->run)('foodalchemist.verkaufsrezepte.POST', ['name' => 'X', 'basis_recipe_id' => 999999])->errorCode)->toBe('NOT_FOUND');
});

it('PUT bearbeitet VK-Felder; leere felder → VALIDATION_ERROR; fremd → ACCESS_DENIED', function () {
    $id = ($this->neuVk)();
    $ok = ($this->run)('foodalchemist.verkaufsrezepte.PUT', ['id' => $id, 'felder' => ['sales_wording_standard' => 'Feiner Fisch', 'description' => 'Test']]);
    expect($ok->success)->toBeTrue();
    expect(FoodAlchemistRecipe::find($id)->sales_wording_standard)->toBe('Feiner Fisch');

    expect(($this->run)('foodalchemist.verkaufsrezepte.PUT', ['id' => $id, 'felder' => []])->errorCode)->toBe('VALIDATION_ERROR');
    expect(($this->run)('foodalchemist.verkaufsrezepte.PUT', ['id' => $id, 'felder' => ['name' => 'X']], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
});

it('PUT mit fremd-Team-FK (dish_class_id) → VALIDATION_ERROR (FK-Re-Auth)', function () {
    $id = ($this->neuVk)();
    // dish_class_id, das im Team nicht sichtbar ist → TeamScope::referenz wirft → VALIDATION_ERROR
    $res = ($this->run)('foodalchemist.verkaufsrezepte.PUT', ['id' => $id, 'felder' => ['dish_class_id' => 999999]]);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('VALIDATION_ERROR');
});

it('STATUS setzt approved; Basisrezept-Id → NOT_FOUND (nur VK)', function () {
    $id = ($this->neuVk)();
    expect(($this->run)('foodalchemist.verkaufsrezepte.STATUS', ['id' => $id, 'status' => 'approved'])->data['status'])->toBe('approved');

    $basis = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'b2_' . bin2hex(random_bytes(3)), 'name' => 'Fond2', 'status' => 'draft', 'yield_kg' => 1.0, 'is_sales_recipe' => false]);
    expect(($this->run)('foodalchemist.verkaufsrezepte.STATUS', ['id' => $basis->id, 'status' => 'approved'])->errorCode)->toBe('NOT_FOUND');
});

it('DELETE: confirm-Pflicht, löscht eigenes Gericht', function () {
    $id = ($this->neuVk)();
    expect(($this->run)('foodalchemist.verkaufsrezepte.DELETE', ['id' => $id])->errorCode)->toBe('CONFIRM_REQUIRED');
    expect(($this->run)('foodalchemist.verkaufsrezepte.DELETE', ['id' => $id, 'confirm' => true])->data['deleted'])->toBeTrue();
});
