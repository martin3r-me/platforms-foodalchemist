<?php

use Illuminate\Support\Facades\Queue;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 · Paket C-5: Format-Editionen mit Struktur per MCP — `format_editions.POST neu={name, geruest}`
 * geht denselben Weg wie der UI-Button (+ Gerüst) bzw. `concepts.POST geruest`; PLAN_FROM_BRIEF füllt
 * Branding-Lücken auch am bestehenden Format (Override-First, GL-07).
 */
function c5BindeGeruestStub(array $werte): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($werte) implements LLMProviderContract
    {
        public function __construct(private array $werte) {}

        public function getName(): string { return 'c5-geruest-stub'; }

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
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->format = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'CHEFS.CORNER']);
});

it('format_editions.POST neu ohne geruest: UI-Standard-Edition — aktiv, Sektions-Header, als Slot referenziert', function () {
    $res = ($this->run)('foodalchemist.format_editions.POST', ['format_id' => $this->format->id, 'neu' => ['name' => 'Frühjahr']]);
    expect($res->success)->toBeTrue()
        ->and($res->data['geruest']['typ'])->toBe('sektionen')
        ->and($res->data['geruest']['status'])->toBe('active')
        ->and($res->data['geruest']['header'])->toBe(count(FormatService::SEKTIONS_GERUEST))
        ->and($res->data['geruest']['positionen_leer'])->toBe(0)
        ->and($res->data['note'])->toContain('Sektions-Header');

    $c = FoodAlchemistConcept::find($res->data['edition']['concept_id']);
    expect($c->name)->toBe('Frühjahr')->and($c->status)->toBe('active');
    // Exakt dieselbe Struktur wie der UI-Button (+ Gerüst) — kein flaches Concept.
    expect(FoodAlchemistConceptSlot::where('concept_id', $c->id)->orderBy('position')->pluck('title')->all())
        ->toBe(FormatService::SEKTIONS_GERUEST);
    expect(FoodAlchemistFormatSlot::where('format_id', $this->format->id)->where('concept_id', $c->id)->exists())->toBeTrue();
    // ohne Planungs-Gerüst (Sektionen sind UI-Default, kein Frame)
    expect(app(PlanningFrameService::class)->find('concept', (int) $c->id))->toBeNull();
});

it('format_editions.POST neu mit geruest=menue: kanonische Struktur wie concepts.POST geruest — Entwurf, Header + leere Positionen, Frame', function () {
    $res = ($this->run)('foodalchemist.format_editions.POST', [
        'format_id' => $this->format->id, 'neu' => ['name' => 'Herbst', 'geruest' => ['typ' => 'menue', 'gaenge' => 3]],
    ]);
    expect($res->success)->toBeTrue()
        ->and($res->data['geruest']['typ'])->toBe('menue')
        ->and($res->data['geruest']['status'])->toBe('draft')
        ->and($res->data['geruest']['header'])->toBe(3)
        ->and($res->data['geruest']['positionen_leer'])->toBe(3)
        ->and($res->data['note'])->toContain('concept_slots.PUT');

    $cid = (int) $res->data['edition']['concept_id'];
    $slots = FoodAlchemistConceptSlot::where('concept_id', $cid)->orderBy('position')->get();
    expect($slots->pluck('type')->all())->toBe(['header', 'gericht', 'header', 'gericht', 'header', 'gericht']);
    foreach ($slots->where('type', 'header') as $h) {
        expect($h->title)->not->toBeEmpty()->and($h->role)->toBe($h->title);
    }
    expect(app(PlanningFrameService::class)->find('concept', $cid))->not->toBeNull()
        ->and(FoodAlchemistFormatSlot::where('format_id', $this->format->id)->where('concept_id', $cid)->exists())->toBeTrue();
});

it('format_editions.POST: XOR concept_id/neu, ungültiger Gerüst-Typ hinterlässt kein verwaistes Concept', function () {
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Bestand', 'status' => 'active']);
    $beides = ($this->run)('foodalchemist.format_editions.POST', ['format_id' => $this->format->id, 'concept_id' => $c->id, 'neu' => ['name' => 'X']]);
    $keins = ($this->run)('foodalchemist.format_editions.POST', ['format_id' => $this->format->id]);
    expect($beides->success)->toBeFalse()->and($beides->errorCode)->toBe('VALIDATION_ERROR')
        ->and($keins->success)->toBeFalse()->and($keins->errorCode)->toBe('VALIDATION_ERROR');

    $vorher = FoodAlchemistConcept::count();
    $falsch = ($this->run)('foodalchemist.format_editions.POST', [
        'format_id' => $this->format->id, 'neu' => ['name' => 'Kaputt', 'geruest' => ['typ' => 'cocktail']],
    ]);
    expect($falsch->success)->toBeFalse()->and($falsch->errorCode)->toBe('VALIDATION_ERROR')
        ->and($falsch->error)->toContain('menue | buffet')
        ->and(FoodAlchemistConcept::count())->toBe($vorher)
        ->and(FoodAlchemistFormatSlot::where('format_id', $this->format->id)->count())->toBe(0);
});

it('format_editions.POST neu: fremdes Format ⇒ NOT_FOUND', function () {
    $fremdTeam = \Platform\Core\Models\Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false]);
    $fremd = FoodAlchemistFormat::create(['team_id' => $fremdTeam->id, 'name' => 'FREMD']);
    $res = ($this->run)('foodalchemist.format_editions.POST', ['format_id' => $fremd->id, 'neu' => ['name' => 'X']]);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
});

it('FormatService::neueEdition ist der eine Weg für UI und MCP — Editor::neueEdition erzeugt dieselbe Struktur', function () {
    $e = app(FormatService::class)->neueEdition($this->rootTeam, $this->format->id, null, null, null);
    expect($e['concept']->name)->toBe('Neue Edition')
        ->and($e['header'])->toBe(count(FormatService::SEKTIONS_GERUEST))
        ->and((int) $e['slot']->format_id)->toBe($this->format->id);
});

it('format.PLAN_FROM_BRIEF: bestehendes Format — Branding-LÜCKEN werden gefüllt, gesetzte Identität bleibt (Override-First)', function () {
    Queue::fake();
    $bestehend = app(FormatService::class)->create($this->rootTeam, ['name' => 'CHEFS.CORNER', 'consumer_name' => 'Chefs Corner']);
    c5BindeGeruestStub([
        'name' => 'SOLL-NICHT-UEBERSCHREIBEN',
        'consumer_name' => 'ANDERS',
        'claim' => 'World on a Plate',
        'story' => 'Köche im Rampenlicht.',
        'slots' => [['label' => 'Station A', 'slot_type' => 'station', 'target_count' => 2]],
    ]);

    $res = ($this->run)('foodalchemist.format.PLAN_FROM_BRIEF', ['brief' => 'Bestehendes Format neu befüllen.', 'format_id' => $bestehend->id]);
    expect($res->success)->toBeTrue()
        ->and($res->data['neu_angelegt'])->toBeFalse()
        ->and($res->data['branding_gesetzt'])->toBe(['claim', 'story']);

    $format = FoodAlchemistFormat::find($bestehend->id);
    expect($format->name)->toBe('CHEFS.CORNER')
        ->and($format->consumer_name)->toBe('Chefs Corner')
        ->and($format->claim)->toBe('World on a Plate')
        ->and($format->story)->toBe('Köche im Rampenlicht.');
});
