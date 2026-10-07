<?php

use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Livewire\Speiseplan\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 2026-10-07: KI-Wording im Speiseplan (wie Speisekarte). Schreibstil am Plan, Name je Eintrag,
 * „Plan neu betexten" = ein KI-Aufruf je Gericht, der Text geht an alle Einträge des Gerichts.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(SpeiseplanService::class);

    $this->kiStub = function (?string $text): void {
        $GLOBALS['sp_ki_calls'] = 0;
        config(['foodalchemist.ai.provider' => 'core']);
        app()->bind(LLMProviderContract::class, fn () => new class($text) implements LLMProviderContract
        {
            public function __construct(private ?string $text) {}

            public function getName(): string { return 'test-stub'; }

            public function chat(array $messages, array $options = []): array
            {
                $GLOBALS['sp_ki_calls']++;
                $GLOBALS['sp_ki_prompt'] = collect($messages)->where('role', 'user')->last()['content'] ?? '';

                return ['content' => json_encode(['werte' => ['text' => $this->text], 'confidence' => 0.8, 'reasoning' => 'stub']),
                    'usage' => [], 'model' => 'stub', 'tool_calls' => null];
            }

            public function streamChat(array $messages, callable $onDelta, array $options = []): void { $onDelta($this->chat($messages, $options)['content']); }

            public function getAvailableModels(): array { return ['stub']; }

            public function getDefaultModel(): string { return 'stub'; }

            public function isAvailable(): bool { return true; }
        });
    };

    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'waffel', 'name' => '[DES] Butterwaffeln | Milchreis | Kirschgrütze',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 5.60,
    ]);
    $this->stil = FoodAlchemistWritingStyle::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'bodenstaendig', 'name' => 'Bodenständig', 'sprach_duktus' => 'Kurz, herzlich, ohne Fachbegriffe.',
    ]);
    $this->plan = $this->svc->create($this->rootTeam, ['name' => 'Kantine KW 41', 'start_date' => '2026-10-05']);
});

it('eigenes Wording geht vor das Wording des Gerichts, leer fällt zurück', function () {
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => '2026-10-05', 'sales_recipe_id' => $this->gericht->id]);
    expect($this->svc->eintragName($e->fresh()))->toBe('Butterwaffeln | Milchreis | Kirschgrütze');

    $this->svc->setEintragWording($this->rootTeam, $e->id, '  Belgische Waffeln | Milchreis  ');
    expect($this->svc->eintragName($e->fresh()))->toBe('Belgische Waffeln | Milchreis');

    $this->svc->setEintragWording($this->rootTeam, $e->id, '');
    expect($e->fresh()->wording)->toBeNull();
});

it('Schreibstil am Plan wird gespeichert und geprüft', function () {
    $this->svc->update($this->rootTeam, $this->plan->id, ['writing_style_id' => $this->stil->id]);
    expect((int) $this->plan->fresh()->writing_style_id)->toBe($this->stil->id);

    expect(fn () => $this->svc->update($this->rootTeam, $this->plan->id, ['writing_style_id' => 999999]))
        ->toThrow(\RuntimeException::class, 'Schreibstil');
});

it('KI-Vorschlag trägt Gericht und Schreibstil, schreibt aber nichts', function () {
    ($this->kiStub)('Goldene Waffeln mit Milchreis');
    $this->svc->update($this->rootTeam, $this->plan->id, ['writing_style_id' => $this->stil->id]);
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => '2026-10-05', 'sales_recipe_id' => $this->gericht->id]);

    $r = $this->svc->kiWordingVorschlag($this->rootTeam, $e->id);
    expect($r['text'])->toBe('Goldene Waffeln mit Milchreis')
        ->and($e->fresh()->wording)->toBeNull()
        ->and($GLOBALS['sp_ki_prompt'])->toContain('Butterwaffeln')->toContain('Kurz, herzlich');
});

it('Plan neu betexten: ein KI-Aufruf je Gericht, Text an alle Einträge, Concept/Paket bleiben', function () {
    ($this->kiStub)('Goldene Waffeln');
    $this->svc->update($this->rootTeam, $this->plan->id, ['writing_style_id' => $this->stil->id]);
    foreach (['2026-10-05', '2026-10-07', '2026-10-09'] as $d) {
        $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $d, 'sales_recipe_id' => $this->gericht->id]);
    }

    $res = $this->svc->planWordingRegenerieren($this->rootTeam, $this->plan->id);
    expect($res)->toBe(['gerichte' => 1, 'eintraege' => 3, 'fehler' => 0])
        ->and($GLOBALS['sp_ki_calls'])->toBe(1)
        ->and($this->plan->entries()->pluck('wording')->unique()->values()->all())->toBe(['Goldene Waffeln']);
});

it('ohne Schreibstil kein Lauf und kein KI-Aufruf', function () {
    ($this->kiStub)('egal');
    $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => '2026-10-05', 'sales_recipe_id' => $this->gericht->id]);

    expect(fn () => $this->svc->planWordingRegenerieren($this->rootTeam, $this->plan->id))->toThrow(\RuntimeException::class, 'Schreibstil')
        ->and($GLOBALS['sp_ki_calls'])->toBe(0);
});

it('Kopieren übernimmt das Wording, Ersetzen setzt es zurück', function () {
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => '2026-10-05', 'sales_recipe_id' => $this->gericht->id]);
    $this->svc->setEintragWording($this->rootTeam, $e->id, 'Goldene Waffeln');

    $this->svc->kopiereEintrag($this->rootTeam, $e->id, ['2026-10-06']);
    expect($this->plan->entries()->whereDate('entry_date', '2026-10-06')->first()->wording)->toBe('Goldene Waffeln');

    $anderes = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'spinat', 'name' => 'Rahmspinat', 'status' => 'approved', 'is_sales_recipe' => true,
    ]);
    $this->svc->ersetzeEintrag($this->rootTeam, $e->id, ['sales_recipe_id' => $anderes->id]);
    expect($e->fresh()->wording)->toBeNull();
});

it('UI: Stammdaten zeigen Schreibstil + Plan neu betexten, Detail den Namen im Plan', function () {
    ($this->kiStub)('Goldene Waffeln');
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => '2026-10-05', 'sales_recipe_id' => $this->gericht->id]);

    Livewire::test(Editor::class)
        ->call('oeffnenBearbeiten', $this->plan->id)
        ->assertSeeHtml('data-sp-wording-generieren')
        ->assertSee('Bodenständig')
        ->set('form.writing_style_id', $this->stil->id)
        ->call('planWordingGenerieren')
        ->assertSet('wordingHinweis', '1 Gericht(e) in 1 Einträgen neu betextet.')
        ->call('eintragOeffnen', $e->id)
        ->assertSet('detailWording', 'Goldene Waffeln')
        ->assertSeeHtml('data-sp-eintrag-wording')
        ->set('detailWording', 'Waffeln wie bei Oma')
        ->call('eintragWordingSpeichern');

    expect($e->fresh()->wording)->toBe('Waffeln wie bei Oma');
});

it('MCP: PUT setzt wording und writing_style_id, GET liefert wording', function () {
    $user = $this->makeUser($this->rootTeam, 'MCP');
    $ctx = new \Platform\Core\Contracts\ToolContext($user, $this->rootTeam);
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => '2026-10-05', 'sales_recipe_id' => $this->gericht->id]);

    $put = app(\Platform\FoodAlchemist\Tools\SpeiseplanEintraegePutTool::class)->execute(['eintrag_id' => $e->id, 'wording' => 'Goldene Waffeln'], $ctx);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''))
        ->and($put->data['geaendert'])->toBe(['wording'])
        ->and($put->data['eintrag']['wording'])->toBe('Goldene Waffeln');

    $plan = app(\Platform\FoodAlchemist\Tools\SpeiseplaenePutTool::class)->execute(['id' => $this->plan->id, 'felder' => ['writing_style_id' => $this->stil->id]], $ctx);
    expect($plan->success)->toBeTrue('plan: ' . ($plan->error ?? ''))
        ->and((int) $this->plan->fresh()->writing_style_id)->toBe($this->stil->id);

    $get = app(\Platform\FoodAlchemist\Tools\SpeiseplanEintraegeGetTool::class)->execute(['plan_id' => $this->plan->id], $ctx);
    expect(collect($get->data['eintraege'])->firstWhere('id', $e->id)['wording'])->toBe('Goldene Waffeln');
});
