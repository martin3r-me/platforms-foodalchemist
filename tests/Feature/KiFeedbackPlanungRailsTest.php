<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Planung\FoodbookKontextRail;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Livewire\Planung\KapitelRail;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G (Spec 53), Restliste — dieselbe Umstellung wie im Recipe-/VK-Modal
 * ({@see \tests\Feature\KiFeedbackRecipeModalTest}), für die zwei Planungs-
 * Leitstelle-Rails, die NICHT zu Peters aktivem Arbeitsbereich gehören
 * (vom Orchestrator explizit in die PR-2-Reihenfolge aufgenommen).
 */
function bindeS3GeruestStubPaketG(array $werte): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($werte) implements LLMProviderContract
    {
        public function __construct(private array $werte) {}

        public function getName(): string
        {
            return 's3-stub-paket-g';
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

it('FoodbookKontextRail: „KI-Text" (kiEinleitung) hat data-ki-action + wire:target', function () {
    Queue::fake();
    bindeS3GeruestStubPaketG([
        'name' => 'Rail-FB', 'slots' => [['label' => 'Vorspeise', 'slot_type' => 'gang', 'target_count' => 2]],
    ]);
    Livewire::test(PlanungIndex::class)
        ->set('fbTitel', 'Rail-FB')->set('fbBrief', 'Menü für 20 Gäste.')->call('foodbookAusBrief');
    $fb = FoodAlchemistFoodbook::where('team_id', $this->rootTeam->id)->where('label', 'Rail-FB')->latest('id')->firstOrFail();

    $html = Livewire::test(FoodbookKontextRail::class, ['foodbookId' => (int) $fb->id])->html();

    expect($html)->toContain('data-ki-action="kiEinleitung"')
        ->toContain('wire:target="kiEinleitung"');
});

it('KapitelRail: „Kapitel erzeugen" (kapitelErzeugen) hat data-ki-action + wire:target', function () {
    Queue::fake();
    bindeS3GeruestStubPaketG([
        'name' => 'Rail-Kapitel-FB', 'slots' => [['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 2]],
    ]);
    Livewire::test(PlanungIndex::class)
        ->set('fbTitel', 'Rail-Kapitel-FB')->set('fbBrief', 'Menü für 20 Gäste.')->call('foodbookAusBrief');
    $fb = FoodAlchemistFoodbook::where('team_id', $this->rootTeam->id)->where('label', 'Rail-Kapitel-FB')->latest('id')->firstOrFail();
    $kapA = (int) FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)->orderBy('id')->value('id');

    $html = Livewire::test(KapitelRail::class, ['foodbookId' => (int) $fb->id])->html();

    expect($html)->toContain('data-ki-action="kapitelErzeugen('.$kapA.')"')
        ->toContain('wire:target="kapitelErzeugen"');
});
