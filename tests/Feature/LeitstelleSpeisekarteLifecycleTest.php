<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec-42-Vollzug Stufe 1 — „Speisekarte aus Brief" IN der Leitstelle. Spiegelt den Foodbook-Weg
 * (LeitstelleFoodbookLifecycleTest), aber owner_type=speisekarte: die „aus Brief"-Kette ist owner-
 * generisch, die Rubriken entstehen pro Slot im Fan-out (KEIN strukturAusGeruest), Rückdock als
 * menue_ref-Position.
 */

/** Provider-Stub: kontrolliertes Gerüst-JSON (eindeutiger Name gegen Parallel-Redeclare). */
function bindeSk1GeruestStub(array $werte): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($werte) implements LLMProviderContract
    {
        public function __construct(private array $werte) {}

        public function getName(): string
        {
            return 'sk1-stub';
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

it('speisekarteAusBrief: plant Rahmen + Inhalte in der Leitstelle und dockt an die neue Speisekarte (owner=speisekarte)', function () {
    Queue::fake();
    bindeSk1GeruestStub([
        'name' => 'Sommerkarte',
        'slots' => [
            ['label' => 'Vorspeisen', 'slot_type' => 'gang', 'target_count' => 2],
            ['label' => 'Hauptgerichte', 'slot_type' => 'gang', 'is_pflicht' => true],
        ],
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('skTitel', 'Sommerkarte')
        ->set('skBrief', 'À-la-carte-Sommerkarte, mediterran, gehobenes Niveau.')
        ->call('speisekarteAusBrief')
        ->assertSet('skMeldung', null)
        ->assertSet('skBrief', '');

    // 1. Speisekarte-Hülle angelegt.
    $karte = FoodAlchemistSpeisekarte::where('team_id', $this->rootTeam->id)
        ->where('name', 'Sommerkarte')->latest('id')->first();
    expect($karte)->not->toBeNull();

    // 2. Rubriken je Slot synchron angelegt (rubrikFuerSlot im Fan-out — KEIN strukturAusGeruest).
    expect($karte->rubriken()->count())->toBeGreaterThanOrEqual(1);

    // 3. Review-Session mit eigener Provenienz.
    $session = FoodAlchemistPlanningSession::where('team_id', $this->rootTeam->id)
        ->where('created_via', 'leitstelle_speisekarte_brief')->latest('id')->first();
    expect($session)->not->toBeNull();

    // 4. Voll-Kaskade trägt den Speisekarte-Owner auf dem Lauf.
    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'speisekarte')
        ->where('source_owner_id', $karte->id)->latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->scope)->toBe('vollkaskade')
        ->and($run->planning_session_id)->toBe($session->id);

    // 5. Je Slot ein Concept-Job, der in die Speisekarte zurückdockt (menue_ref-Position).
    Queue::assertPushed(GenerateConceptJob::class, fn ($job) => $job->attachOwnerType === 'speisekarte' && (int) $job->attachContainerId > 0);
});

it('speisekarteAusBrief mit skOwnerId (Handoff aus dem Modul): plant für eine bestehende Karte, legt keine neue an', function () {
    Queue::fake();
    bindeSk1GeruestStub([
        'name' => 'Bestehend',
        'slots' => [['label' => 'Rubrik 1', 'slot_type' => 'gang', 'target_count' => 2]],
    ]);
    $karte = app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'Bestehende Karte']);
    $vorher = FoodAlchemistSpeisekarte::where('team_id', $this->rootTeam->id)->count();

    Livewire::test(PlanungIndex::class)
        ->set('skOwnerId', $karte->id)
        ->set('skBrief', 'Brief für eine bestehende Speisekarte.')
        ->call('speisekarteAusBrief')
        ->assertSet('skMeldung', null);

    // Keine neue Karte — der bestehende Owner wird bebrieft.
    expect(FoodAlchemistSpeisekarte::where('team_id', $this->rootTeam->id)->count())->toBe($vorher);
    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'speisekarte')
        ->where('source_owner_id', $karte->id)->latest('id')->first();
    expect($run)->not->toBeNull()->and($run->scope)->toBe('vollkaskade');
});

it('speisekarteAusBrief: leerer Brief → Meldung, nichts angelegt', function () {
    Queue::fake();

    $comp = Livewire::test(PlanungIndex::class)
        ->set('skBrief', '   ')
        ->call('speisekarteAusBrief');

    expect($comp->get('skMeldung'))->not->toBeNull();
    expect(FoodAlchemistSpeisekarte::where('team_id', $this->rootTeam->id)->count())->toBe(0);
    Queue::assertNotPushed(GenerateConceptJob::class);
});
