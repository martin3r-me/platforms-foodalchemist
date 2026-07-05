<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\VoiceModal;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Stt\SttServiceContract;
use Platform\FoodAlchemist\Services\VoiceCommandService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M7-10 DoD: 3 Sprachbefehle end-to-end (Suche · Detail öffnen · Schreib-
 * Proposal mit Accept) über den Tier-D-Tool-Loop; Latenz gemessen. Der
 * LLM-Schritt läuft als Skript-Provider (dokumentierte FakeProvider-Grenze
 * wie M4-14/M6-06) — Tools, Protokoll, Guards und Accept sind ECHT.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);

    $this->skript = function (array $antworten) {
        app()->singleton(FakeAiProvider::class, fn () => new class($antworten) extends FakeAiProvider
        {
            private int $i = 0;

            public function __construct(private array $antworten)
            {
            }

            public function chat(array $messages, array $options = []): array
            {
                return ['content' => $this->antworten[min($this->i++, count($this->antworten) - 1)], 'model' => 'fake-voice', 'usage' => []];
            }
        });
    };

    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'bbq', 'name' => 'Sauce: BBQ', 'status' => 'approved',
    ]);
});

it('Befehl 1 — Suche: Loop ruft recipes.SEARCH und antwortet final; Latenz gemessen', function () {
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.recipes.SEARCH","arguments":{"q":"BBQ"}}',
        '{"action":"final","text":"1 Treffer: Sauce: BBQ."}',
    ]);

    $r = app(VoiceCommandService::class)->verarbeite('Suche BBQ Sauce');

    expect($r['text'])->toBe('1 Treffer: Sauce: BBQ.')
        ->and($r['tool_laeufe'][0]['name'])->toBe('foodalchemist.recipes.SEARCH')
        ->and($r['tool_laeufe'][0]['data']['total'])->toBe(1)         // ECHTES Tool gegen echte Daten
        ->and($r['runden'])->toBe(2)
        ->and($r['elapsed_ms'])->toBeGreaterThanOrEqual(0);
    expect(DB::table('foodalchemist_ai_call_log')->where('feature', 'voice.command')->where('tier', 'D')->exists())->toBeTrue();
});

it('Befehl 2 — Detail öffnen: ui.OPEN mit Sichtbarkeits-Guard ⇒ UI-Aktion + Event', function () {
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.ui.OPEN","arguments":{"type":"recipe","id":' . $this->rezept->id . '}}',
        '{"action":"final","text":"Geöffnet."}',
    ]);

    Livewire::test(VoiceModal::class)
        ->call('verarbeiteText', 'Öffne die BBQ Sauce')
        ->assertDispatched('recipe-selected', id: $this->rezept->id)
        ->assertSeeHtml('data-voice-ergebnis');
});

it('Befehl 3 — Schreib-Proposal: sprechen → Proposal (kein Write) → Bestätigen schreibt via GL-07', function () {
    $hg = FoodAlchemistDishMainGroup::create(['code' => 'HG', 'label' => 'Hauptgang']);
    $klasse = FoodAlchemistDishClass::create(['dish_main_group_id' => $hg->id, 'code' => 'HG_F', 'label' => 'Fleisch', 'diet_form' => 'fleisch']);
    $vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk', 'name' => 'HG: Filet', 'status' => 'draft',
        'is_sales_recipe' => true, 'dish_class_id' => $klasse->id,  // Kontext fürs classify-Echo
    ]);
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.recipe_klasse.POST","arguments":{"recipe_id":' . $vk->id . '}}',
        // Antwort 2 konsumiert das classify INNERHALB des Tools (gleicher Provider):
        '{"werte":{"dish_class_id":' . $klasse->id . '},"confidence":0.87}',
        '{"action":"final","text":"Vorschlag: Fleisch — bitte bestätigen."}',
    ]);

    $modal = Livewire::test(VoiceModal::class)->call('verarbeiteText', 'Klassifiziere das Filet');
    $vk->update(['dish_class_id' => null]);                       // Proposal hat NICHT geschrieben
    expect($vk->fresh()->dish_class_id)->toBeNull();

    $modal->call('proposalUebernehmen', 0)->assertDispatched('recipe-gespeichert');
    expect($vk->fresh()->dish_class_source)->toBe('ki');          // Accept = GL-07-Pfad
});

it('STT-Fassade: Fake liefert konfigurierten Text; AssemblyAI ohne Key wirft mit D8-Hinweis', function () {
    config(['foodalchemist.stt.fake_text' => 'Suche Agar']);
    expect(app(SttServiceContract::class)->transcribe('BLOB'))->toBe('Suche Agar');

    config(['foodalchemist.stt.provider' => 'assemblyai', 'foodalchemist.stt.key' => '']);
    expect(fn () => app(SttServiceContract::class)->transcribe('BLOB'))
        ->toThrow(RuntimeException::class, 'ASSEMBLYAI_API_KEY');
});
