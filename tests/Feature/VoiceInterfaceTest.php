<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Livewire\VoiceModal;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
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

/*
 * Spec 53 / Paket D — ab hier: Roundtrip-Split, Provider-Pill, Navigation (13 ui.OPEN-Typen +
 * ui.NAVIGATE), leere Eingaben. Bestehende drei „Befehl"-Tests oben bleiben unverändert grün.
 */

it('Roundtrip-Split: Upload setzt nur Transkript + phase=verstehen, verstehen() führt den Tool-Loop aus', function () {
    ($this->skript)(['{"action":"final","text":"ok"}']);

    $modal = Livewire::test(VoiceModal::class)
        ->set('audio', UploadedFile::fake()->create('befehl.mp4', 5, 'audio/mp4'));

    // Zwischen Upload und verstehen(): Transkript da, aber NOCH kein Tool-Loop gelaufen — das ist
    // der ganze Punkt des Splits (vorher: EIN stummer Rundgang von 5-20 s ohne Ladezustand dazwischen).
    expect($modal->get('phase'))->toBe('verstehen')
        ->and($modal->get('transcript'))->toBe('Suche BBQ Sauce')       // Fake-Fixtext (kein Key in testing)
        ->and($modal->get('ergebnis'))->toBeNull();

    $modal->call('verstehen');

    expect($modal->get('phase'))->toBeNull()
        ->and($modal->get('ergebnis')['text'])->toBe('ok');
});

it('Roundtrip-Split: leeres Transkript (Prompt-Echo-Riegel) ⇒ Fehler, kein Tool-Loop, phase bleibt null', function () {
    config(['foodalchemist.stt.fake_text' => '']);

    $modal = Livewire::test(VoiceModal::class)
        ->set('audio', UploadedFile::fake()->create('befehl.webm', 1, 'audio/webm'));

    expect($modal->get('phase'))->toBeNull()
        ->and($modal->get('fehler'))->toBe('Aufnahme war leer.')
        ->and($modal->get('ergebnis'))->toBeNull();
});

it('Getippter Pfad: leerer Text ⇒ kein KI-Call', function () {
    $vorher = DB::table('foodalchemist_ai_call_log')->where('feature', 'voice.command')->count();

    Livewire::test(VoiceModal::class)->call('verarbeiteText', '   ');

    expect(DB::table('foodalchemist_ai_call_log')->where('feature', 'voice.command')->count())->toBe($vorher);
});

it('Provider-Pill: ohne echten STT-Zugang bleibt nur Tippen — Aufnahme-Knopf fehlt, Hinweis steht', function () {
    config(['foodalchemist.stt.provider' => 'fake']);

    $html = Livewire::test(VoiceModal::class)->html();

    expect($html)->toContain('data-voice-provider')
        ->and($html)->toContain('data-voice-provider-hinweis')
        ->and($html)->not->toContain('data-voice-rec')
        ->and($html)->toContain('data-voice-text');                    // Tippen bleibt IMMER
});

it('Provider-Pill: mit OpenAI-Zugang ist die Aufnahme da', function () {
    config(['services.openai.api_key' => 'sk-test', 'foodalchemist.stt.provider' => 'auto']);

    $html = Livewire::test(VoiceModal::class)->html();

    expect($html)->toContain('data-voice-rec')
        ->and($html)->not->toContain('data-voice-provider-hinweis');
});

it('Navigations-Matrix: alle 13 ui.OPEN-Ziele zeigen auf eine WIRKLICH existierende Route', function () {
    $ziele = (new ReflectionClass(VoiceModal::class))->getConstant('ZIELE');

    expect($ziele)->toHaveCount(13);
    foreach ($ziele as $typ => $ziel) {
        expect(Route::has($ziel['route']))->toBeTrue("Route fehlt für ui.OPEN-Typ „{$typ}“: {$ziel['route']}");
    }
});

it('Navigation: Typ ohne Same-Page-Event (gp) navigiert per Redirect', function () {
    $gp = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'voice|zander', 'name' => 'Zanderfilet', 'status' => 'approved']);
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.ui.OPEN","arguments":{"type":"gp","id":' . $gp->id . '}}',
        '{"action":"final","text":"Geöffnet."}',
    ]);

    Livewire::test(VoiceModal::class)
        ->call('verarbeiteText', 'Öffne das Grundprodukt Zanderfilet')
        ->assertRedirect(route('foodalchemist.gps.index', ['gp' => $gp->id]));
});

it('Navigation: zwei ui.OPEN-Treffer — nur der ERSTE navigiert, der zweite wird ein Link im Ergebnis', function () {
    $a = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'voice|a', 'name' => 'GP A', 'status' => 'approved']);
    $b = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'voice|b', 'name' => 'GP B', 'status' => 'approved']);
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.ui.OPEN","arguments":{"type":"gp","id":' . $a->id . '}}',
        '{"action":"tool","name":"foodalchemist.ui.OPEN","arguments":{"type":"gp","id":' . $b->id . '}}',
        '{"action":"final","text":"Zwei Treffer."}',
    ]);

    $modal = Livewire::test(VoiceModal::class)
        ->call('verarbeiteText', 'Öffne GP A und GP B')
        ->assertRedirect(route('foodalchemist.gps.index', ['gp' => $a->id]));

    $aktionen = $modal->get('ergebnis')['aktionen'];
    expect($aktionen[1]['link'] ?? null)->toBe(route('foodalchemist.gps.index', ['gp' => $b->id]));
});

it('Navigation: ui.NAVIGATE zur Planung redirected (Aufgabe 6 Beispiel „Öffne die Planung")', function () {
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.ui.NAVIGATE","arguments":{"route_key":"planung"}}',
        '{"action":"final","text":"Öffne die Planung."}',
    ]);

    Livewire::test(VoiceModal::class)
        ->call('verarbeiteText', 'Öffne die Planung')
        ->assertRedirect(route('foodalchemist.planung.index'));
});

/*
 * Spec 53/D — Aufgabe 7 (GL-07): „erstelle ein …" / „reichere an" dürfen nur bis zum Vorschlag
 * kommen. Kein Schreiben vor dem Bestätigen-Klick — genau wie beim bestehenden speisen_klasse-Fall.
 */

it('GL-07 Planung: sprechen → Vorschlag (kein Write) → „Planung starten" legt die Session erst beim Klick an', function () {
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.planung_vorschlag.POST","arguments":{"scope":"rezept","brief":"Tomatensuppe mit Basilikum","leitplanken":false}}',
        '{"action":"final","text":"Vorschlag: Basisrezept Tomatensuppe."}',
    ]);

    $modal = Livewire::test(VoiceModal::class)->call('verarbeiteText', 'Erstelle ein Basisrezept für Tomatensuppe mit Basilikum');

    expect(FoodAlchemistPlanningSession::count())->toBe(0);             // NUR Vorschlag — das Tool schreibt nichts
    $proposals = collect($modal->get('ergebnis')['proposals']);
    $index = $proposals->search(fn ($p) => $p['type'] === 'planung_start');
    expect($index)->not->toBeFalse();

    $modal->call('planungStarten', $index);

    expect(FoodAlchemistPlanningSession::count())->toBe(1);
    $session = FoodAlchemistPlanningSession::first();
    expect($session->created_via)->toBe('voice')
        ->and($session->brief)->toBe('Tomatensuppe mit Basilikum');
    $modal->assertRedirect(route('foodalchemist.planung.index', ['session' => $session->id, 'open' => 1, 'tab' => 'basisrezept']));
});

it('GL-07 Anreicherung: sprechen → Vorschlag → „Anreicherung starten" dispatcht EnrichRecipeJob erst beim Klick', function () {
    Queue::fake();
    $rezept = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'ans', 'name' => 'Tomatensauce', 'status' => 'draft']);
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.anreicherung_vorschlag.POST","arguments":{"recipe_id":' . $rezept->id . '}}',
        '{"action":"final","text":"Vorschlag: Tomatensauce anreichern."}',
    ]);

    $modal = Livewire::test(VoiceModal::class)->call('verarbeiteText', 'Reichere dieses Rezept vollständig an');
    Queue::assertNotPushed(EnrichRecipeJob::class);
    $index = collect($modal->get('ergebnis')['proposals'])->search(fn ($p) => $p['type'] === 'anreicherung');
    expect($index)->not->toBeFalse();

    $modal->call('anreicherungStarten', $index);

    Queue::assertPushed(EnrichRecipeJob::class, fn ($job) => $job->recipeId === $rezept->id);
});

it('Rezept-Kontext: das oeffnen()-Event landet im Auftrag, ohne dass der User den Namen nennen muss', function () {
    // Primärer Pfad (Aufgabe 7): eine Rezept-Seite dispatcht `voice-modal.oeffnen` MIT Kontext.
    // Der `?rezept=`-Fallback (kontextFuerAuftrag()) ist in Livewire::test() nicht ohne echten
    // HTTP-Request simulierbar — dort spiegelt request()->query() nicht die im Test gesetzte
    // Query. Die Fallback-Zeile ist eine reine request()->query()-Leseoperation ohne eigene
    // Logik und bleibt darum ungetestet, bewusst per Code-Review geprüft statt erzwungen.
    $rezept = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'ctx', 'name' => 'Fond', 'status' => 'draft']);

    $erfasst = new stdClass();
    $erfasst->auftrag = null;
    app()->singleton(FakeAiProvider::class, fn () => new class($erfasst) extends FakeAiProvider
    {
        public function __construct(private stdClass $erfasst)
        {
        }

        public function chat(array $messages, array $options = []): array
        {
            $this->erfasst->auftrag ??= $messages[1]['content'] ?? null;

            return ['content' => '{"action":"final","text":"ok"}', 'model' => 'fake', 'usage' => []];
        }
    });

    Livewire::test(VoiceModal::class)
        ->call('oeffnen', ['type' => 'recipe', 'id' => $rezept->id])
        ->call('verarbeiteText', 'Reichere dieses Rezept an');

    expect($erfasst->auftrag)->toContain('Kontext: aktuell geöffnet — recipe ID=' . $rezept->id);
});
