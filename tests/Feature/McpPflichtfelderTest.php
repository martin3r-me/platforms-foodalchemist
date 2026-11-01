<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Der MCP-Weg (`execute`-Tool, Adapter) ruft `execute()` direkt auf und umgeht die Schema-Prüfung des Core-ToolExecutors. Ein fehlendes Pflichtfeld
 * (z. B. `id` bei recipes.GET) wurde dort zu „Undefined array key“. Die Prüfung sitzt zentral in der FA-Tool-Hülle (FaRechteToolHuelle), also für alle Tools.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    // der Weg des MCP-execute-Tools: Registry-Tool, execute() direkt (kein ToolExecutor)
    $this->direkt = fn (string $tool, array $args) => app(ToolRegistry::class)->get($tool)->execute($args, $this->ctx);
});

it('recipes.GET ohne id: klarer VALIDATION_ERROR statt „Undefined array key id“', function () {
    $r = ($this->direkt)('foodalchemist.recipes.GET', []);

    expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('VALIDATION_ERROR')->and($r->error)->toContain("Feld 'id' ist erforderlich");
    // null zählt wie bei Core als fehlend, 0 nicht (Core: isset)
    expect(($this->direkt)('foodalchemist.recipes.GET', ['id' => null])->errorCode)->toBe('VALIDATION_ERROR');
    $rezept = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'rk_x', 'name' => 'Fond: Test', 'status' => 'draft', 'yield_kg' => 1.0, 'is_sales_recipe' => false]);
    expect(($this->direkt)('foodalchemist.recipes.GET', ['id' => $rezept->id])->success)->toBeTrue();
});

it('ALLE FA-Tools mit Pflichtfeldern: ohne Argumente über den direkten Weg nie eine Exception, immer VALIDATION_ERROR mit allen fehlenden Feldern', function () {
    $geprueft = 0;
    $schlecht = [];
    foreach (app(ToolRegistry::class)->all() as $name => $tool) {
        if (! str_starts_with($tool->getName(), 'foodalchemist.')) {
            continue;
        }
        $pflicht = $tool->getSchema()['required'] ?? [];
        if ($pflicht === []) {
            continue;
        }
        try {
            $r = $tool->execute([], $this->ctx);
        } catch (\Throwable $e) {
            $schlecht[] = $tool->getName() . ' wirft ' . $e::class . ': ' . mb_substr($e->getMessage(), 0, 60);

            continue;
        }
        if ($r->errorCode === 'FORBIDDEN') {
            continue;   // Rechte/Bereich gehen vor der Pflichtfeld-Prüfung (Master-only-Bereiche u. ä.)
        }
        $fehlt = array_filter($pflicht, fn ($f) => ! str_contains((string) $r->error, "Feld '{$f}' ist erforderlich"));
        if ($r->success || $r->errorCode !== 'VALIDATION_ERROR' || $fehlt !== []) {
            $schlecht[] = $tool->getName() . ' => ' . ($r->success ? 'SUCCESS' : $r->errorCode) . ' ' . mb_substr((string) $r->error, 0, 60);
        }
        $geprueft++;
    }

    expect($schlecht)->toBe([])->and($geprueft)->toBeGreaterThan(300);
});

it('Rechte gehen vor: ein Betrachter bekommt bei fehlendem Pflichtfeld eines schreibenden Tools FORBIDDEN, nicht VALIDATION_ERROR', function () {
    $leser = $this->makeUser($this->rootTeam, 'Leser', 'viewer');
    $r = app(ToolRegistry::class)->get('foodalchemist.recipes.PUT')->execute([], new ToolContext($leser, $this->rootTeam));

    expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('FORBIDDEN');
});
