<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\BriefingLeitplankenService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\RecipeGenerationContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 80 Teil A — Suchbegriffe als Zwischenschritt. Anlass demo Lauf #79: Wissen, Bestand und Pairing
 * suchten mit den ersten 16 Briefing-Wörtern (Füllwörter), die Basis Petersilienwurzel kam nie vor.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);

    $this->stub = function (array $werte) {
        app()->bind(FakeAiProvider::class, fn () => new class($werte) extends FakeAiProvider
        {
            public function __construct(private array $werte)
            {
            }

            public function chat(array $messages, array $options = []): array
            {
                return [
                    'content' => json_encode(['werte' => $this->werte, 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE),
                    'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                    'model' => 'fake-brief',
                    'tool_calls' => null,
                ];
            }
        });
    };

    $this->petersilie = [
        'leitplanken' => ['level' => 'haute_cuisine'],
        'suchbegriffe' => [
            'zutaten' => ['Petersilienwurzel', 'glatte Petersilie'],
            'komponenten' => ['Püree', 'Blattgrün-Matte'],
            'techniken' => ['grün halten'],
            'aromen' => [],
        ],
    ];
});

it('normalisiert die KI-Suchbegriffe: Gruppen, Mengen raus, Dubletten einmal', function () {
    $liste = BriefingLeitplankenService::suchbegriffeNormalisieren([
        'zutaten' => ['Petersilienwurzel', 'petersilienwurzel', '80g', '2 kg', 'ab', 42],
        'komponenten' => ['Püree'],
        'erfunden' => ['Gala'],
        'aromen' => 'frisch-grün',          // einzelner Text statt Liste: als ein Begriff übernehmen
    ]);

    expect($liste)->toBe([
        ['t' => 'Petersilienwurzel', 'g' => 'zutaten', 'q' => 'ki'],
        ['t' => 'Püree', 'g' => 'komponenten', 'q' => 'ki'],
        ['t' => 'frisch-grün', 'g' => 'aromen', 'q' => 'ki'],
    ])->and(BriefingLeitplankenService::suchbegriffeNormalisieren(null))->toBe([]);
});

it('Leitplanken füllen die Chips; eigene Begriffe überleben ein erneutes Ableiten', function () {
    ($this->stub)($this->petersilie);

    $c = Livewire::test(PlanungIndex::class)
        ->set('eingabe.rezept.brief', 'Petersilienpüree, grün, mit Petersilienmatte eingefärbt')
        ->call('leitplankenAusBriefing', 'rezept');

    expect(array_column($c->get('eingabe.rezept.suchbegriffe'), 't'))
        ->toBe(['Petersilienwurzel', 'glatte Petersilie', 'Püree', 'Blattgrün-Matte', 'grün halten']);

    // Mensch ergänzt, entfernt einen KI-Chip, leitet neu ab: Mensch-Chip bleibt, KI-Satz kommt neu.
    $c->set('eingabe.rezept.suchbegriff_neu', 'Butter, Petersilienwurzel')
        ->call('suchbegriffHinzufuegen', 'rezept')
        ->call('suchbegriffEntfernen', 'rezept', 4);
    $nachHand = $c->get('eingabe.rezept.suchbegriffe');
    expect(array_column($nachHand, 't'))->toContain('Butter')->not->toContain('grün halten')
        ->and(collect($nachHand)->where('t', 'Butter')->first()['q'])->toBe('mensch');

    ($this->stub)(['leitplanken' => ['level' => 'gehoben'], 'suchbegriffe' => ['zutaten' => ['Sellerie', 'Butter']]]);
    $c->call('leitplankenAusBriefing', 'rezept');
    $neu = $c->get('eingabe.rezept.suchbegriffe');
    expect(array_column($neu, 't'))->toBe(['Butter', 'Sellerie'])
        ->and($neu[0]['q'])->toBe('mensch');
});

it('Go: Suchbegriffe gehen geordnet in den Lauf und an die Sitzung, nicht in die Fan-out-Regler', function () {
    Queue::fake();
    ($this->stub)($this->petersilie);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Petersilie']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.rezept.brief', 'Petersilienpüree, grün')
        ->set('eingabe.rezept.suchbegriffe', [
            ['t' => 'grün halten', 'g' => 'techniken', 'q' => 'ki'],
            ['t' => 'Petersilienwurzel', 'g' => 'zutaten', 'q' => 'mensch'],
        ])
        ->call('goKaskade', 'rezept');

    \Platform\FoodAlchemist\Tests\Support\KomponentenPlanDurch::bauen();
    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => ($job->parameter['suchbegriffe'] ?? null) === ['Petersilienwurzel', 'grün halten']);
    $session->refresh();
    expect(array_column($session->suchbegriffe['rezept'] ?? [], 't'))->toBe(['grün halten', 'Petersilienwurzel'])
        ->and($session->generation_params ?? [])->not->toHaveKey('suchbegriffe');
});

it('Go ohne Chips leitet die Suchbegriffe einmal nach (Regler bleiben unangetastet)', function () {
    Queue::fake();
    ($this->stub)(['leitplanken' => ['level' => 'klassisch'], 'suchbegriffe' => ['zutaten' => ['Petersilienwurzel']]]);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Petersilie']);

    $c = Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.rezept.brief', 'Petersilienpüree, grün')
        ->call('goKaskade', 'rezept');

    \Platform\FoodAlchemist\Tests\Support\KomponentenPlanDurch::bauen();
    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => ($job->parameter['suchbegriffe'] ?? null) === ['Petersilienwurzel']);
    expect($c->get('regler.rezept.level'))->toBe(PlanungIndex::REGLER_DEFAULT['level']);
});

it('Generator-Kontext: Suchbegriffe schlagen 16 Füllwörter, Pairing-Anker wird gefunden und protokolliert', function () {
    DB::table('foodalchemist_vocab_pairing_anchors')->insert([
        'uuid' => (string) UuidV7::generate(), 'slug' => 'petersilienwurzel', 'display_de' => 'Petersilienwurzel',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // Das Briefing von Lauf #79 sinngemäß: lang, die Basis kommt darin nicht vor.
    $brief = 'Ich brauche ein Rezept für eine Petersilienpüree, was grün ist, was mit einer Petersilienmatte '
        . 'eingefärbt wird. ausgelegt für 100 pax, ist gedacht für à la carte, keine Diät Vorgaben, 80g pro Person';

    $ohne = app(RecipeGenerationContextService::class)->build($this->rootTeam, $brief, [], false);
    $mit = app(RecipeGenerationContextService::class)->build($this->rootTeam, $brief,
        ['suchbegriffe' => ['Petersilienwurzel', 'Blattgrün-Matte']], false);

    expect($ohne['prompt'])->not->toHaveKey('kombinationsplan')
        ->and($mit['prompt'])->toHaveKey('kombinationsplan')
        ->and($mit['snapshot']['pairing_keys'])->toContain('kombinationsplan')
        ->and($mit['snapshot']['suchbegriffe'])->toBe(['Petersilienwurzel', 'Blattgrün-Matte'])
        ->and($mit['snapshot']['pairing']['anker'])->toBe(['Petersilienwurzel'])
        ->and($mit['snapshot']['pairing']['ohne_anker'])->toBe(['Blattgrün-Matte']);
});

it('Generator-Kontext ohne Suchbegriffe bleibt beim Alt-Verhalten', function () {
    $ctx = app(RecipeGenerationContextService::class)->build($this->rootTeam, 'Rotwein-Schalotten-Reduktion', [], false);

    expect($ctx['snapshot']['suchbegriffe'])->toBe([])
        ->and($ctx['snapshot']['pairing'])->toBe(['anker' => [], 'ohne_anker' => []]);
});
