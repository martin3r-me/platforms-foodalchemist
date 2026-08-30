<?php

use Illuminate\Support\Facades\Queue;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Jobs\MaterializeSpeiseplanCellJob;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** MCP-Tools speiseplan.PLAN_FROM_BRIEF + angebot.PLAN_FROM_BRIEF (Container-Formen aus Brief, eager). */

/** Provider-Stub für das Gerüst-JSON (eindeutiger Name gegen Parallel-Redeclare). */
function bindeAusgabeGeruestStub(array $werte): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($werte) implements LLMProviderContract
    {
        public function __construct(private array $werte) {}

        public function getName(): string { return 'ausgabe-geruest-stub'; }

        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => $this->werte, 'confidence' => 0.9, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void {}

        public function getAvailableModels(): array { return ['stub']; }

        public function getDefaultModel(): string { return 'stub'; }

        public function isAvailable(): bool { return true; }
    });
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
});

it('Registry-Smoke: speiseplan + angebot PLAN_FROM_BRIEF sind registriert + WRITE', function () {
    foreach (['foodalchemist.speiseplan.PLAN_FROM_BRIEF', 'foodalchemist.angebot.PLAN_FROM_BRIEF'] as $name) {
        $tool = $this->registry->get($name);
        expect($tool)->not->toBeNull()
            ->and($tool->getName())->toBe($name)
            ->and($tool->getMetadata()['read_only'])->toBeFalse()
            ->and($tool->getMetadata()['risk_level'])->toBe('write');
    }
});

it('speiseplan.PLAN_FROM_BRIEF: legt einen Speiseplan an + startet den Zell-Fan-out (owner=speiseplan, eager)', function () {
    Queue::fake();

    $res = $this->registry->get('foodalchemist.speiseplan.PLAN_FROM_BRIEF')
        ->execute(['brief' => 'Betriebsrestaurant, 2 Wochen, ausgewogen.', 'label' => 'MCP-Plan'], $this->ctx);

    expect($res->success)->toBeTrue();
    $planId = (int) $res->data['speiseplan_id'];
    expect(FoodAlchemistSpeiseplan::find($planId))->not->toBeNull();
    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'speiseplan')->where('source_owner_id', $planId)->latest('id')->first();
    expect($run)->not->toBeNull()->and($run->scope)->toBe('vollkaskade')->and((bool) $run->staged)->toBeFalse();
    Queue::assertPushed(MaterializeSpeiseplanCellJob::class);   // Zell-Fan-out
});

it('speiseplan.PLAN_FROM_BRIEF: leerer Brief → VALIDATION_ERROR', function () {
    Queue::fake();
    $res = $this->registry->get('foodalchemist.speiseplan.PLAN_FROM_BRIEF')->execute(['brief' => '  '], $this->ctx);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('VALIDATION_ERROR');
    Queue::assertNotPushed(MaterializeSpeiseplanCellJob::class);
});

it('angebot.PLAN_FROM_BRIEF: legt ein Angebot an, baut das Gerüst + startet die Voll-Kaskade (owner=offer)', function () {
    Queue::fake();
    bindeAusgabeGeruestStub(['name' => 'MCP-Angebot', 'slots' => [['label' => 'Vorspeise', 'slot_type' => 'gang', 'target_count' => 1]]]);

    $res = $this->registry->get('foodalchemist.angebot.PLAN_FROM_BRIEF')
        ->execute(['brief' => 'Sommerfest für 50 Gäste, 3-Gänge-Menü.', 'label' => 'MCP-Angebot'], $this->ctx);

    expect($res->success)->toBeTrue();
    $angebotId = (int) $res->data['angebot_id'];
    expect(FoodAlchemistAngebot::find($angebotId))->not->toBeNull();
    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'offer')->where('source_owner_id', $angebotId)->latest('id')->first();
    expect($run)->not->toBeNull()->and($run->scope)->toBe('vollkaskade')->and((bool) $run->staged)->toBeFalse();
    Queue::assertPushed(GenerateConceptJob::class, fn ($j) => $j->attachOwnerType === 'offer' && (int) $j->attachContainerId > 0);
});
