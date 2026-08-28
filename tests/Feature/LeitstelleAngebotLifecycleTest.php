<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #5 (Dominique 2026-08-28) — „Angebot aus Brief" IN der Leitstelle. Spiegelt den Speisekarte-Weg,
 * owner_type=offer: die „aus Brief"-Kette ist owner-generisch; jedes erzeugte Konzept dockt direkt ans
 * Angebot (Pivot foodalchemist_offer_concept, KEIN Zwischen-Container → containerId = Angebots-ID).
 */

/** Provider-Stub: kontrolliertes Gerüst-JSON (eindeutiger Name gegen Parallel-Redeclare). */
function bindeOfferGeruestStub(array $werte): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($werte) implements LLMProviderContract
    {
        public function __construct(private array $werte) {}

        public function getName(): string
        {
            return 'offer-stub';
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

it('angebotAusBrief: plant Konzepte in der Leitstelle und dockt ans neue Angebot (owner=offer)', function () {
    Queue::fake();
    bindeOfferGeruestStub([
        'name' => 'Sommerfest-Angebot',
        'slots' => [
            ['label' => 'Flying Fingerfood', 'slot_type' => 'gang', 'target_count' => 2],
            ['label' => 'Hauptgang-Buffet', 'slot_type' => 'gang', 'is_pflicht' => true],
        ],
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('offerTitel', 'Sommerfest-Angebot')
        ->set('offerBrief', 'Angebot für ein Sommerfest, 80 Gäste, Flying + Buffet, gehobenes Niveau.')
        ->call('angebotAusBrief')
        ->assertSet('offerMeldung', null)
        ->assertSet('offerBrief', '');

    // 1. Angebots-Hülle angelegt.
    $angebot = FoodAlchemistAngebot::where('team_id', $this->rootTeam->id)
        ->where('name', 'Sommerfest-Angebot')->latest('id')->first();
    expect($angebot)->not->toBeNull();

    // 2. Review-Session mit eigener Provenienz.
    $session = FoodAlchemistPlanningSession::where('team_id', $this->rootTeam->id)
        ->where('created_via', 'leitstelle_offer_brief')->latest('id')->first();
    expect($session)->not->toBeNull();

    // 3. Voll-Kaskade trägt den offer-Owner auf dem Lauf.
    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'offer')
        ->where('source_owner_id', $angebot->id)->latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->scope)->toBe('vollkaskade')
        ->and($run->planning_session_id)->toBe($session->id);

    // 4. Je Slot ein Concept-Job, der ans Angebot zurückdockt (containerId = Angebots-ID).
    Queue::assertPushed(GenerateConceptJob::class, fn ($job) => $job->attachOwnerType === 'offer' && (int) $job->attachContainerId === (int) $angebot->id);
});

it('angebotAusBrief mit offerOwnerId (Handoff): plant für ein bestehendes Angebot, legt kein neues an', function () {
    Queue::fake();
    bindeOfferGeruestStub([
        'name' => 'Bestehend',
        'slots' => [['label' => 'Slot 1', 'slot_type' => 'gang', 'target_count' => 2]],
    ]);
    $angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Bestehendes Angebot']);
    $vorher = FoodAlchemistAngebot::where('team_id', $this->rootTeam->id)->count();

    Livewire::test(PlanungIndex::class)
        ->set('offerOwnerId', $angebot->id)
        ->set('offerBrief', 'Brief für ein bestehendes Angebot.')
        ->call('angebotAusBrief')
        ->assertSet('offerMeldung', null);

    expect(FoodAlchemistAngebot::where('team_id', $this->rootTeam->id)->count())->toBe($vorher);
    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'offer')
        ->where('source_owner_id', $angebot->id)->latest('id')->first();
    expect($run)->not->toBeNull()->and($run->scope)->toBe('vollkaskade');
});

it('angebotAusBrief: leerer Brief → Meldung, nichts angelegt', function () {
    Queue::fake();

    $comp = Livewire::test(PlanungIndex::class)
        ->set('offerBrief', '   ')
        ->call('angebotAusBrief');

    expect($comp->get('offerMeldung'))->not->toBeNull();
    expect(FoodAlchemistAngebot::where('team_id', $this->rootTeam->id)->count())->toBe(0);
    Queue::assertNotPushed(GenerateConceptJob::class);
});

it('Angebot-Auswähler aktiviert die jüngste Owner-Session (updatedOfferOwnerId)', function () {
    $angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Gala-Angebot']);
    $session = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)
        ->create($this->rootTeam, ['title' => 'Angebot-Planung']);
    FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'vollkaskade',
        'creative_mode' => 'voll_kreativ', 'brief' => 'x', 'status' => 'done', 'staged' => false,
        'source_owner_type' => 'offer', 'source_owner_id' => $angebot->id, 'created_via' => 'test',
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('offerOwnerId', $angebot->id)
        ->assertSet('offerOwnerId', $angebot->id)
        ->assertSet('sessionId', $session->id);
});
