<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * „Format aus Brief" als Kickoff-Tab in der Leitstelle (Leitstellen-Einstieg zum MCP-Pfad
 * FormatPlanFromBriefTool). owner_type=format: die aus-Brief-Kette ist owner-generisch, jedes
 * Konzept dockt ans Format (FormatService::slotConceptEinfuegen).
 */
function bindeLeitstelleFormatGeruestStub(array $werte): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($werte) implements LLMProviderContract
    {
        public function __construct(private array $werte) {}

        public function getName(): string
        {
            return 'format-stub';
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
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('formatAusBrief: plant Konzepte in der Leitstelle und dockt ans neue Format (owner=format)', function () {
    Queue::fake();
    bindeLeitstelleFormatGeruestStub([
        'name' => 'Streetfood Weltreise',
        'slots' => [
            ['label' => 'Station Asien', 'slot_type' => 'gang', 'target_count' => 2],
            ['label' => 'Station Amerika', 'slot_type' => 'gang', 'is_pflicht' => true],
        ],
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('fmtTitel', 'Streetfood Weltreise')
        ->set('fmtBrief', 'Gebrandetes Streetfood-Format, 2 Stationen, gehobenes Business-Catering.')
        ->call('formatAusBrief')
        ->assertSet('fmtMeldung', null)
        ->assertSet('fmtBrief', '');

    $format = FoodAlchemistFormat::where('team_id', $this->rootTeam->id)->latest('id')->first();
    expect($format)->not->toBeNull();

    $session = FoodAlchemistPlanningSession::where('team_id', $this->rootTeam->id)
        ->where('created_via', 'leitstelle_format_brief')->latest('id')->first();
    expect($session)->not->toBeNull();

    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'format')
        ->where('source_owner_id', $format->id)->latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->scope)->toBe('vollkaskade')
        ->and($run->planning_session_id)->toBe($session->id);

    Queue::assertPushed(GenerateConceptJob::class, fn ($job) => $job->attachOwnerType === 'format' && (int) $job->attachContainerId === (int) $format->id);
});

it('formatAusBrief mit fmtOwnerId (Handoff): plant für ein bestehendes Format, legt kein neues an', function () {
    Queue::fake();
    bindeLeitstelleFormatGeruestStub(['name' => 'Bestehend', 'slots' => [['label' => 'Slot 1', 'slot_type' => 'gang', 'target_count' => 2]]]);
    $format = app(FormatService::class)->create($this->rootTeam, ['name' => 'Bestehendes Format', 'origin' => 'eigen']);
    $vorher = FoodAlchemistFormat::where('team_id', $this->rootTeam->id)->count();

    Livewire::test(PlanungIndex::class)
        ->set('fmtOwnerId', $format->id)
        ->set('fmtBrief', 'Brief für ein bestehendes Format.')
        ->call('formatAusBrief')
        ->assertSet('fmtMeldung', null);

    expect(FoodAlchemistFormat::where('team_id', $this->rootTeam->id)->count())->toBe($vorher);
    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'format')
        ->where('source_owner_id', $format->id)->latest('id')->first();
    expect($run)->not->toBeNull()->and($run->scope)->toBe('vollkaskade');
});

it('formatAusBrief: leerer Brief → Meldung, nichts angelegt', function () {
    Queue::fake();

    $comp = Livewire::test(PlanungIndex::class)
        ->set('fmtBrief', '   ')
        ->call('formatAusBrief');

    expect($comp->get('fmtMeldung'))->not->toBeNull();
    expect(FoodAlchemistFormat::where('team_id', $this->rootTeam->id)->count())->toBe(0);
    Queue::assertNotPushed(GenerateConceptJob::class);
});

it('Format-Auswähler aktiviert die jüngste Owner-Session (updatedFmtOwnerId)', function () {
    $format = app(FormatService::class)->create($this->rootTeam, ['name' => 'Gala-Format', 'origin' => 'eigen']);
    $session = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)
        ->create($this->rootTeam, ['title' => 'Format-Planung']);
    FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'vollkaskade',
        'creative_mode' => 'voll_kreativ', 'brief' => 'x', 'status' => 'done', 'staged' => false,
        'source_owner_type' => 'format', 'source_owner_id' => $format->id, 'created_via' => 'test',
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('fmtOwnerId', $format->id)
        ->assertSet('fmtOwnerId', $format->id)
        ->assertSet('sessionId', $session->id);
});
