<?php

use Illuminate\Support\Facades\Queue;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Spec 42 F5 — MCP-Tool foodalchemist.foodbook.PLAN_FROM_BRIEF (Foodbook aus Brief in der Leitstelle). */

/** Provider-Stub für das Gerüst-JSON (eindeutiger Name gegen Parallel-Redeclare). */
function bindeMcpFbGeruestStub(array $werte): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($werte) implements LLMProviderContract
    {
        public function __construct(private array $werte) {}

        public function getName(): string
        {
            return 'mcp-fb-stub';
        }

        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => $this->werte, 'confidence' => 0.9, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void {}

        public function getAvailableModels(): array
        {
            return ['stub'];
        }

        public function getDefaultModel(): string
        {
            return 'stub';
        }

        public function isAvailable(): bool
        {
            return true;
        }
    });
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
});

it('PLAN_FROM_BRIEF: legt ein neues Foodbook an, baut das Gerüst und startet die Voll-Kaskade (owner=foodbook)', function () {
    Queue::fake();
    bindeMcpFbGeruestStub(['name' => 'MCP-Buch', 'slots' => [['label' => 'Vorspeise', 'slot_type' => 'gang', 'target_count' => 2]]]);

    $res = $this->registry->get('foodalchemist.foodbook.PLAN_FROM_BRIEF')
        ->execute(['brief' => 'Sommer-Gala für 60 Gäste, gehoben.', 'label' => 'MCP-Buch'], $this->kontext);

    expect($res->success)->toBeTrue();
    $fbId = (int) $res->data['foodbook_id'];
    expect(FoodAlchemistFoodbook::find($fbId))->not->toBeNull();

    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'foodbook')->where('source_owner_id', $fbId)->latest('id')->first();
    expect($run)->not->toBeNull()->and($run->scope)->toBe('vollkaskade');
    Queue::assertPushed(GenerateConceptJob::class, fn ($j) => $j->attachOwnerType === 'foodbook' && (int) $j->attachContainerId > 0);
});

it('PLAN_FROM_BRIEF: leerer Brief → VALIDATION_ERROR, nichts angelegt', function () {
    Queue::fake();
    $res = $this->registry->get('foodalchemist.foodbook.PLAN_FROM_BRIEF')->execute(['brief' => '   '], $this->kontext);

    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('VALIDATION_ERROR');
    expect(FoodAlchemistFoodbook::where('team_id', $this->rootTeam->id)->count())->toBe(0);
    Queue::assertNotPushed(GenerateConceptJob::class);
});

it('PLAN_FROM_BRIEF: bestehendes foodbook_id wird bebrieft (kein neues angelegt)', function () {
    Queue::fake();
    bindeMcpFbGeruestStub(['name' => 'Bestehend', 'slots' => [['label' => 'Gang 1', 'slot_type' => 'gang', 'target_count' => 2]]]);
    $fb = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'Bestehendes FB']);
    $vorher = FoodAlchemistFoodbook::where('team_id', $this->rootTeam->id)->count();

    $res = $this->registry->get('foodalchemist.foodbook.PLAN_FROM_BRIEF')
        ->execute(['brief' => 'Brief fürs bestehende Buch.', 'foodbook_id' => $fb->id], $this->kontext);

    expect($res->success)->toBeTrue()->and((int) $res->data['foodbook_id'])->toBe((int) $fb->id);
    expect(FoodAlchemistFoodbook::where('team_id', $this->rootTeam->id)->count())->toBe($vorher);
});

it('PLAN_FROM_BRIEF: fremdes/unbekanntes foodbook_id wird abgewiesen (kein Fremd-Plan)', function () {
    Queue::fake();
    bindeMcpFbGeruestStub(['name' => 'X', 'slots' => [['label' => 'G', 'slot_type' => 'gang']]]);

    $res = $this->registry->get('foodalchemist.foodbook.PLAN_FROM_BRIEF')
        ->execute(['brief' => 'Brief.', 'foodbook_id' => 999999], $this->kontext);

    expect($res->success)->toBeFalse()->and($res->errorCode)->toBeIn(['NOT_FOUND', 'INHERITED']);
    Queue::assertNotPushed(GenerateConceptJob::class);
});
