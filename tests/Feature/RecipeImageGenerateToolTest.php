<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Bild-Tool: Guards (Kill-Switch, Tenancy) + Wiring in RecipeImageService.
 * Die eigentliche Bilderzeugung (OpenAI) wird gemockt — hier zählt die Tool-Logik.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->recipe = $this->makeRecipe($this->rootTeam, 'Quiche', ['ek_total_eur' => 5]);
});

it('ist registriert mit object-Schema', function () {
    $tool = $this->registry->get('foodalchemist.recipe_images.GENERATE');
    expect($tool)->not->toBeNull()
        ->and($tool->getSchema()['type'] ?? null)->toBe('object');
});

it('blockt bei aktivem Kill-Switch', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['ai_active' => false]);
    $res = $this->registry->get('foodalchemist.recipe_images.GENERATE')
        ->execute(['recipe_id' => $this->recipe->id], $this->ctx);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('KI_DISABLED');
});

it('lehnt ein fremdes Rezept ab', function () {
    $fremd = $this->makeRecipe($this->childB, 'Fremd', []);
    $res = $this->registry->get('foodalchemist.recipe_images.GENERATE')
        ->execute(['recipe_id' => $fremd->id], $this->ctx);
    // childB-Rezept ist für rootTeam nicht sichtbar → NOT_FOUND.
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
});

it('scope=all reicht an RecipeImageService durch und liefert die Zähler', function () {
    $this->mock(RecipeImageService::class, function ($m) {
        $m->shouldReceive('erzeugeFuerRezept')->once()
            ->andReturn(['erzeugt' => 3, 'fehler' => 1, 'letzter_fehler' => 'Timeout']);
    });

    $res = $this->registry->get('foodalchemist.recipe_images.GENERATE')
        ->execute(['recipe_id' => $this->recipe->id, 'scope' => 'all'], $this->ctx);

    expect($res->success)->toBeTrue()
        ->and($res->data['generated'])->toBe(3)
        ->and($res->data['errors'])->toBe(1)
        ->and($res->data['last_error'])->toBe('Timeout');
});

it('replace=true löscht vorher die KI-Fotos', function () {
    $this->mock(RecipeImageService::class, function ($m) {
        $m->shouldReceive('loescheKiFotos')->once()->andReturn(2);
        $m->shouldReceive('erzeugeFuerRezept')->once()->andReturn(['erzeugt' => 1, 'fehler' => 0, 'letzter_fehler' => null]);
    });

    $res = $this->registry->get('foodalchemist.recipe_images.GENERATE')
        ->execute(['recipe_id' => $this->recipe->id, 'scope' => 'all', 'replace' => true], $this->ctx);
    expect($res->success)->toBeTrue()->and($res->data['generated'])->toBe(1);
});
