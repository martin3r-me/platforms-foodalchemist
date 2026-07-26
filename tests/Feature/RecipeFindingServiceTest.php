<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeFinding;
use Platform\FoodAlchemist\Services\RecipeFindingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 · S5a (Tranche B) — die Ablage der Copilot-Befunde.
 *
 * Drei Dinge sind zu beweisen, weil an ihnen die Signal-Hälfte (S5b) hängt:
 *  1. **Idempotenz.** Zwei Läufe über dasselbe Rezept ergeben keine zweite Zeile —
 *     sonst wächst die Inbox mit jedem Lauf statt mit jedem Befund.
 *  2. **Eine Ablehnung hält**, auch wenn das Modell im Folgelauf einen anderen
 *     Wert für denselben Sachverhalt vorschlägt (Fingerprint ohne Wert).
 *  3. **Die Arbeitsmenge ist change-driven** — ein geprüftes, unverändertes Rezept
 *     kostet keinen zweiten Provider-Call.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->g = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ]);

    $this->recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 's5a-basis', 'name' => 'Kartoffelpüree',
        'status' => 'approved', 'preparation' => 'Kartoffeln kochen, stampfen, Butter einrühren.',
    ]);

    $gp = $this->makeGp($this->rootTeam, 'Kartoffel: frisch');
    $this->zeileId = DB::table('foodalchemist_recipe_ingredients')->insertGetId([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->recipe->id, 'gp_id' => $gp->id, 'raw_text' => 'Kartoffel', 'display_name' => 'Kartoffel',
        'quantity' => 1000, 'unit_vocab_id' => $this->g->id, 'position' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

/** Provider-Stub: liefert genau die übergebene Befund-Liste. */
function bindBatchStub(array $befunde): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($befunde) implements LLMProviderContract
    {
        public function __construct(private array $befunde) {}

        public function getName(): string
        {
            return 'test-stub';
        }

        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => ['befunde' => $this->befunde, 'gesamturteil' => 'stub'],
                'confidence' => 0.8, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void
        {
            $onDelta($this->chat($messages, $options)['content']);
        }

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

it('S5a: legt Befunde ab und zählt beim zweiten Lauf nur hoch (Idempotenz)', function () {
    bindBatchStub([
        ['art' => 'menge', 'zutat_id' => $this->zeileId, 'quantity' => 1200, 'begruendung' => 'Zu wenig für 4 Portionen.', 'konfidenz' => 0.85],
        ['art' => 'hinweis', 'begruendung' => 'Salz wird im Text nicht dosiert.', 'konfidenz' => 0.6],
    ]);

    $svc = app(RecipeFindingService::class);
    $erst = $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id);

    expect($erst['neu'])->toBe(2)->and($erst['offen'])->toBe(2)
        ->and(FoodAlchemistRecipeFinding::count())->toBe(2);

    $zweit = $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id);

    expect($zweit['neu'])->toBe(0)->and($zweit['offen'])->toBe(2)
        ->and($zweit['verschwunden'])->toBe(0)
        ->and(FoodAlchemistRecipeFinding::count())->toBe(2);          // KEINE zweite Zeile

    $menge = FoodAlchemistRecipeFinding::where('kind', 'menge')->first();
    expect($menge->seen_count)->toBe(2)
        ->and($menge->auto_applicable)->toBeTrue()
        ->and($menge->confidence)->toBe(0.85)
        ->and($menge->first_seen_at)->not->toBeNull();
});

it('S5a: derselbe Sachverhalt mit anderem Wert bleibt dieselbe Zeile — eine Ablehnung hält', function () {
    $svc = app(RecipeFindingService::class);

    bindBatchStub([['art' => 'menge', 'zutat_id' => $this->zeileId, 'quantity' => 1200, 'begruendung' => 'Zu wenig.', 'konfidenz' => 0.8]]);
    $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id);

    $zeile = FoodAlchemistRecipeFinding::first();
    $svc->entscheide($this->rootTeam, $zeile->id, 'verworfen');

    // Folgelauf: gleicher Befund, anderer Vorschlagswert.
    bindBatchStub([['art' => 'menge', 'zutat_id' => $this->zeileId, 'quantity' => 1250, 'begruendung' => 'Immer noch zu wenig.', 'konfidenz' => 0.9]]);
    $z = $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id);

    expect(FoodAlchemistRecipeFinding::count())->toBe(1)             // Fingerprint kennt den Wert nicht
        ->and($z['neu'])->toBe(0)->and($z['offen'])->toBe(0);        // kein Signal-Kandidat mehr

    $zeile->refresh();
    expect($zeile->status)->toBe('verworfen')                        // Ablehnung hält
        ->and($zeile->quantity)->toBe(1250.0)                        // Wert wird trotzdem nachgeführt
        ->and($zeile->seen_count)->toBe(2)
        ->and($svc->offeneUeberSchwelle($this->rootTeam)->count())->toBe(0);
});

it('S5a: ein angewendeter Befund, der zurückkommt, wird wieder offen', function () {
    $svc = app(RecipeFindingService::class);
    $befund = [['art' => 'menge', 'zutat_id' => $this->zeileId, 'quantity' => 1200, 'begruendung' => 'Zu wenig.', 'konfidenz' => 0.8]];

    bindBatchStub($befund);
    $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id);
    $zeile = FoodAlchemistRecipeFinding::first();
    $svc->entscheide($this->rootTeam, $zeile->id, 'uebernommen');

    bindBatchStub($befund);
    $z = $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id);

    expect($z['wieder'])->toBe(1)->and($z['offen'])->toBe(1)
        ->and($zeile->refresh()->status)->toBe('offen')
        ->and($zeile->seen_count)->toBe(2);
});

it('S5a: ein nicht mehr gemeldeter offener Befund wird geschlossen, ein entschiedener nicht', function () {
    $svc = app(RecipeFindingService::class);

    bindBatchStub([
        ['art' => 'menge', 'zutat_id' => $this->zeileId, 'quantity' => 1200, 'begruendung' => 'Zu wenig.', 'konfidenz' => 0.8],
        ['art' => 'hinweis', 'begruendung' => 'Salz fehlt im Text.', 'konfidenz' => 0.75],
    ]);
    $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id);
    $svc->entscheide($this->rootTeam, FoodAlchemistRecipeFinding::where('kind', 'hinweis')->value('id'), 'verworfen');

    bindBatchStub([]);                                               // Modell meldet nichts mehr
    $z = $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id);

    expect($z['verschwunden'])->toBe(1)
        ->and(FoodAlchemistRecipeFinding::where('kind', 'menge')->value('status'))->toBe('verschwunden')
        ->and(FoodAlchemistRecipeFinding::where('kind', 'hinweis')->value('status'))->toBe('verworfen');
});

it('S5a: ein weggeräumter Befund, der wiederkommt, wird reaktiviert statt am Unique-Index zu scheitern', function () {
    $svc = app(RecipeFindingService::class);
    $befund = [['art' => 'menge', 'zutat_id' => $this->zeileId, 'quantity' => 1200, 'begruendung' => 'Zu wenig.', 'konfidenz' => 0.8]];

    bindBatchStub($befund);
    $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id);
    FoodAlchemistRecipeFinding::first()->delete();                   // Soft-Delete, Index greift weiter

    bindBatchStub($befund);
    $z = $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id);

    expect($z['neu'])->toBe(0)
        ->and(FoodAlchemistRecipeFinding::count())->toBe(1)
        ->and(FoodAlchemistRecipeFinding::first()->status)->toBe('offen')
        ->and(FoodAlchemistRecipeFinding::withTrashed()->count())->toBe(1);
});

it('S5a: die Arbeitsmenge ist change-driven und kennt nur produktive Rezepte', function () {
    $svc = app(RecipeFindingService::class);

    $entwurf = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 's5a-draft', 'name' => 'Entwurf', 'status' => 'draft',
    ]);

    expect($svc->arbeitsmenge($this->rootTeam)->pluck('id')->all())->toBe([$this->recipe->id])
        ->and($svc->arbeitsmenge($this->rootTeam)->pluck('id'))->not->toContain($entwurf->id);

    bindBatchStub([]);
    $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id);

    // Geprüft und unverändert ⇒ nicht mehr fällig (der Stempel darf `updated_at` nicht bewegen).
    expect($svc->arbeitsmenge($this->rootTeam)->count())->toBe(0);

    $this->travel(2)->minutes();
    FoodAlchemistRecipe::whereKey($this->recipe->id)->update(['updated_at' => now()]);

    expect($svc->arbeitsmenge($this->rootTeam)->pluck('id')->all())->toBe([$this->recipe->id]);
});

it('S5a: der Batch-Command legt ab, --dry-run nicht', function () {
    bindBatchStub([['art' => 'menge', 'zutat_id' => $this->zeileId, 'quantity' => 1200, 'begruendung' => 'Zu wenig.', 'konfidenz' => 0.8]]);

    $this->artisan('foodalchemist:recipe-findings', ['--team' => $this->rootTeam->id, '--limit' => 5, '--dry-run' => true])
        ->assertExitCode(0);
    expect(FoodAlchemistRecipeFinding::count())->toBe(0);

    $this->artisan('foodalchemist:recipe-findings', ['--team' => $this->rootTeam->id, '--limit' => 5])
        ->assertExitCode(0);

    expect(FoodAlchemistRecipeFinding::count())->toBe(1)
        ->and(DB::table('foodalchemist_bulk_runs')->where('type', 'review')->first())
        ->not->toBeNull();

    $lauf = DB::table('foodalchemist_bulk_runs')->where('type', 'review')->first();
    expect($lauf->status)->toBe('done')->and((int) $lauf->done)->toBe(1)->and((int) $lauf->failed)->toBe(0);
});

it('S5a: nur Befunde über der Konfidenz-Schwelle sind Signal-Kandidaten', function () {
    bindBatchStub([
        ['art' => 'menge', 'zutat_id' => $this->zeileId, 'quantity' => 1200, 'begruendung' => 'Sicher zu wenig.', 'konfidenz' => 0.9],
        ['art' => 'hinweis', 'begruendung' => 'Vielleicht etwas Muskat?', 'konfidenz' => 0.4],
    ]);

    $svc = app(RecipeFindingService::class);
    $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id);

    expect($svc->offeneUeberSchwelle($this->rootTeam)->count())->toBe(1)
        ->and($svc->offeneUeberSchwelle($this->rootTeam)->first()->kind)->toBe('menge');
});
