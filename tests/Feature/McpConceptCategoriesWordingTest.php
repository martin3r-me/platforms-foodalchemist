<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D5c: Konzept-Kategorien (POST/PUT/DELETE) + concept_wording.GENERATE
 * (W-Grounding) + concepts.COHESION (Read). Web↔MCP-Parität über ConceptService.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->svc = app(ConceptService::class);
    $this->concept = $this->svc->create($this->rootTeam, ['name' => 'Grill-Buffet', 'occasion' => 'Sommerfest']);
});

it('Registry-Smoke: 5 D5c-Tools registriert mit type=object', function () {
    foreach (['concept_categories.POST', 'concept_categories.PUT', 'concept_categories.DELETE', 'concept_wording.GENERATE', 'concepts.COHESION'] as $n) {
        $tool = $this->registry->get("foodalchemist.{$n}");
        expect($tool)->not->toBeNull($n);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $n);
    }
});

it('concept_categories: POST (+Unterkategorie) / PUT / DELETE(confirm)', function () {
    $post = ($this->run)('foodalchemist.concept_categories.POST', ['name' => 'Sommer-Menüs']);
    expect($post->success)->toBeTrue('post: ' . ($post->error ?? ''));
    $catId = $post->data['id'];

    $sub = ($this->run)('foodalchemist.concept_categories.POST', ['name' => 'Grill', 'parent_id' => $catId]);
    expect($sub->success)->toBeTrue('sub: ' . ($sub->error ?? ''))->and($sub->data['parent_id'])->toBe($catId);

    $put = ($this->run)('foodalchemist.concept_categories.PUT', ['id' => $catId, 'name' => 'Sommer 2027']);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''));
    expect(FoodAlchemistConceptCategory::find($catId)->name)->toBe('Sommer 2027');

    // DELETE ohne confirm → CONFIRM_REQUIRED
    expect(($this->run)('foodalchemist.concept_categories.DELETE', ['id' => $catId])->errorCode)->toBe('CONFIRM_REQUIRED');

    // Unterkategorie wandert an den Eltern (hier: null), Zeile weg
    $del = ($this->run)('foodalchemist.concept_categories.DELETE', ['id' => $catId, 'confirm' => true]);
    expect($del->success)->toBeTrue('del: ' . ($del->error ?? ''));
    expect(FoodAlchemistConceptCategory::find($catId))->toBeNull()
        ->and(FoodAlchemistConceptCategory::find($sub->data['id'])->parent_id)->toBeNull();
});

it('concept_categories: Guards — unbekannt NOT_FOUND, fremd ACCESS_DENIED, leer VALIDATION_ERROR', function () {
    $post = ($this->run)('foodalchemist.concept_categories.POST', ['name' => 'X']);
    $catId = $post->data['id'];

    expect(($this->run)('foodalchemist.concept_categories.PUT', ['id' => 999999, 'name' => 'Y'])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.concept_categories.PUT', ['id' => $catId, 'name' => 'Y'], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
    expect(($this->run)('foodalchemist.concept_categories.POST', ['name' => ''])->errorCode)->toBe('VALIDATION_ERROR');
    expect(($this->run)('foodalchemist.concept_categories.DELETE', ['id' => $catId, 'confirm' => true], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
});

it('concept_wording.GENERATE: Intro→Beschreibung + Positions-Texte (Spy-Provider)', function () {
    $dish = $this->makeRecipe($this->rootTeam, 'DES: Hot-Dog', ['is_sales_recipe' => true, 'sales_net' => 2.0]);
    $slot = $this->svc->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Snack']);
    $slot = $this->svc->fillSlot($this->rootTeam, $slot->id, ['sales_recipe_id' => $dish->id]);

    $spy = new class($slot->id) extends FakeAiProvider
    {
        public function __construct(public int $slotId) {}

        public function chat(array $messages, array $options = []): array
        {
            return [
                'content' => json_encode(['werte' => [
                    'intro' => 'Ein sommerliches Grill-Erlebnis.',
                    'slots' => [$this->slotId => 'Knuspriger Hot-Dog-Traum'],
                ], 'confidence' => 0.9]),
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'spy', 'tool_calls' => null,
            ];
        }
    };
    app()->instance(FakeAiProvider::class, $spy);

    $gen = ($this->run)('foodalchemist.concept_wording.GENERATE', ['concept_id' => $this->concept->id]);
    expect($gen->success)->toBeTrue('gen: ' . ($gen->error ?? ''))
        ->and($gen->data['intro'])->toBe('Ein sommerliches Grill-Erlebnis.')
        ->and($gen->data['slots_set'])->toBe(1);
    expect($this->concept->fresh()->description)->toBe('Ein sommerliches Grill-Erlebnis.')
        ->and($slot->fresh()->wording)->toBe('Knuspriger Hot-Dog-Traum');

    // Guards
    expect(($this->run)('foodalchemist.concept_wording.GENERATE', ['concept_id' => 999999])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.concept_wording.GENERATE', ['concept_id' => $this->concept->id], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
});

it('concepts.COHESION: read-only, zu_wenig bei <2 Gerichten; fremd/sichtbar noch lesbar', function () {
    $coh = ($this->run)('foodalchemist.concepts.COHESION', ['concept_id' => $this->concept->id]);
    expect($coh->success)->toBeTrue('coh: ' . ($coh->error ?? ''))
        ->and($coh->data['zu_wenig'])->toBeTrue()
        ->and($coh->data['concept_id'])->toBe($this->concept->id);

    expect(($this->run)('foodalchemist.concepts.COHESION', ['concept_id' => 999999])->errorCode)->toBe('NOT_FOUND');
});
