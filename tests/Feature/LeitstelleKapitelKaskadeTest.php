<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Livewire\Planung\KapitelRail;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec-42-Vollzug S3a — Kapitel-Ebenen-Planung in der Leitstelle: gezielter Kaskaden-Teil-Lauf je
 * Kapitel (statt des kaskaden-fremden `kapitelFreigeben`-Bypass) + Step↔Kapitel-Persistenz + M3-Ziele.
 */

/** Provider-Stub: kontrolliertes Gerüst-JSON (eindeutiger Name gegen Parallel-Redeclare). */
function bindeS3GeruestStub(array $werte): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($werte) implements LLMProviderContract
    {
        public function __construct(private array $werte) {}

        public function getName(): string
        {
            return 's3-stub';
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

/** Legt ein Foodbook mit 2 Gerüst-Slots→Kapitel an (über den Leitstelle-„aus Brief"-Weg). */
function macheFoodbookMitKapiteln(\Platform\Core\Models\Team $rootTeam): FoodAlchemistFoodbook
{
    bindeS3GeruestStub([
        'name' => 'Kapitel-FB',
        'slots' => [
            ['label' => 'Vorspeise', 'slot_type' => 'gang', 'target_count' => 2],
            ['label' => 'Hauptgang', 'slot_type' => 'gang', 'is_pflicht' => true],
        ],
    ]);
    Livewire::test(PlanungIndex::class)
        ->set('fbTitel', 'Kapitel-FB')
        ->set('fbBrief', 'Menü für 60 Gäste, gehoben.')
        ->call('foodbookAusBrief');

    return FoodAlchemistFoodbook::where('team_id', $rootTeam->id)->where('label', 'Kapitel-FB')->latest('id')->firstOrFail();
}

it('Voll-Lauf: jeder concept-Step trägt sein chapter_id (Helfer greift in beiden Pfaden)', function () {
    Queue::fake();
    $fb = macheFoodbookMitKapiteln($this->rootTeam);
    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'foodbook')->where('source_owner_id', $fb->id)
        ->where('created_via', 'leitstelle_foodbook_brief')->latest('id')->firstOrFail();

    $steps = FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->where('kind', 'concept')->get();
    expect($steps->count())->toBeGreaterThanOrEqual(2)
        ->and($steps->every(fn ($s) => $s->chapter_id !== null))->toBeTrue();
    // Die getragenen Kapitel = die Foodbook-Kapitel.
    $kapIds = FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)->pluck('id')->map(fn ($x) => (int) $x)->sort()->values()->all();
    $stepKaps = $steps->pluck('chapter_id')->map(fn ($x) => (int) $x)->unique()->sort()->values()->all();
    expect($stepKaps)->toBe($kapIds);
});

it('starteKapitelKaskade dockt genau EIN Kapitel (1 Step, 1 Job, kein zweiter Slot)', function () {
    Queue::fake();
    $fb = macheFoodbookMitKapiteln($this->rootTeam);
    $kapA = (int) FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)->orderBy('id')->value('id');

    Queue::fake();   // Fake zurücksetzen → nur der Kapitel-Job wird erfasst
    app(PlanningCascadeService::class)->starteKapitelKaskade($this->rootTeam, null, 'voll_kreativ', (int) $fb->id, $kapA);

    $run = FoodAlchemistCascadeRun::where('created_via', 'leitstelle_kapitel_go')->latest('id')->firstOrFail();
    expect($run->scope)->toBe('vollkaskade')
        ->and($run->source_owner_type)->toBe('foodbook')
        ->and((int) $run->source_owner_id)->toBe((int) $fb->id);

    $steps = FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->get();
    expect($steps->count())->toBe(1)
        ->and((int) $steps->first()->chapter_id)->toBe($kapA);

    Queue::assertPushed(GenerateConceptJob::class, 1);
    Queue::assertPushed(GenerateConceptJob::class, fn ($j) => $j->attachOwnerType === 'foodbook' && (int) $j->attachContainerId === $kapA);
});

it('M3→Brief: editierte Kapitel-Zielanzahl schlägt den Slot im Concept-Brief', function () {
    Queue::fake();
    $fb = macheFoodbookMitKapiteln($this->rootTeam);
    $kapA = (int) FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)->orderBy('id')->value('id');
    // Kapitel-Zielanzahl auf einen markanten Wert setzen (schlägt den Slot-Wert 2).
    FoodAlchemistFoodbookKapitel::whereKey($kapA)->update(['target_count' => 7]);

    Queue::fake();
    app(PlanningCascadeService::class)->starteKapitelKaskade($this->rootTeam, null, 'voll_kreativ', (int) $fb->id, $kapA);

    Queue::assertPushed(GenerateConceptJob::class, fn ($j) => (int) $j->attachContainerId === $kapA && str_contains((string) $j->brief, 'Zielanzahl Gerichte: 7'));
});

it('KapitelRail: zieleSpeichern + zielgruppeToggle schreiben über die reinen Setter', function () {
    Queue::fake();
    $fb = macheFoodbookMitKapiteln($this->rootTeam);
    $kapA = (int) FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)->orderBy('id')->value('id');

    $comp = Livewire::test(KapitelRail::class, ['foodbookId' => (int) $fb->id])
        ->call('oeffne', $kapA)
        ->set('ziel.niveau', 'gehoben')
        ->set('ziel.target_count', 5)
        ->set('ziel.price_min', '')          // Leerstring → null
        ->call('zieleSpeichern');

    $k = FoodAlchemistFoodbookKapitel::findOrFail($kapA);
    expect($k->niveau)->toBe('gehoben')
        ->and((int) $k->target_count)->toBe(5)
        ->and($k->price_min)->toBeNull();

    // Zielgruppen-Toggle (nur wenn Vokabular existiert) — sonst überspringen.
    $zg = \Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup::visibleToTeam($this->rootTeam)->where('is_inactive', false)->first();
    if ($zg !== null) {
        $comp->call('zielgruppeToggle', $zg->id);
        expect($k->fresh()->targetGroups()->where('id', $zg->id)->exists())->toBeTrue();
    }
});

it('regeneriereStep behält den Kapitel-Attach (kein stiller Datenverlust)', function () {
    Queue::fake();
    $fb = macheFoodbookMitKapiteln($this->rootTeam);
    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'foodbook')->where('source_owner_id', $fb->id)->latest('id')->firstOrFail();
    $step = FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->where('kind', 'concept')->whereNotNull('chapter_id')->firstOrFail();
    $kap = (int) $step->chapter_id;

    Queue::fake();
    app(PlanningCascadeService::class)->regeneriereStep($this->rootTeam, (int) $step->id);

    Queue::assertPushed(GenerateConceptJob::class, fn ($j) => $j->attachOwnerType === 'foodbook' && (int) $j->attachContainerId === $kap);
});

it('Guard: fremdes Team kann das Kapitel nicht über die Kaskade erzeugen', function () {
    Queue::fake();
    $fb = macheFoodbookMitKapiteln($this->rootTeam);
    $kapA = (int) FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)->orderBy('id')->value('id');

    // childB besitzt das rootTeam-Foodbook nicht → der Owner-Guard (strukturAusGeruest/ownedKapitel) greift.
    Queue::fake();
    expect(fn () => app(PlanningCascadeService::class)->starteKapitelKaskade($this->childB, null, 'voll_kreativ', (int) $fb->id, $kapA))
        ->toThrow(\RuntimeException::class);
    Queue::assertNotPushed(GenerateConceptJob::class);
});
