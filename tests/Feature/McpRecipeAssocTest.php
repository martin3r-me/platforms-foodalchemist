<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\FeedbackService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D2b: Rezept-Assoziationen — recipe_eignung/anchors/pairings.PUT,
 * recipe_sensorik.POST, recipe_feedback.DELETE/DEVELOP. Owner-/Sichtbarkeits-Guards + Validierung.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $name, array $a) => $this->registry->get($name)->execute($a, $this->kontext);
    $this->runChild = fn (string $name, array $a) => $this->registry->get($name)->execute($a, $this->childKontext);
    $this->mkRecipe = fn () => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'rk_' . bin2hex(random_bytes(4)),
        'name' => 'Fond: Assoc', 'status' => 'draft', 'yield_kg' => 1.0, 'is_sales_recipe' => false,
    ]);
    $this->neuGp = fn (string $hz) => $this->registry->get('foodalchemist.gps.POST')->execute(['hauptzutat' => $hz], $this->kontext)->data['id'];
    // globaler Pairing-Anker-Knoten (team_id NULL) für Anker/Pairing-Mappings
    $this->neuAnker = fn (string $name) => DB::table('foodalchemist_vocab_pairing_anchors')->insertGetId([
        'uuid' => (string) Str::uuid(), 'team_id' => null,
        'slug' => Str::slug($name) . '-' . bin2hex(random_bytes(3)), 'display_de' => $name,
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('Registry-Smoke: D2b-Tools registriert', function () {
    foreach ([
        'foodalchemist.recipe_eignung.PUT', 'foodalchemist.recipe_anchors.PUT', 'foodalchemist.recipe_pairings.PUT',
        'foodalchemist.recipe_sensorik.POST', 'foodalchemist.recipe_feedback.DELETE', 'foodalchemist.recipe_feedback.DEVELOP',
    ] as $name) {
        expect($this->registry->get($name))->not->toBeNull($name);
        expect($this->registry->get($name)->getSchema()['type'] ?? null)->toBe('object', $name);
    }
});

it('recipe_eignung.PUT: set/remove; ungültiger Slug → VALIDATION_ERROR; fremd → ACCESS_DENIED', function () {
    $r = ($this->mkRecipe)();
    expect(($this->run)('foodalchemist.recipe_eignung.PUT', ['recipe_id' => $r->id, 'typ' => 'level', 'slug' => 'gehoben', 'action' => 'set'])->success)->toBeTrue();
    expect(($this->run)('foodalchemist.recipe_eignung.PUT', ['recipe_id' => $r->id, 'typ' => 'level', 'slug' => 'gehoben', 'action' => 'remove'])->success)->toBeTrue();
    expect(($this->run)('foodalchemist.recipe_eignung.PUT', ['recipe_id' => $r->id, 'typ' => 'level', 'slug' => 'quatsch', 'action' => 'set'])->errorCode)->toBe('VALIDATION_ERROR');
    expect(($this->runChild)('foodalchemist.recipe_eignung.PUT', ['recipe_id' => $r->id, 'typ' => 'sektor', 'slug' => 'care', 'action' => 'set'])->errorCode)->toBe('ACCESS_DENIED');
});

it('recipe_anchors.PUT: set + remove echten Anker; unbekanntes Rezept + invalider Anker → NOT_FOUND', function () {
    $r = ($this->mkRecipe)();
    $anker = ($this->neuAnker)('Zitrone');
    expect(($this->run)('foodalchemist.recipe_anchors.PUT', ['recipe_id' => $r->id, 'anker_id' => $anker, 'action' => 'set'])->success)->toBeTrue();
    expect(($this->run)('foodalchemist.recipe_anchors.PUT', ['recipe_id' => $r->id, 'anker_id' => $anker, 'action' => 'remove'])->success)->toBeTrue();
    expect(($this->run)('foodalchemist.recipe_anchors.PUT', ['recipe_id' => 999999, 'anker_id' => $anker, 'action' => 'set'])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.recipe_anchors.PUT', ['recipe_id' => $r->id, 'anker_id' => 999999, 'action' => 'set'])->errorCode)->toBe('NOT_FOUND');
});

it('recipe_pairings.PUT: set mit Typ + remove; ungültiger Typ → VALIDATION_ERROR', function () {
    $r = ($this->mkRecipe)();
    $anker = ($this->neuAnker)('Estragon');
    expect(($this->run)('foodalchemist.recipe_pairings.PUT', ['recipe_id' => $r->id, 'anker_id' => $anker, 'typ' => 'klassisch', 'action' => 'set'])->success)->toBeTrue();
    expect(($this->run)('foodalchemist.recipe_pairings.PUT', ['recipe_id' => $r->id, 'anker_id' => $anker, 'action' => 'remove'])->success)->toBeTrue();
    expect(($this->run)('foodalchemist.recipe_pairings.PUT', ['recipe_id' => $r->id, 'anker_id' => $anker, 'typ' => 'schräg', 'action' => 'set'])->errorCode)->toBe('VALIDATION_ERROR');
});

it('recipe_sensorik.POST: eigenes ok (KI), fremd → ACCESS_DENIED', function () {
    $r = ($this->mkRecipe)();
    $ok = ($this->run)('foodalchemist.recipe_sensorik.POST', ['recipe_id' => $r->id]);
    expect($ok->success)->toBeTrue()->and($ok->data['recipe_id'])->toBe($r->id);
    expect(($this->runChild)('foodalchemist.recipe_sensorik.POST', ['recipe_id' => $r->id])->errorCode)->toBe('ACCESS_DENIED');
});

it('recipe_feedback.DELETE: löscht eigenes Feedback; unbekannt → NOT_FOUND', function () {
    $r = ($this->mkRecipe)();
    $fb = app(FeedbackService::class)->erstelle($this->rootTeam, $r->id, ['quelle' => 'kunde', 'comment' => 'Sehr gut']);
    expect(($this->run)('foodalchemist.recipe_feedback.DELETE', ['feedback_id' => $fb->id])->data['deleted'])->toBeTrue();
    expect(($this->run)('foodalchemist.recipe_feedback.DELETE', ['feedback_id' => 999999])->errorCode)->toBe('NOT_FOUND');
});

it('recipe_feedback.DEVELOP: erzeugt Weiterentwicklung (neues Rezept)', function () {
    $r = ($this->mkRecipe)();
    $fb = app(FeedbackService::class)->erstelle($this->rootTeam, $r->id, ['quelle' => 'kueche', 'comment' => 'Mehr Säure', 'score' => 3]);
    $res = ($this->run)('foodalchemist.recipe_feedback.DEVELOP', ['feedback_id' => $fb->id]);
    expect($res->success)->toBeTrue()->and($res->data['neues_rezept_id'])->toBeInt();
});
