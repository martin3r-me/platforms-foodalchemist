<?php

use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Speisekarte Stufe E — KI-Wording (Brand-Voice) über den Core-Contract. Zwei-stufig:
 * Vorschlag → Vorschau; schreibt nichts, D1-Owner-Guard greift.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->karten = app(SpeisekarteService::class);
    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'ke1', 'name' => 'Rinderfilet', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 28.00,
    ]);
});

if (! function_exists('bindSpeisekarteKiStub')) {
    function bindSpeisekarteKiStub(?string $text): void
    {
        config(['foodalchemist.ai.provider' => 'core']);
        app()->bind(LLMProviderContract::class, fn () => new class($text) implements LLMProviderContract
        {
            public function __construct(private ?string $text) {}

            public function getName(): string { return 'test-stub'; }

            public function chat(array $messages, array $options = []): array
            {
                $GLOBALS['sk_ki_prompt'] = collect($messages)->where('role', 'user')->last()['content'] ?? '';

                return ['content' => json_encode(['werte' => ['text' => $this->text], 'confidence' => 0.8, 'reasoning' => 'stub']),
                    'usage' => [], 'model' => 'stub', 'tool_calls' => null];
            }

            public function streamChat(array $messages, callable $onDelta, array $options = []): void { $onDelta($this->chat($messages, $options)['content']); }

            public function getAvailableModels(): array { return ['stub']; }

            public function getDefaultModel(): string { return 'stub'; }

            public function isAvailable(): bool { return true; }
        });
    }
}

it('Stufe E: KI-Wording schlägt vor, schreibt aber nichts an die Position', function () {
    bindSpeisekarteKiStub('Dry Aged Rinderfilet vom Weiderind');
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $this->karten->update($this->rootTeam, $karte->id, ['default_niveau' => 'gehoben']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge']);
    $pos = $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id]);

    $r = $this->karten->kiWordingVorschlag($this->rootTeam, $pos->id);
    expect($r['text'])->toContain('Rinderfilet')
        ->and($r['confidence'])->toBe(0.8)
        ->and($pos->refresh()->wording)->toBeNull();          // nichts geschrieben

    // Der Kontext trägt das Roh-Gericht + die Leitplanken.
    expect($GLOBALS['sk_ki_prompt'] ?? '')->toContain('Rinderfilet')->toContain('gehoben');
});

it('Stufe E: leere KI-Antwort wirft', function () {
    bindSpeisekarteKiStub('   ');
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id);
    $pos = $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id]);

    expect(fn () => $this->karten->kiWordingVorschlag($this->rootTeam, $pos->id))
        ->toThrow(RuntimeException::class, 'keinen Text');
});

it('Stufe E: geerbte Karte — KI-Vorschlag nur durchs Besitzer-Team (D1)', function () {
    bindSpeisekarteKiStub('Text.');
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id);
    $pos = $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id]);

    expect(fn () => $this->karten->kiWordingVorschlag($this->childA, $pos->id))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
});

it('Stufe E: KI-Kartentext nutzt die Gericht-Namen im Kontext', function () {
    bindSpeisekarteKiStub('Willkommen — von Klassik bis Kreativ.');
    $karte = $this->karten->create($this->rootTeam, ['name' => 'Abendkarte']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id);
    $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id]);

    $r = $this->karten->kiKartenText($this->rootTeam, $karte->id);
    expect($r['text'])->toContain('Willkommen')
        ->and($karte->refresh()->description)->toBeNull();
    expect($GLOBALS['sk_ki_prompt'] ?? '')->toContain('Rinderfilet');
});

it('A/Bug-Fix: der Schreibstil-Sprach-Duktus der Karte landet im KI-Prompt', function () {
    bindSpeisekarteKiStub('Zartes Rinderfilet.');
    $stil = \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'sk-duktus', 'name' => 'Edel',
        'sprach_duktus' => 'SK-DUKTUS-MARKER: gehoben, französische Loanwords.',
    ]);
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $this->karten->update($this->rootTeam, $karte->id, ['writing_style_id' => $stil->id]);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge']);
    $pos = $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id]);

    $this->karten->kiWordingVorschlag($this->rootTeam, $pos->id);
    expect($GLOBALS['sk_ki_prompt'] ?? '')->toContain('SK-DUKTUS-MARKER');   // war der Bug: Stil ging nie mit
});

it('A: speisekarteWordingRegenerieren betextet alle Positionen im Stil + schreibt sie', function () {
    bindSpeisekarteKiStub('Edles Rinderfilet, sous-vide.');
    $stil = \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'sk-duktus2', 'name' => 'Edel',
        'sprach_duktus' => 'gehoben.',
    ]);
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $this->karten->update($this->rootTeam, $karte->id, ['writing_style_id' => $stil->id]);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge']);
    $pos = $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id]);

    $n = $this->karten->speisekarteWordingRegenerieren($this->rootTeam, $karte->id);
    expect($n)->toBe(1)
        ->and($pos->refresh()->wording)->toBe('Edles Rinderfilet, sous-vide.');   // Bulk schreibt (anders als kiWordingVorschlag)

    // Ohne Stil: nichts betextet, kein Call.
    $karteOhne = $this->karten->create($this->rootTeam, ['name' => 'Ohne']);
    $r2 = $this->karten->addRubrik($this->rootTeam, $karteOhne->id);
    $this->karten->addPosition($this->rootTeam, $r2->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id]);
    expect($this->karten->speisekarteWordingRegenerieren($this->rootTeam, $karteOhne->id))->toBe(0);
});
