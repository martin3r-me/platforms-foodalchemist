<?php

use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/** Provider-Stub: kontrolliertes Gerüst-JSON (kein echter LLM). */
function bindeGeruestStub(array $werte): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($werte) implements LLMProviderContract
    {
        public function __construct(private array $werte) {}

        public function getName(): string
        {
            return 'test-stub';
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

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Phase 3a: „Struktur anwenden" — Planungs-Gerüst-Slots als Kapitel materialisieren
 * (Slot = Kapitel, chapter_id-Kopplung). Idempotenz + Leer-Fall.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(FoodbookService::class);
    $this->frames = app(PlanningFrameService::class);
});

it('materialisiert Slots als Kapitel + setzt chapter_id (idempotent)', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Sommerfest']);
    $frame = $this->frames->frameFor($this->rootTeam, 'foodbook', $fb->id);
    $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Fingerfood', 'slot_type' => 'gang', 'target_count' => 5]);
    $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'is_pflicht' => true]);

    $r1 = $this->svc->strukturAusGeruest($this->rootTeam, $fb->id);
    expect($r1['kein_geruest'])->toBeFalse()
        ->and($r1['angelegt'])->toBe(2)
        ->and($r1['uebersprungen'])->toBe(0)
        ->and($fb->chapters()->count())->toBe(2)
        ->and($fb->chapters()->pluck('title')->all())->toContain('Fingerfood', 'Hauptgang');

    // Slots sind jetzt an Kapitel gekoppelt.
    $frame->refresh()->load('slots');
    expect($frame->slots->every(fn ($s) => $s->chapter_id !== null))->toBeTrue();

    // Idempotent: erneut anwenden legt nichts Neues an.
    $r2 = $this->svc->strukturAusGeruest($this->rootTeam, $fb->id);
    expect($r2['angelegt'])->toBe(0)
        ->and($r2['uebersprungen'])->toBe(2)
        ->and($fb->chapters()->count())->toBe(2);
});

it('ohne Gerüst mit Slots: kein_geruest = true, keine Kapitel', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Leer']);
    $r = $this->svc->strukturAusGeruest($this->rootTeam, $fb->id);
    expect($r['kein_geruest'])->toBeTrue()
        ->and($fb->chapters()->count())->toBe(0);
});

/**
 * Phase 5 Kickoff-Wizard: Brief → KI-Gerüst-Vorschlag für einen FOODBOOK-Owner
 * (owner-agnostisch, kein Konzept), das dann in „Struktur anwenden" mündet.
 */
it('Kickoff: Brief → KI-Gerüst am Foodbook, dann Struktur anwenden → Kapitel', function () {
    bindeGeruestStub([
        'name' => 'Sommer-Gala',
        'target_price_pp' => 45,
        'slots' => [
            ['label' => 'Vorspeise', 'slot_type' => 'gang', 'target_count' => 2],
            ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1, 'is_pflicht' => true],
        ],
        'rules' => [['rule_type' => 'nogo_ingredient', 'value_text' => 'Innereien', 'severity' => 'hart']],
    ]);

    $fb = $this->svc->create($this->rootTeam, ['label' => 'Adler Gala 2026']);
    $res = app(ConceptGeneratorService::class)->geruestAusBriefFuerOwner(
        $this->rootTeam, 'foodbook', $fb->id,
        "Anlass: Sommer-Gala\nGäste: 80 Personen\nBudget: 45 € pro Person",
        ['segment' => ['label' => 'Event-Catering', 'niveau' => 'gehoben']],
    );

    expect($res['slots'])->toBe(2)
        ->and($res['confidence'])->toBe(0.9)
        ->and($res['name'])->toBe('Sommer-Gala');

    // Gerüst hängt am FOODBOOK (nicht an einem Konzept) — Owner-Trennung.
    $frame = $this->frames->find('foodbook', $fb->id);
    expect($frame)->not->toBeNull()
        ->and($this->frames->find('concept', $fb->id))->toBeNull()
        ->and((float) $frame->target_price_pp)->toBe(45.0)
        ->and($frame->slots()->count())->toBe(2)
        ->and($frame->rules()->whereNull('slot_id')->count())->toBe(1);

    // Kette: der vorgeschlagene Rahmen mündet in „Struktur anwenden".
    $r = $this->svc->strukturAusGeruest($this->rootTeam, $fb->id);
    expect($r['angelegt'])->toBe(2)
        ->and($fb->chapters()->pluck('title')->all())->toContain('Vorspeise', 'Hauptgang');
});

it('Kickoff: leerer Brief wirft, KI ohne Slots wirft (graceful, kein Frame)', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Leer-Kickoff']);
    $svc = app(ConceptGeneratorService::class);

    expect(fn () => $svc->geruestAusBriefFuerOwner($this->rootTeam, 'foodbook', $fb->id, '   '))
        ->toThrow(RuntimeException::class);

    bindeGeruestStub(['name' => 'Ohne Slots', 'slots' => []]);
    expect(fn () => $svc->geruestAusBriefFuerOwner($this->rootTeam, 'foodbook', $fb->id, 'Anlass: Test'))
        ->toThrow(RuntimeException::class);

    // Kein Frame angelegt (beide Pfade brechen vor dem Schreiben ab).
    expect($this->frames->find('foodbook', $fb->id))->toBeNull();
});
