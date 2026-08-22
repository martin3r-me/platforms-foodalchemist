<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 42 F1 — „Foodbook aus Brief" IN der Leitstelle: der Rahmen (Gerüst/Struktur) wird nicht mehr
 * im Foodbook-Modul, sondern in der Leitstelle geplant; das Foodbook wird zur reinen Ausgabe.
 */

/** Provider-Stub: kontrolliertes Gerüst-JSON (eindeutiger Name gegen Parallel-Redeclare). */
function bindeF1GeruestStub(array $werte): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($werte) implements LLMProviderContract
    {
        public function __construct(private array $werte) {}

        public function getName(): string
        {
            return 'f1-stub';
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

it('foodbookAusBrief: plant Rahmen + Inhalte in der Leitstelle und dockt ans neue Foodbook (owner=foodbook)', function () {
    Queue::fake();
    bindeF1GeruestStub([
        'name' => 'Sommer-Gala',
        'slots' => [
            ['label' => 'Vorspeise', 'slot_type' => 'gang', 'target_count' => 2],
            ['label' => 'Hauptgang', 'slot_type' => 'gang', 'is_pflicht' => true],
        ],
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('fbTitel', 'Sommer-Gala')
        ->set('fbBrief', 'Sommerliches Galadinner für 80 Gäste, gehobenes Niveau.')
        ->call('foodbookAusBrief')
        ->assertSet('fbMeldung', null)
        ->assertSet('fbBrief', '');

    // 1. Foodbook-Hülle angelegt.
    $fb = FoodAlchemistFoodbook::where('team_id', $this->rootTeam->id)
        ->where('label', 'Sommer-Gala')->latest('id')->first();
    expect($fb)->not->toBeNull();

    // 2. Struktur angewendet: Gerüst-Slots → Kapitel.
    expect($fb->chapters()->count())->toBeGreaterThanOrEqual(1);

    // 3. Review-Session mit eigener Provenienz.
    $session = FoodAlchemistPlanningSession::where('team_id', $this->rootTeam->id)
        ->where('created_via', 'leitstelle_foodbook_brief')->latest('id')->first();
    expect($session)->not->toBeNull();

    // 4. Voll-Kaskade trägt den Foodbook-Owner auf dem Lauf (Round-Trip / Owner-Banner).
    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'foodbook')
        ->where('source_owner_id', $fb->id)->latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->scope)->toBe('vollkaskade')
        ->and($run->planning_session_id)->toBe($session->id);

    // 5. Je Slot ein Concept-Job, der ins Foodbook zurückdockt.
    Queue::assertPushed(GenerateConceptJob::class, fn ($job) => $job->attachOwnerType === 'foodbook' && (int) $job->attachContainerId > 0);
});

it('foodbookAusBrief mit fbOwnerId (Handoff aus dem Modul): plant für ein bestehendes Foodbook, legt kein neues an', function () {
    Queue::fake();
    bindeF1GeruestStub([
        'name' => 'Bestehend',
        'slots' => [['label' => 'Gang 1', 'slot_type' => 'gang', 'target_count' => 2]],
    ]);
    $fb = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'Bestehendes FB']);
    $vorher = FoodAlchemistFoodbook::where('team_id', $this->rootTeam->id)->count();

    Livewire::test(PlanungIndex::class)
        ->set('fbOwnerId', $fb->id)
        ->set('fbBrief', 'Brief für ein bestehendes Foodbook.')
        ->call('foodbookAusBrief')
        ->assertSet('fbMeldung', null);

    // Kein neues Foodbook — der bestehende Owner wird bebrieft.
    expect(FoodAlchemistFoodbook::where('team_id', $this->rootTeam->id)->count())->toBe($vorher);
    // Voll-Kaskade hängt am bestehenden Foodbook.
    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'foodbook')
        ->where('source_owner_id', $fb->id)->latest('id')->first();
    expect($run)->not->toBeNull()->and($run->scope)->toBe('vollkaskade');
});

it('foodbookAusBrief: leerer Brief → Meldung, nichts angelegt', function () {
    Queue::fake();

    $comp = Livewire::test(PlanungIndex::class)
        ->set('fbBrief', '   ')
        ->call('foodbookAusBrief');

    expect($comp->get('fbMeldung'))->not->toBeNull();
    expect(FoodAlchemistFoodbook::where('team_id', $this->rootTeam->id)->count())->toBe(0);
    Queue::assertNotPushed(GenerateConceptJob::class);
});
