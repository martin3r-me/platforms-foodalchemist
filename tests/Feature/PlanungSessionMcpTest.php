<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Phase 0 (Leitstelle per MCP): die Richtungs-Regler (Leitplanken/`generation_params`) sind über die
 * Session-Tools setz- und lesbar — Voraussetzung, um die Leitplanken per MCP überhaupt zu prüfen.
 * Ohne diese Anbindung liefe eine rein per MCP angelegte Session mit leeren Reglern.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
});

it('POST setzt generation_params (whitelist-gefiltert) + liefert sie zurück', function () {
    $r = $this->registry->get('foodalchemist.planung_session.POST')->execute([
        'title' => 'MCP-Leitplanken',
        'brief' => 'Ein Menü.',
        'creative_mode' => 'hybrid',
        'generation_params' => [
            'level' => 'gehoben', 'diaet_hart' => 'vegetarisch', 'ki_bilder' => true,
            'nicht_erlaubt' => 'xxx',   // nicht in der Whitelist → wird verworfen
        ],
    ], $this->ctx);

    expect($r->success)->toBeTrue()
        ->and($r->data['creative_mode'])->toBe('hybrid')
        ->and($r->data['generation_params'])->toMatchArray(['level' => 'gehoben', 'diaet_hart' => 'vegetarisch', 'ki_bilder' => true])
        ->and($r->data['generation_params'])->not->toHaveKey('nicht_erlaubt');

    $session = FoodAlchemistPlanningSession::find($r->data['id']);
    expect($session->generation_params)->not->toHaveKey('nicht_erlaubt');
});

it('PUT ersetzt generation_params + GET liest sie zurück', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'x', 'brief' => 'y']);

    $put = $this->registry->get('foodalchemist.planung_session.PUT')->execute([
        'id' => (int) $session->id,
        'generation_params' => ['sektor' => 'fine_dining', 'ki_bilder' => false, 'saison' => 'herbst'],
    ], $this->ctx);
    expect($put->success)->toBeTrue()
        ->and($put->data['generation_params'])->toMatchArray(['sektor' => 'fine_dining', 'saison' => 'herbst']);

    $get = $this->registry->get('foodalchemist.planung_session.GET')->execute(['id' => (int) $session->id], $this->ctx);
    expect($get->success)->toBeTrue()
        ->and($get->data['session']['generation_params'])->toMatchArray(['sektor' => 'fine_dining', 'saison' => 'herbst']);
});

it('POST-Tenancy: PUT auf eine geerbte Session wird abgewiesen (D1 ownedSession)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Root', 'brief' => 'x']);
    $childAUser = $this->makeUser($this->childA, 'Kind A');
    $ctxA = new ToolContext($childAUser, $this->childA);

    $r = $this->registry->get('foodalchemist.planung_session.PUT')->execute([
        'id' => (int) $session->id,
        'generation_params' => ['level' => 'gehoben'],
    ], $ctxA);

    expect($r->success)->toBeFalse()
        ->and($r->errorCode)->toBe('VALIDATION_ERROR');   // ownedSession wirft RuntimeException (geerbt)
});
