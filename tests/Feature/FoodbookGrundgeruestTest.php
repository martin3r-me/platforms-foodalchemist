<?php

use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Foodbook-Grundgerüst = KAPITEL (nicht Gänge). Behebt „Menü mit 4 Gängen → 4 Kapitel": für
 * owner=foodbook läuft der eigene Prompt `foodbook.grundgeruest`, die gang-erzwingenden Nachbearbeiter
 * (expandiereContainerGeruest/menueGaengeCap) werden übersprungen, und die Slots werden defensiv auf
 * kapitel gezwungen. Die Gänge entstehen erst im Kapitel-Concept (menue_gaenge, Concept-Ebene).
 */

/** Stub-Provider: liefert exakt das vorgegebene Gerüst-JSON (eindeutiger Name gegen Parallel-Redeclare). */
function bindeGgGeruestStub(array $werte): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($werte) implements LLMProviderContract
    {
        public function __construct(private array $werte) {}

        public function getName(): string { return 'gg-geruest-stub'; }

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
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('Foodbook: 1 KI-Slot bleibt 1 KAPITEL — kein Menü→Gang-Aufblähen, kein Gang=Kapitel', function () {
    // Die KI liefert (wie beim alten Menü→gang-Prompt) EINEN gang-Slot für ein 4-Gänge-Menü.
    bindeGgGeruestStub(['name' => 'Herbstbuch', 'slots' => [
        ['label' => 'Herbst-Galamenü', 'slot_type' => 'gang', 'target_count' => 4],
    ]]);
    $fb = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'Herbstbuch']);

    app(ConceptGeneratorService::class)->geruestAusBriefFuerOwner($this->rootTeam, 'foodbook', (int) $fb->id, 'Ein 4-Gänge-Menü für ein Galadinner.');

    $frame = app(PlanningFrameService::class)->find('foodbook', (int) $fb->id);
    // expandiereContainerGeruest übersprungen → NICHT auf 4 gang-Slots aufgebläht; Slot auf kapitel gezwungen.
    expect($frame->slots)->toHaveCount(1)
        ->and($frame->slots->first()->slot_type)->toBe('kapitel')
        ->and($frame->slots->first()->label)->toBe('Herbst-Galamenü');
});

it('Foodbook: mehrere KI-Slots werden ALLE zu KAPITEL (auch gang-getippte)', function () {
    bindeGgGeruestStub(['name' => 'Buch', 'slots' => [
        ['label' => 'Empfang', 'slot_type' => 'kapitel', 'target_count' => 3],
        ['label' => 'Galamenü', 'slot_type' => 'gang', 'target_count' => 4],   // KI vertippt sich → muss kapitel werden
    ]]);
    $fb = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'Buch']);

    app(ConceptGeneratorService::class)->geruestAusBriefFuerOwner($this->rootTeam, 'foodbook', (int) $fb->id, 'Foodbook mit Empfang und Galamenü.');

    $frame = app(PlanningFrameService::class)->find('foodbook', (int) $fb->id);
    expect($frame->slots)->toHaveCount(2)
        ->and($frame->slots->pluck('slot_type')->unique()->values()->all())->toBe(['kapitel']);
});

it('Concept (Kontrolle): Slots bleiben gang — die kapitel-Zwang-Coercion ist foodbook-only', function () {
    // Gleicher gang-Input, aber owner=concept → NICHT auf kapitel gezwungen (der Fix ist foodbook-spezifisch).
    bindeGgGeruestStub(['name' => 'Menü', 'slots' => [
        ['label' => 'Vorspeise', 'slot_type' => 'gang', 'target_count' => 1],
        ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1],
    ]]);
    $concept = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Kontroll-Menü']);

    app(ConceptGeneratorService::class)->geruestAusBriefFuerOwner($this->rootTeam, 'concept', (int) $concept->id, 'Ein Menü für ein Galadinner.');

    $frame = app(PlanningFrameService::class)->find('concept', (int) $concept->id);
    // Kein Slot ist kapitel (die foodbook-Coercion greift hier NICHT); gang bleibt gang.
    expect($frame->slots->pluck('slot_type')->unique()->values()->all())->toBe(['gang']);
});
