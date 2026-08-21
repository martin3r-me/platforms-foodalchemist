<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Der MCP-Schreibpfad kennt die Produktionsplaner-Felder (Schema-Parität zum Editor + Service):
 * recipes.POST/PUT reichen setup_time_min / standzeit_min / batch_max_* / max_vorlauf_tage /
 * default_station_id durch — nicht mehr nur work_time_min. Draft-Quarantäne bleibt der Kanal.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
});

it('recipes.POST übernimmt die Produktionszeit-Felder (nicht nur work_time_min)', function () {
    $post = $this->registry->get('foodalchemist.recipes.POST')->execute([
        'name' => 'Demi-Glace',
        'work_time_min' => 45,
        'setup_time_min' => 20,
        'standzeit_min' => 180,
        'batch_max_kg' => 30,
        'max_vorlauf_tage' => 5,
    ], $this->kontext);

    expect($post->success)->toBeTrue();
    $r = FoodAlchemistRecipe::find($post->data['recipe']['id']);
    expect((int) $r->work_time_min)->toBe(45)
        ->and((int) $r->setup_time_min)->toBe(20)
        ->and((int) $r->standzeit_min)->toBe(180)
        ->and((float) $r->batch_max_kg)->toBe(30.0)
        ->and((int) $r->max_vorlauf_tage)->toBe(5)
        ->and($r->created_via)->toBe('mcp')
        ->and($r->status->value)->toBe('draft');   // Draft-Quarantäne bleibt der Governance-Kanal
});

it('recipes.PUT aktualisiert die Produktionszeit-Felder am Entwurf', function () {
    $post = $this->registry->get('foodalchemist.recipes.POST')->execute(['name' => 'Jus'], $this->kontext);
    $id = $post->data['recipe']['id'];

    $put = $this->registry->get('foodalchemist.recipes.PUT')->execute([
        'recipe_id' => $id,
        'standzeit_min' => 90,
        'batch_max_kg' => 15,
        'setup_time_min' => 10,
    ], $this->kontext);

    expect($put->success)->toBeTrue();
    $r = FoodAlchemistRecipe::find($id);
    expect((int) $r->standzeit_min)->toBe(90)
        ->and((float) $r->batch_max_kg)->toBe(15.0)
        ->and((int) $r->setup_time_min)->toBe(10);
});
