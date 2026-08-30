<?php

use Illuminate\Support\Facades\Queue;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** MCP-Tool format.PLAN_FROM_BRIEF — gebrandetes Foodkonzept aus Brief (vollkaskade owner=format, eager). */

/** Provider-Stub für das Format-Gerüst-JSON (eindeutiger Name gegen Parallel-Redeclare). */
function bindeFormatGeruestStub(array $werte): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($werte) implements LLMProviderContract
    {
        public function __construct(private array $werte) {}

        public function getName(): string { return 'format-geruest-stub'; }

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

it('Registry-Smoke: format.PLAN_FROM_BRIEF ist registriert + WRITE', function () {
    $tool = $this->registry->get('foodalchemist.format.PLAN_FROM_BRIEF');
    expect($tool)->not->toBeNull()
        ->and($tool->getName())->toBe('foodalchemist.format.PLAN_FROM_BRIEF')
        ->and($tool->getMetadata()['read_only'])->toBeFalse()
        ->and($tool->getMetadata()['risk_level'])->toBe('write');
});

it('format.PLAN_FROM_BRIEF: legt ein Format an, schreibt Branding aus dem Brief + startet den Baustein-Fan-out (owner=format, eager)', function () {
    Queue::fake();
    bindeFormatGeruestStub([
        'name' => 'TASTE.AND.FLY',
        'consumer_name' => 'Taste & Fly',
        'claim' => 'Fingerfood, das abhebt',
        'story' => 'Ein verspieltes Flying-Konzept für Empfänge.',
        'slots' => [
            ['label' => 'Kalte Signatures', 'slot_type' => 'station', 'target_count' => 3],
            ['label' => 'Warme Bissen', 'slot_type' => 'station', 'target_count' => 3],
        ],
    ]);

    $res = $this->registry->get('foodalchemist.format.PLAN_FROM_BRIEF')
        ->execute(['brief' => 'Ein Flying-Fingerfood-Konzept für Empfänge, verspielt-modern.', 'label' => 'Platzhalter'], $this->ctx);

    expect($res->success)->toBeTrue();
    $formatId = (int) $res->data['format_id'];
    expect($res->data['neu_angelegt'])->toBeTrue()
        ->and($res->data['branding_gesetzt'])->toContain('consumer_name')->toContain('claim');

    // Branding + Name wurden aufs Format geschrieben (Gerüst-Name überschreibt den Platzhalter).
    $format = FoodAlchemistFormat::find($formatId);
    expect($format->name)->toBe('TASTE.AND.FLY')
        ->and($format->consumer_name)->toBe('Taste & Fly')
        ->and($format->claim)->toBe('Fingerfood, das abhebt')
        ->and($format->story)->not->toBeNull();

    // Voll-Kaskade owner=format, eager (nicht staged); je Baustein-Slot ein Concept ans Format.
    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'format')->where('source_owner_id', $formatId)->latest('id')->first();
    expect($run)->not->toBeNull()->and($run->scope)->toBe('vollkaskade')->and((bool) $run->staged)->toBeFalse();
    Queue::assertPushed(GenerateConceptJob::class, fn ($j) => $j->attachOwnerType === 'format' && (int) $j->attachContainerId === $formatId);
});

it('format.PLAN_FROM_BRIEF: bestehendes Format bebriefen lässt die Marken-Identität unangetastet (neu=false)', function () {
    Queue::fake();
    $bestehend = app(FormatService::class)->create($this->rootTeam, ['name' => 'CHEFS.CORNER', 'consumer_name' => 'Chefs Corner', 'claim' => 'World on a Plate']);
    bindeFormatGeruestStub([
        'name' => 'SOLL-NICHT-UEBERSCHREIBEN',
        'consumer_name' => 'ANDERS',
        'slots' => [['label' => 'Station A', 'slot_type' => 'station', 'target_count' => 2]],
    ]);

    $res = $this->registry->get('foodalchemist.format.PLAN_FROM_BRIEF')
        ->execute(['brief' => 'Bestehendes Format neu befüllen.', 'format_id' => $bestehend->id], $this->ctx);

    expect($res->success)->toBeTrue()
        ->and($res->data['neu_angelegt'])->toBeFalse()
        ->and($res->data['branding_gesetzt'])->toBe([]);
    // Identität unverändert
    $format = FoodAlchemistFormat::find($bestehend->id);
    expect($format->name)->toBe('CHEFS.CORNER')->and($format->consumer_name)->toBe('Chefs Corner');
    // aber die Kaskade läuft (Bausteine werden ans Format gehängt)
    Queue::assertPushed(GenerateConceptJob::class, fn ($j) => $j->attachOwnerType === 'format' && (int) $j->attachContainerId === (int) $bestehend->id);
});

it('format.PLAN_FROM_BRIEF: leerer Brief → VALIDATION_ERROR', function () {
    Queue::fake();
    $res = $this->registry->get('foodalchemist.format.PLAN_FROM_BRIEF')->execute(['brief' => '  '], $this->ctx);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('VALIDATION_ERROR');
    Queue::assertNotPushed(GenerateConceptJob::class);
});
