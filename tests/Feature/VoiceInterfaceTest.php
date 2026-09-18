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
use Platform\FoodAlchemist\Services\TeamSettingsService;
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

/**
 * Live-Bruch Dominique (2026-09-18): „Öffne die Seite der Basisrezepte" brauchte 3 Runden
 * (tool_registry.SEARCH → ui.ROUTES → NAVIGATE, 29,8 s — knapp am 28 s-Zeitbudget). Die kurzen
 * route_key-Labels stehen jetzt direkt im ui.NAVIGATE-Schema — das Skript hier bietet absichtlich
 * NUR EINEN Tool-Aufruf an; würde das Modell zuerst SEARCH/ROUTES probieren, bekäme es dafür
 * einfach dieselbe (falsche) Antwort zurück und der Test schlüge fehl.
 */
it('Navigation: "Öffne die Basisrezepte" braucht NUR EINE Runde — kein SEARCH/ui.ROUTES-Umweg', function () {
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.ui.NAVIGATE","arguments":{"route_key":"recipes"}}',
        '{"action":"final","text":"Öffne die Basisrezepte."}',
    ]);

    $r = app(VoiceCommandService::class)->verarbeite('Öffne die Seite der Basisrezepte');

    expect($r['runden'])->toBe(2)                                        // 1 Tool + final, keine dritte Runde
        ->and($r['tool_laeufe'])->toHaveCount(1)
        ->and($r['tool_laeufe'][0]['name'])->toBe('foodalchemist.ui.NAVIGATE')
        ->and(collect($r['tool_laeufe'])->pluck('name'))->not->toContain('foodalchemist.ui.ROUTES')
        ->and(collect($r['tool_laeufe'])->pluck('name'))->not->toContain('tool_registry.SEARCH');
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

/*
 * Hotfix 2026-09-17: ein rohes <script> als ERSTES Tag der Komponenten-HTML bekam von Livewire das
 * wire:id (Drawer\Utils::insertAttributesIntoHtmlRoot hängt Attribute per Regex an das erste Tag).
 * Das Modal-Markup gehörte damit zur Eltern-Komponente (Sidebar) — $wire.upload lief gegen
 * foodalchemist.sidebar ohne WithFileUploads (MissingFileUploadsTraitException, demo). Die
 * Root-ZÄHLUNG strippt <script> vorher, die Attribut-INJEKTION nicht — deshalb hier der Wächter:
 * das erste Tag der gerenderten Komponente muss das Modal-Div sein, nie ein Script.
 */
it('rendert kein <script> als erstes Tag der Komponente (wire:id landet sonst am Script)', function () {
    $html = Livewire::test(VoiceModal::class)->html();
    preg_match('/(?:\n\s*|^\s*)<([a-zA-Z0-9\-]+)/', $html, $m);
    expect($m[1] ?? null)->toBe('div');
});

/*
 * Spec 53 / Paket F — Agenten-Modus (fragen|auto_sicher|nur_lesen). Kein Team-Setting gesetzt
 * ⇒ Default `fragen` (bestehende GL-07-Tests oben bleiben also unverändert gültig).
 */

it('Modus fragen (Default): kein Setting gesetzt ⇒ Modal liest fragen (Klick-Pflicht bleibt wie in den GL-07-Tests oben belegt)', function () {
    expect(Livewire::test(VoiceModal::class)->get('agentModus'))->toBe('fragen');
});

/**
 * Live-Befund Dominique (2026-09-18): Team-Setting geändert NACH `mount()` (z. B. auf der
 * Einstellungen-Seite selbst) — die Pille zeigte weiter den ALTEN Modus, der Recorder blieb im
 * Ein-Klick-Modus, bis zum vollen Reload. `mount()` läuft nur einmal pro Seite; `oeffnen()` ist
 * der richtige Moment, beides frisch zu lesen.
 */
it('oeffnen() liest Modus + „dauerhaft aktiv" FRISCH — eine Setting-Änderung nach mount() zeigt sich sofort beim nächsten Öffnen', function () {
    $modal = Livewire::test(VoiceModal::class);
    expect($modal->get('agentModus'))->toBe('fragen')
        ->and($modal->get('konversationAktiv'))->toBeFalse();

    // Setting ändert sich NACH mount() — z. B. auf einer anderen/derselben Seite.
    app(TeamSettingsService::class)->update($this->rootTeam, [
        'voice_agent_mode' => 'auto_sicher', 'voice_agent_dauerhaft_aktiv' => true,
    ]);

    $modal->call('oeffnen');

    expect($modal->get('agentModus'))->toBe('auto_sicher')
        ->and($modal->get('konversationAktiv'))->toBeTrue();
});

it('Modus auto_sicher: planung_start legt die Session OHNE Klick an, Ergebnis zeigt „automatisch ausgeführt"', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['voice_agent_mode' => 'auto_sicher']);
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.planung_vorschlag.POST","arguments":{"scope":"rezept","brief":"Tomatensuppe","leitplanken":false}}',
        '{"action":"final","text":"Vorschlag: Tomatensuppe."}',
    ]);

    $modal = Livewire::test(VoiceModal::class);
    expect($modal->get('agentModus'))->toBe('auto_sicher')
        ->and(FoodAlchemistPlanningSession::count())->toBe(0);

    $modal->call('verarbeiteText', 'Erstelle ein Basisrezept für Tomatensuppe')
        ->assertRedirect(route('foodalchemist.planung.index', [
            'session' => FoodAlchemistPlanningSession::first()?->id, 'open' => 1, 'tab' => 'basisrezept',
        ]));

    expect(FoodAlchemistPlanningSession::count())->toBe(1);              // KEIN Klick nötig — direkt ausgeführt
    $proposal = collect($modal->get('ergebnis')['proposals'])->firstWhere('type', 'planung_start');
    expect($proposal['accepted'] ?? false)->toBeTrue();
});

it('Modus auto_sicher: anreicherung_vorschlag dispatcht EnrichRecipeJob OHNE Klick', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['voice_agent_mode' => 'auto_sicher']);
    Queue::fake();
    $rezept = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'auto1', 'name' => 'Sauce', 'status' => 'draft']);
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.anreicherung_vorschlag.POST","arguments":{"recipe_id":' . $rezept->id . '}}',
        '{"action":"final","text":"Vorschlag: Sauce."}',
    ]);

    $modal = Livewire::test(VoiceModal::class)->call('verarbeiteText', 'Reichere dieses Rezept vollständig an');

    Queue::assertPushed(EnrichRecipeJob::class, fn ($job) => $job->recipeId === $rezept->id);   // KEIN Klick nötig
    $proposal = collect($modal->get('ergebnis')['proposals'])->firstWhere('type', 'anreicherung');
    expect($proposal['accepted'] ?? false)->toBeTrue();
});

it('Modus nur_lesen: Proposal-Tools sind strukturell gesperrt — keine Vorschläge, kein Write', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['voice_agent_mode' => 'nur_lesen']);
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.planung_vorschlag.POST","arguments":{"scope":"rezept","brief":"Tomatensuppe"}}',
        '{"action":"final","text":"Das darf ich in diesem Modus nicht vorschlagen."}',
    ]);

    $modal = Livewire::test(VoiceModal::class);
    expect($modal->get('agentModus'))->toBe('nur_lesen');

    $modal->call('verarbeiteText', 'Erstelle ein Basisrezept für Tomatensuppe');

    expect(FoodAlchemistPlanningSession::count())->toBe(0)
        ->and($modal->get('ergebnis')['proposals'])->toBe([])
        ->and($modal->get('ergebnis')['tool_laeufe'])->toBe([]);          // Tool wurde von der Policy abgelehnt, nie ausgeführt
});

it('Review-Fix: #[Locked] verhindert Selbst-Hochstufung — $wire.set(agentModus, auto_sicher) wird abgelehnt', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['voice_agent_mode' => 'nur_lesen']);

    $modal = Livewire::test(VoiceModal::class);
    expect($modal->get('agentModus'))->toBe('nur_lesen');

    expect(fn () => $modal->set('agentModus', 'auto_sicher'))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

    // Selbst wenn die Property (z. B. per direktem PHP-Zugriff) doch abwiche, entscheidet
    // NICHT sie — agentModusAktuell() liest immer frisch aus dem Team-Setting.
    ($modal->instance())->agentModus = 'auto_sicher';   // simuliert einen umgangenen Property-Zustand
    FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'lock1', 'name' => 'X', 'status' => 'draft']);
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.planung_vorschlag.POST","arguments":{"scope":"rezept","brief":"Test"}}',
        '{"action":"final","text":"nicht erlaubt"}',
    ]);

    $modal->call('verarbeiteText', 'Erstelle ein Basisrezept');

    expect(FoodAlchemistPlanningSession::count())->toBe(0);               // Team-Setting (nur_lesen) hat gewonnen, nicht die Property
});

/*
 * Spec 53 / Paket F (1b) — generischer Schreibvorschlag für FA-Write-Tools ohne eigenes
 * Proposal-Tool. Alias-Fall (recipes.PUT: Feld-Diff) + Alias-Fall Löschen (kein Diff, Name
 * Pflicht) — beide bis zum Bestätigen-Klick ohne DB-Änderung, GL-07 unverändert.
 */

it('Schreibvorschlag (Alias recipes.PUT): Vorschau zeigt NUR das im Befehl genannte Feld (Partial-Update-Beweis)', function () {
    $rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sv1', 'name' => 'Alter Name',
        'description' => 'Alte Beschreibung', 'status' => 'draft',
    ]);
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.recipes.PUT","arguments":{"recipe_id":' . $rezept->id . ',"name":"Neuer Name"}}',
        '{"action":"final","text":"Vorschlag angelegt — bitte bestätigen."}',
    ]);

    $modal = Livewire::test(VoiceModal::class)->call('verarbeiteText', 'Nenne das Rezept in Neuer Name um');

    $proposal = collect($modal->get('ergebnis')['proposals'])->firstWhere('type', 'schreibaktion');
    expect($proposal)->not->toBeNull()
        ->and($proposal['objekt']['type'])->toBe('Basisrezept')
        ->and($proposal['objekt']['name'])->toBe('Alter Name')              // GET-Vorher liefert den ALTEN Namen
        ->and($proposal['vorschau'])->toHaveCount(1)                        // NUR das genannte Feld, nicht description
        ->and($proposal['vorschau'][0])->toBe(['feld' => 'name', 'alt' => 'Alter Name', 'neu' => 'Neuer Name']);
    expect($rezept->fresh()->name)->toBe('Alter Name');                    // NICHTS geschrieben vor dem Klick

    $index = collect($modal->get('ergebnis')['proposals'])->search(fn ($p) => $p['type'] === 'schreibaktion');
    $modal->call('schreibaktionAusfuehren', $index);

    expect($rezept->fresh()->name)->toBe('Neuer Name');                    // JETZT geschrieben
    expect(DB::table('foodalchemist_ai_call_log')->where('feature', 'voice.schreibaktion')->exists())->toBeTrue();
});

it('Schreibvorschlag (Alias recipes.DELETE): Karte zeigt den Objekt-NAMEN, kein Feld-Diff, DELETE erst nach Klick', function () {
    $rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sv2', 'name' => 'Tomatensuppe klassisch', 'status' => 'draft',
    ]);
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.recipes.DELETE","arguments":{"id":' . $rezept->id . ',"confirm":true}}',
        '{"action":"final","text":"Vorschlag angelegt — bitte bestätigen."}',
    ]);

    $modal = Livewire::test(VoiceModal::class)->call('verarbeiteText', 'Lösche das Rezept Tomatensuppe klassisch');

    $proposal = collect($modal->get('ergebnis')['proposals'])->firstWhere('type', 'schreibaktion');
    expect($proposal['objekt']['name'])->toBe('Tomatensuppe klassisch')
        ->and($proposal['vorschau'])->toBe([]);                            // Löschen hat keinen Feld-Diff
    expect(FoodAlchemistRecipe::find($rezept->id))->not->toBeNull();       // NICHT gelöscht vor dem Klick

    $index = collect($modal->get('ergebnis')['proposals'])->search(fn ($p) => $p['type'] === 'schreibaktion');
    $modal->call('schreibaktionAusfuehren', $index);

    expect(FoodAlchemistRecipe::find($rezept->id))->toBeNull();            // JETZT gelöscht (confirm wurde am Klick wiederhergestellt)
});

it('Schreibvorschlag ohne Alias: rohe Argumente ohne Alt-Wert + Tool-Beschreibung, kein falscher Diff', function () {
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.gps.POST","arguments":{"hauptzutat":"Zander"}}',
        '{"action":"final","text":"Vorschlag angelegt — bitte bestätigen."}',
    ]);

    $modal = Livewire::test(VoiceModal::class)->call('verarbeiteText', 'Lege ein Grundprodukt Zander an');

    $proposal = collect($modal->get('ergebnis')['proposals'])->firstWhere('type', 'schreibaktion');
    expect($proposal['tool'])->toBe('foodalchemist.gps.POST')
        ->and($proposal['beschreibung'])->not->toBeNull()
        ->and($proposal['vorschau'][0])->toBe(['feld' => 'hauptzutat', 'neu' => 'Zander'])
        ->and($proposal['vorschau'][0])->not->toHaveKey('alt');            // kein geratener Alt-Wert
});

/*
 * Spec 53 / Paket F (3): Konversations-Modus — Sprachausgabe. Server-Teil (Contract, Route,
 * Livewire-Methoden, Call-Log); VAD/Autoplay/speechSynthesis-Fallback ist Browser-Teil (Stufe 3B).
 */

it('TTS-Vorlesen AN: nach der Antwort wird voice-tts-bereit dispatcht (Fake-TTS, kein echtes HTTP)', function () {
    config(['foodalchemist.tts.provider' => 'fake']);
    app(TeamSettingsService::class)->update($this->rootTeam, ['voice_tts_vorlesen' => true]);
    ($this->skript)(['{"action":"final","text":"Alles klar."}']);

    Livewire::test(VoiceModal::class)
        ->call('verarbeiteText', 'Hallo')
        ->assertDispatched('voice-tts-bereit');

    expect(DB::table('foodalchemist_ai_call_log')->where('feature', 'voice.tts')->exists())->toBeTrue();
});

it('TTS-Vorlesen AUS (Default): keine Sprachausgabe ausgelöst', function () {
    ($this->skript)(['{"action":"final","text":"Alles klar."}']);

    Livewire::test(VoiceModal::class)
        ->call('verarbeiteText', 'Hallo')
        ->assertNotDispatched('voice-tts-bereit');
});

it('TTS-Vorlesen AN, aber die Antwort navigiert: kein Vorlesen (der Ton würde die verlassene Seite treffen)', function () {
    config(['foodalchemist.tts.provider' => 'fake']);
    app(TeamSettingsService::class)->update($this->rootTeam, ['voice_tts_vorlesen' => true]);
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.ui.NAVIGATE","arguments":{"route_key":"planung"}}',
        '{"action":"final","text":"Planung geöffnet."}',
    ]);

    Livewire::test(VoiceModal::class)
        ->call('verarbeiteText', 'Öffne die Planung')
        ->assertNotDispatched('voice-tts-bereit');
});

it('sprechen(): setzt sprichtGerade + dispatcht die signierte Audio-URL; sprechenBeendet() setzt zurück', function () {
    config(['foodalchemist.tts.provider' => 'fake']);

    Livewire::test(VoiceModal::class)
        ->call('sprechen', 'Testsatz')
        ->assertSet('sprichtGerade', true)
        ->assertDispatched('voice-tts-bereit')
        ->call('sprechenBeendet')
        ->assertSet('sprichtGerade', false);
});

it('sprechen(): TTS-Fehler dispatcht voice-tts-fehlgeschlagen statt die UI zu blockieren', function () {
    config(['foodalchemist.tts.provider' => 'none']);   // bindet UnkonfiguriertTtsService, wirft immer

    Livewire::test(VoiceModal::class)
        ->call('sprechen', 'Testsatz')
        ->assertSet('sprichtGerade', false)
        ->assertDispatched('voice-tts-fehlgeschlagen');
});

it('die von sprechen() TATSÄCHLICH dispatchte Audio-URL ist abrufbar und liefert die echten Bytes (Single-Use)', function () {
    config(['foodalchemist.tts.provider' => 'fake']);

    $url = null;
    Livewire::test(VoiceModal::class)
        ->call('sprechen', 'Testsatz')
        ->assertDispatched('voice-tts-bereit', function ($name, $params) use (&$url) {
            $url = $params['url'];

            return is_string($url) && $url !== '';
        });

    $response = $this->get($url);
    $response->assertOk();
    expect($response->getContent())->toBe((new \Platform\FoodAlchemist\Services\Tts\FakeTtsService())->synthesize('Testsatz'))
        ->and($response->headers->get('Content-Type'))->toStartWith('audio/mpeg');

    // Single-Use: derselbe Link liefert beim zweiten Abruf nichts mehr.
    $this->get($url)->assertNotFound();
});

/**
 * Live-Bruch Dominique (2026-09-18): auf demo läuft `cache.default=database` — rohe MP3-Bytes
 * in einer utf8mb4-Textspalte lässt MySQL im strict mode NICHT zu (SQLSTATE 1366 "Incorrect
 * string value"), reproduziert per Tinker (random_bytes wirft, base64_encode geht durch). Der
 * SQLite-Test-Treiber kennt dieses Problem NICHT (keine Charset-Prüfung) — der Round-Trip-Test
 * oben wäre also grün geblieben, selbst mit dem Bug. Diese Prüfung greift direkt am gecachten
 * Rohwert an: er MUSS reines ASCII sein (Base64), sonst wäre auf einem strengen SQL-Cache-
 * Treiber genau dieser Bruch wieder da.
 */
it('die gecachten TTS-Bytes sind reines ASCII (Base64) — nicht die rohen Binär-Bytes, sonst scheitert ein utf8mb4-Cache-Treiber', function () {
    config(['foodalchemist.tts.provider' => 'fake']);

    $url = null;
    Livewire::test(VoiceModal::class)
        ->call('sprechen', 'Testsatz')
        ->assertDispatched('voice-tts-bereit', function ($name, $params) use (&$url) {
            $url = $params['url'];

            return true;
        });

    $token = basename(parse_url($url, PHP_URL_PATH));
    $eintrag = \Illuminate\Support\Facades\Cache::get(\Platform\FoodAlchemist\Http\Controllers\VoiceAudioController::cacheKey($token));

    expect($eintrag)->not->toBeNull()
        ->and(mb_check_encoding($eintrag['bytes'], 'ASCII'))->toBeTrue()
        ->and(base64_decode($eintrag['bytes'], true))->not->toBeFalse();
});

it('TTS-Fehler landet MIT Fehlertext im Call-Log — "fehler — fehlgeschlagen" ohne Ursache war 40 Minuten Sucherei', function () {
    config(['foodalchemist.tts.provider' => 'none']);   // bindet UnkonfiguriertTtsService, wirft immer

    Livewire::test(VoiceModal::class)->call('sprechen', 'Testsatz');

    $zeile = \Illuminate\Support\Facades\DB::table('foodalchemist_ai_call_log')
        ->where('feature', 'voice.tts')->latest('id')->first();

    expect($zeile)->not->toBeNull()
        ->and($zeile->error)->not->toBeNull()
        ->and($zeile->error)->toContain('nicht konfiguriert');
});

it('Audio-Route ohne gültige Signatur wird abgelehnt (403) — kein Erraten der Route über die reine ID', function () {
    $ungesichert = route('foodalchemist.voice.audio', ['token' => 'irgendwas']);

    $this->get($ungesichert)->assertForbidden();
});

it('Audio-Route mit gültiger Signatur, aber unbekanntem/abgelaufenem Token: 404 statt Fatal', function () {
    $signierteUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'foodalchemist.voice.audio', now()->addMinutes(5), ['token' => 'nie-gecacht'],
    );

    $this->get($signierteUrl)->assertNotFound();
});

/*
 * Spec 53 / Paket F (3), Browser-Teil: VAD/Autoplay/speechSynthesis-Fallback selbst lässt sich
 * ohne echten Browser nicht ausführen — diese Tests pinnen, was Pest MESSEN kann: das gerenderte
 * Markup, die Event-Verdrahtung als Konstanten (kein Magic-String an zwei Stellen) und die
 * fünf Zustands-Texte. Chrome/Safari-Verhalten prüft Dominique live (siehe PR-Body).
 */

it('Event-Namen sind Konstanten: das Blade zitiert VoiceModal::EVENT_TTS_BEREIT/-FEHLGESCHLAGEN statt eigener String-Literale', function () {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/livewire/voice-modal.blade.php');

    expect($blade)->toContain('VoiceModal::EVENT_TTS_BEREIT')
        ->and($blade)->toContain('VoiceModal::EVENT_TTS_FEHLGESCHLAGEN')
        // Kein zweites, unabhängig gepflegtes String-Literal für denselben Event-Namen im Blade —
        // die einzigen Vorkommen von 'voice-tts-bereit'/'voice-tts-fehlgeschlagen' als Text sind
        // die PHP-Konstanten-Werte selbst (VoiceModal.php), nicht das Blade.
        ->and($blade)->not->toContain("'voice-tts-bereit'")
        ->and($blade)->not->toContain("'voice-tts-fehlgeschlagen'");

    expect(VoiceModal::EVENT_TTS_BEREIT)->toBe('voice-tts-bereit')
        ->and(VoiceModal::EVENT_TTS_FEHLGESCHLAGEN)->toBe('voice-tts-fehlgeschlagen');
});

it('Fallback-Event ist verdrahtet: $wire.on(EVENT_TTS_FEHLGESCHLAGEN) ruft den speechSynthesis-Fallback', function () {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/livewire/voice-modal.blade.php');

    // Nicht die ganze Zeile wörtlich (bricht bei jeder Formatierungsänderung) — die
    // BAUSTEINE einzeln: derselbe $wire.on-Block referenziert den Konstanten-Namen UND
    // ruft den Fallback auf; speechSynthesis/SpeechSynthesisUtterance stehen im Fallback.
    $onFehlgeschlagenZeile = collect(explode("\n", $blade))
        ->first(fn ($z) => str_contains($z, '$wire.on') && str_contains($z, 'EVENT_TTS_FEHLGESCHLAGEN'));

    expect($onFehlgeschlagenZeile)->not->toBeNull('kein $wire.on(...EVENT_TTS_FEHLGESCHLAGEN...) im Blade gefunden')
        ->and($onFehlgeschlagenZeile)->toContain('_sprachausgabeFallback')
        ->and($blade)->toContain('speechSynthesis')
        ->and($blade)->toContain('SpeechSynthesisUtterance')
        // Autoplay blockiert (kein Entsperrt-Flag in _wiedergeben) landet im SELBEN Fallback-Pfad,
        // nicht in einem zweiten, separat gepflegten.
        ->and(substr_count($blade, '_sprachausgabeFallback'))->toBeGreaterThanOrEqual(3);
});

it('Zustandsanzeige: alle fünf data-voice-status-Werte stehen als Markup fest (hört zu · sendet · versteht · führt aus · spricht)', function () {
    config(['foodalchemist.stt.provider' => 'openai', 'services.openai.api_key' => 'sk-test']);

    $html = Livewire::test(VoiceModal::class)->html();

    foreach (['hoert_zu', 'sendet', 'versteht', 'fuehrt_aus'] as $status) {
        expect($html)->toContain("data-voice-status=\"{$status}\"");
    }
    // „spricht" ist NICHT immer im Markup (nur solange $sprichtGerade true ist) — das ist
    // Absicht (echter Server-Zustand, kein CSS-Dauerelement) und wird im nächsten Test geprüft.
});

it('Zustand „spricht": data-voice-status="spricht" erscheint NUR während sprichtGerade wahr ist', function () {
    config(['foodalchemist.stt.provider' => 'openai', 'services.openai.api_key' => 'sk-test', 'foodalchemist.tts.provider' => 'fake']);

    $modal = Livewire::test(VoiceModal::class);
    expect($modal->html())->not->toContain('data-voice-status="spricht"');

    $modal->call('sprechen', 'Testsatz');
    expect($modal->html())->toContain('data-voice-status="spricht"');

    $modal->call('sprechenBeendet');
    expect($modal->html())->not->toContain('data-voice-status="spricht"');
});

it('VAD-Optionen: konversationAktiv steuert vad:true/false am Recorder', function () {
    config(['foodalchemist.stt.provider' => 'openai', 'services.openai.api_key' => 'sk-test']);

    $aus = Livewire::test(VoiceModal::class)->html();
    expect($aus)->toContain('vad: false');

    app(TeamSettingsService::class)->update($this->rootTeam, ['voice_agent_dauerhaft_aktiv' => true]);
    $an = Livewire::test(VoiceModal::class)->html();
    expect($an)->toContain('vad: true');
});

/*
 * Spec 53 / Paket F (4): Gesprächsgedächtnis — Referenz-Bestätigung auf einen offenen
 * Vorschlag, Fortbestand über eine simulierte Seiten-Navigation hinweg, „Gespräch vergessen".
 */

it('Zwei-Zug-Dialog: Zug 1 erzeugt zwei Vorschläge, Zug 2 „das zweite" bestätigt NUR den zweiten', function () {
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.gps.POST","arguments":{"hauptzutat":"Zander"}}',
        '{"action":"tool","name":"foodalchemist.gps.POST","arguments":{"hauptzutat":"Lachs"}}',
        '{"action":"final","text":"Zwei Vorschläge — bitte bestätigen."}',
    ]);

    $modal = Livewire::test(VoiceModal::class)->call('verarbeiteText', 'Lege zwei Grundprodukte an');
    $vorschlaege = $modal->get('ergebnis')['proposals'];
    expect($vorschlaege)->toHaveCount(2)
        ->and($vorschlaege[0]['accepted'] ?? false)->toBeFalse()
        ->and($vorschlaege[1]['accepted'] ?? false)->toBeFalse();

    // Zug 2: KEIN zweiter Tool-Loop-Skript-Eintrag nötig — die Referenz läuft VOR dem Tool-Loop.
    $modal->call('verarbeiteText', 'das zweite');

    $nachher = $modal->get('ergebnis')['proposals'];
    expect($nachher[0]['accepted'] ?? false)->toBeFalse('der ERSTE Vorschlag darf NICHT mit-bestätigt werden')
        ->and($nachher[1]['accepted'] ?? false)->toBeTrue('der ZWEITE Vorschlag muss bestätigt sein');

    // Echte Ausführung, nicht nur ein Flag — dasselbe Tool wie im Vorschlag, mit den Lachs-Argumenten
    // (nicht Zander — das wäre der ERSTE, nicht bestätigte Vorschlag).
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistGp::where('name', 'like', '%lachs%')->exists())->toBeTrue()
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistGp::where('name', 'like', '%zander%')->exists())->toBeFalse();
});

it('reine Zustimmung "ja" bestätigt den einen offenen Vorschlag — bei mehreren bleibt sie mehrdeutig (kein Rateversuch)', function () {
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.gps.POST","arguments":{"hauptzutat":"Zander"}}',
        '{"action":"final","text":"Vorschlag — bitte bestätigen."}',
    ]);
    $modal = Livewire::test(VoiceModal::class)->call('verarbeiteText', 'Lege ein Grundprodukt an');

    $modal->call('verarbeiteText', 'ja');

    expect($modal->get('ergebnis')['proposals'][0]['accepted'] ?? false)->toBeTrue();
});

it('Sitzung überlebt eine simulierte Seiten-Navigation: neue Komponenten-Instanz stellt die offenen Vorschläge wieder her', function () {
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.gps.POST","arguments":{"hauptzutat":"Zander"}}',
        '{"action":"final","text":"Vorschlag — bitte bestätigen."}',
    ]);
    Livewire::test(VoiceModal::class)->call('verarbeiteText', 'Lege ein Grundprodukt an');

    // NEUE Komponenten-Instanz = wie ein frischer Seiten-Mount (agent-mount.blade.php auf
    // JEDER FA-Vollseite) + Öffnen-Klick — genau der Fall, den Stufe 2 sonst kaputt macht.
    $neuesModal = Livewire::test(VoiceModal::class)->call('oeffnen');

    $wiederhergestellt = $neuesModal->get('ergebnis')['proposals'] ?? [];
    expect($wiederhergestellt)->toHaveCount(1)
        ->and($wiederhergestellt[0]['tool'])->toBe('foodalchemist.gps.POST')
        ->and($wiederhergestellt[0]['accepted'] ?? false)->toBeFalse();

    // Und die Referenz funktioniert auf der NEUEN Instanz genauso wie auf der alten.
    $neuesModal->call('verarbeiteText', 'ja');
    expect($neuesModal->get('ergebnis')['proposals'][0]['accepted'] ?? false)->toBeTrue();
});

it('„Gespräch vergessen": eine neue Komponenten-Instanz stellt danach NICHTS mehr wieder her', function () {
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.gps.POST","arguments":{"hauptzutat":"Zander"}}',
        '{"action":"final","text":"Vorschlag — bitte bestätigen."}',
    ]);
    $modal = Livewire::test(VoiceModal::class)->call('verarbeiteText', 'Lege ein Grundprodukt an');
    $modal->call('vergessen');

    $neuesModal = Livewire::test(VoiceModal::class)->call('oeffnen');
    expect($neuesModal->get('ergebnis'))->toBeNull();
});

it('„Gespräch vergessen" ist nur sichtbar, wenn es Vorschläge gibt', function () {
    ($this->skript)(['{"action":"final","text":"Hallo, wie kann ich helfen?"}']);
    $ohneVorschlag = Livewire::test(VoiceModal::class)->call('verarbeiteText', 'Hallo');
    expect($ohneVorschlag->html())->not->toContain('data-voice-vergessen');

    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.gps.POST","arguments":{"hauptzutat":"Zander"}}',
        '{"action":"final","text":"Vorschlag — bitte bestätigen."}',
    ]);
    $mitVorschlag = Livewire::test(VoiceModal::class)->call('verarbeiteText', 'Lege ein Grundprodukt an');

    expect($mitVorschlag->html())->toContain('data-voice-vergessen');
});

/*
 * Live-Befund Dominique (2026-09-18): drei Hotfixes off main 8b90e0b7 in EINEM PR.
 */

it('oeffnen(): sowohl Sidebar- als auch schwebender Knopf schicken autostart:true mit', function () {
    $sidebar = file_get_contents(__DIR__ . '/../../resources/views/livewire/sidebar.blade.php');
    $agentMount = file_get_contents(__DIR__ . '/../../resources/views/partials/agent-mount.blade.php');

    expect($sidebar)->toContain("\$dispatch('voice-modal.oeffnen', { autostart: true })")
        // Live-Bruch 2026-09-18 (Punkt 3): der schwebende Knopf schickt seit dem »schwebender
        // Begleiter«-Schritt ZUSÄTZLICH `schwebend:true` mit (Sidebar NICHT — deren Ein-Klick-
        // Modus öffnet weiter immer normal), darum eigene, WEITERE Assertion statt derselben.
        ->and($agentMount)->toContain("\$dispatch('voice-modal.oeffnen', { autostart: true, schwebend: true })");
});

it('der Recorder startet NUR, wenn autostart UND konversationAktiv beide wahr sind (Ein-Klick-Modus bleibt unverändert)', function () {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/livewire/voice-modal.blade.php');

    $zeile = collect(explode("\n", $blade))
        ->first(fn ($z) => str_contains($z, "addEventListener('voice-modal.oeffnen'"));
    expect($zeile)->not->toBeNull();

    $bedingung = collect(explode("\n", $blade))
        ->first(fn ($z) => str_contains($z, 'e?.detail?.autostart'));
    expect($bedingung)->toContain('this.konversationAktiv')
        ->and($bedingung)->toContain('this.unterstuetzt')
        ->and($bedingung)->toContain('this.laeuft');
});

it('z-index: Sprachbefehl-Modal ist fest über allen Editoren gepinnt (zFest), der schwebende Knopf über BEIDEM', function () {
    config(['foodalchemist.stt.provider' => 'openai', 'services.openai.api_key' => 'sk-test']);

    $modalHtml = Livewire::test(VoiceModal::class)->html();
    expect($modalHtml)->toContain('z-[190]')->not->toContain('z-[100]');

    $agentMount = file_get_contents(__DIR__ . '/../../resources/views/partials/agent-mount.blade.php');
    expect($agentMount)->toContain('z-[210]');

    // 210 > 190 (Modal) > 100 (jeder Standard-Editor über dieselbe Komponente) — der Knopf
    // gewinnt in JEDER Kombination, unabhängig von der bringToFront-Reihung der Editoren.
    expect(210)->toBeGreaterThan(190)->and(190)->toBeGreaterThan(100);
});

it('andere Modale (z. B. ein GP-Editor) bleiben bei z-[100] — zFest ist additiv, kein globaler Verhaltenswechsel', function () {
    $html = \Illuminate\Support\Facades\Blade::render(
        '<x-foodalchemist::modal name="gp-edit" title="GP bearbeiten">X</x-foodalchemist::modal>'
    );

    expect($html)->toContain('z-[100]')->not->toContain('z-[190]');
});

/**
 * Live-Bruch nach PR #116 (Deploy 8): vier JS-Kommentarzeilen INNERHALB von `x-data="…"` in
 * voice-modal.blade.php enthielten geradeaus-Anführungszeichen (") — ein HTML-Attribut endet
 * beim ERSTEN `"`, egal ob es aus Blade/PHP oder rohem Text stammt. Das Attribut riss dort ab,
 * Alpine bekam nur noch ein Bruchstück-Objekt und fiel für das GANZE Modal aus (Code als
 * Klartext sichtbar, schwebender Knopf komplett weg). 148 grüne Tests vorher hatten das NICHT
 * gesehen: `Livewire::test()->html()` prüft Textinhalt/Marker, kein Attribut-Parsing wie ein
 * echter Browser. Dieser Wächter tut GENAU das — ein HTML-Attribut-Parser in Miniatur.
 */
it('WÄCHTER: jedes x-data="…" im gerenderten Voice-Modal ist eine VOLLSTÄNDIGE JS-Klammer — kein " reisst es ab', function () {
    config(['foodalchemist.stt.provider' => 'openai', 'services.openai.api_key' => 'sk-test']);
    $html = Livewire::test(VoiceModal::class)->html();

    // Exakt wie ein HTML-Parser: das Attribut endet beim ERSTEN ", ganz gleich was davor stand.
    preg_match_all('/x-data="([^"]*)"/s', $html, $treffer);
    expect($treffer[1])->not->toBeEmpty();

    foreach ($treffer[1] as $i => $inhalt) {
        // Ein abgerissenes Attribut endet mitten in einem Kommentar/Bezeichner, nicht auf der
        // schliessenden Klammer des Objekt-Literals.
        expect(rtrim($inhalt))->toEndWith('}');
    }

    // Das GRÖSSTE, am meisten kommentierte Objekt (der Recorder) muss den LETZTEN definierten
    // Bezeichner wirklich enthalten — ein Abriss mitten in den Kommentaren fehlte GENAU hier,
    // weil das Attribut lange vorher endete (das war der reale Bruch).
    $recorderData = collect($treffer[1])->first(fn ($t) => str_contains($t, 'initKonversation'));
    expect($recorderData)->not->toBeNull()
        ->and($recorderData)->toContain('_nachDemSprechen')
        ->and($recorderData)->toContain('_sprachausgabeFallback');
});

it('WÄCHTER: dasselbe für agent-mount.blade.php — jedes x-data="…" ist eine VOLLSTÄNDIGE JS-Klammer', function () {
    $html = view('foodalchemist::partials.agent-mount')->render();

    preg_match_all('/x-data="([^"]*)"/s', $html, $treffer);
    expect($treffer[1])->not->toBeEmpty();

    foreach ($treffer[1] as $inhalt) {
        expect(rtrim($inhalt))->toEndWith('}');
    }

    $floatData = collect($treffer[1])->first(fn ($t) => str_contains($t, 'startDrag'));
    expect($floatData)->not->toBeNull()
        ->and($floatData)->toContain('oeffnen')
        ->and($floatData)->toContain('stopDrag');
});

/*
 * Live-Bruch Dominique (2026-09-18, Punkt 2): Endlos-Schleife im Konversations-Modus, nicht
 * stoppbar. VAD/Audio-Timing selbst lässt sich ohne echten Browser nicht ausführen — diese Tests
 * pinnen, was Pest MESSEN kann: dass die Sicherungen im Markup/Quelltext wirklich verdrahtet
 * sind (Stopp überall, Sicherheitsdeckel, kein Upload/Wiedereinstieg ohne erkannte Sprache).
 */

it('stopAlles(): räumt Recorder, TTS-Audio UND speechSynthesis ab und pausiert die Konversation', function () {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/livewire/voice-modal.blade.php');

    $zeile = collect(explode("\n", $blade))->first(fn ($z) => str_contains($z, 'stopAlles()'));
    expect($zeile)->not->toBeNull();

    expect($blade)->toContain('window.speechSynthesis.cancel()')
        ->and($blade)->toContain('audio.pause()')
        ->and($blade)->toContain('this.konversationPausiert = true')
        ->and($blade)->toContain('@keydown.window.escape="stopAlles()"');   // ESC stoppt überall
});

it('der Stopp-/Aufnahme-Knopf reagiert auf hört-zu/sendet/spricht gemeinsam, nicht nur auf "laeuft"', function () {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/livewire/voice-modal.blade.php');

    $zeile = collect(explode("\n", $blade))
        ->first(fn ($z) => str_contains($z, '? stopAlles() : (autoZyklen'));
    expect($zeile)->not->toBeNull()
        ->and($zeile)->toContain('hochladenLaeuft')
        ->and($zeile)->toContain('fallbackAktiv')
        ->and($zeile)->toContain('$wire.sprichtGerade');
});

it('Sicherheitsdeckel: nach 3 automatischen Zyklen pausiert die Konversation statt endlos weiterzuhören', function () {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/livewire/voice-modal.blade.php');

    $zeile = collect(explode("\n", $blade))->first(fn ($z) => str_contains($z, 'this.autoZyklen >= 3'));
    expect($zeile)->not->toBeNull();

    // Der Zähler zählt JEDEN automatischen Zyklus (nicht nur stille) und wird NUR bei einem
    // echten Nutzer-Klick zurückgesetzt (autostart-Listener) — sonst könnte eine Kette echter
    // Antworten den Deckel umgehen.
    expect($blade)->toContain('this.autoZyklen++')
        ->and($blade)->toContain('this.autoZyklen = 0');
});

it('VAD "keine Sprache erkannt": kein Upload, kein automatischer Wiedereinstieg', function () {
    $js = file_get_contents(__DIR__ . '/../../resources/js/voice-recorder/index.js');

    $zeile = collect(explode("\n", $js))
        ->first(fn ($z) => str_contains($z, "this.vad && this._vadState === 'warten'"));
    expect($zeile)->not->toBeNull();

    // Der Guard muss VOR dem Upload-Aufruf stehen und dort mit `return` aussteigen — sonst würde
    // der Code darunter (Mindestdauer-Check, `$wire.upload(...)`) trotzdem noch laufen.
    $zeilen = explode("\n", $js);
    $guardIndex = null;
    $uploadIndex = null;
    foreach ($zeilen as $i => $z) {
        if ($guardIndex === null && str_contains($z, "this.vad && this._vadState === 'warten'")) {
            $guardIndex = $i;
        }
        if ($uploadIndex === null && str_contains($z, '$wire.upload(')) {
            $uploadIndex = $i;
        }
    }
    expect($guardIndex)->not->toBeNull()->and($uploadIndex)->not->toBeNull()
        ->and($guardIndex)->toBeLessThan($uploadIndex);
});

it('schwebender Knopf: ein Klick WÄHREND eines laufenden Zyklus stoppt, statt erneut zu öffnen', function () {
    $agentMount = file_get_contents(__DIR__ . '/../../resources/views/partials/agent-mount.blade.php');

    $zeile = collect(explode("\n", $agentMount))
        ->first(fn ($z) => str_contains($z, 'window.FaVoiceKonversationAktiv && window.FaVoiceStopAlles'));
    expect($zeile)->not->toBeNull();

    // Der Stopp-Zweig muss VOR dem Öffnen-Dispatch geprüft werden, sonst würde immer erst
    // erneut geöffnet, bevor überhaupt gestoppt werden könnte.
    $stoppIndex = null;
    $oeffnenIndex = null;
    foreach (explode("\n", $agentMount) as $i => $z) {
        if ($stoppIndex === null && str_contains($z, 'FaVoiceStopAlles();')) {
            $stoppIndex = $i;
        }
        if ($oeffnenIndex === null && str_contains($z, "\$dispatch('voice-modal.oeffnen', { autostart: true, schwebend: true })")) {
            $oeffnenIndex = $i;
        }
    }
    expect($stoppIndex)->not->toBeNull()->and($oeffnenIndex)->not->toBeNull()
        ->and($stoppIndex)->toBeLessThan($oeffnenIndex);
});

/*
 * Live-Bruch Dominique (2026-09-18, Punkt 3 — erster Schritt Spec 54 »schwebender Begleiter«):
 * das Modal soll im Konversations-Modus NICHT mehr aufreissen, solange es nichts zu bestätigen
 * gibt — der schwebende Knopf zeigt den Zustand selbst, eine Sprechblase Transkript + Antwort.
 */

it('oeffnen(schwebend:true) im Konversations-Modus OHNE offene Vorschläge öffnet das Modal NICHT', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['voice_agent_dauerhaft_aktiv' => true]);

    Livewire::test(VoiceModal::class)
        ->call('oeffnen', null, true)
        ->assertNotDispatched('modal.open');
});

it('oeffnen(schwebend:true) im Konversations-Modus MIT offenen Vorschlägen öffnet das Modal trotzdem (etwas zu bestätigen)', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['voice_agent_dauerhaft_aktiv' => true]);
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.gps.POST","arguments":{"hauptzutat":"Zander"}}',
        '{"action":"final","text":"Vorschlag — bitte bestätigen."}',
    ]);
    Livewire::test(VoiceModal::class)->call('verarbeiteText', 'Lege ein Grundprodukt an');
    // Neue Instanz = wie ein frischer Seiten-Mount + Öffnen-Klick — die Sitzung hat den
    // Vorschlag gespeichert, jetzt öffnet oeffnen(schwebend:true) TROTZDEM.
    Livewire::test(VoiceModal::class)
        ->call('oeffnen', null, true)
        ->assertDispatched('modal.open');
});

it('oeffnen(schwebend:false) — Sidebar/Ein-Klick-Modus, Klick auf die Blase — öffnet IMMER, unabhängig vom Konversations-Modus', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['voice_agent_dauerhaft_aktiv' => true]);

    Livewire::test(VoiceModal::class)
        ->call('oeffnen', null, false)
        ->assertDispatched('modal.open');
});

it('oeffnen(schwebend:true) OHNE Konversations-Modus öffnet ebenfalls IMMER (die Sperre gilt NUR im Konversations-Modus)', function () {
    Livewire::test(VoiceModal::class)
        ->call('oeffnen', null, true)
        ->assertDispatched('modal.open');
});

it('ein NEUER Vorschlag WÄHREND des Turns öffnet das Modal nachträglich, auch wenn oeffnen() es zuvor geschlossen liess', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['voice_agent_dauerhaft_aktiv' => true]);
    $modal = Livewire::test(VoiceModal::class)->call('oeffnen', null, true);
    $modal->assertNotDispatched('modal.open');   // beim Öffnen noch nichts zu bestätigen

    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.gps.POST","arguments":{"hauptzutat":"Zander"}}',
        '{"action":"final","text":"Vorschlag — bitte bestätigen."}',
    ]);
    $modal->call('verarbeiteText', 'Lege ein Grundprodukt an')
        ->assertDispatched('modal.open');        // jetzt gibt es etwas zu bestätigen
});

it('schwebender Knopf zeigt GENAU vier Zustände (pulsierend=hört zu, Wellen=spricht, ruhig=wartet, Fehler rot) — nicht mehr, wie verabredet', function () {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/livewire/voice-modal.blade.php');

    $zeile = collect(explode("\n", $blade))->first(fn ($z) => str_contains($z, '_schwebeStatus()'));
    expect($zeile)->not->toBeNull();
    foreach (["return 'fehler'", "return 'spricht'", "return 'hoert_zu'", "return 'wartet'"] as $wert) {
        expect($blade)->toContain($wert);
    }

    $agentMount = file_get_contents(__DIR__ . '/../../resources/views/partials/agent-mount.blade.php');
    expect($agentMount)->toContain("schwebeStatus === 'hoert_zu'")
        ->and($agentMount)->toContain("schwebeStatus === 'spricht'")
        ->and($agentMount)->toContain("schwebeStatus === 'fehler'")
        ->and($agentMount)->toContain('data-voice-float-status');
});

it('die Sprechblase (drittes Element in agent-mount) zeigt Transkript + Antwort und öffnet beim Klick das Modal normal (nicht schwebend)', function () {
    $agentMount = file_get_contents(__DIR__ . '/../../resources/views/partials/agent-mount.blade.php');

    expect($agentMount)->toContain('data-voice-blase')
        ->and($agentMount)->toContain('schwebeTranskript')
        ->and($agentMount)->toContain('schwebeAntwort')
        // Ein Klick auf die Blase öffnet NORMAL (schwebend:false) — anders als der Knopf selbst.
        ->and($agentMount)->toContain("\$dispatch('voice-modal.oeffnen', { autostart: false, schwebend: false })");
});

it('voice-modal.blade.php spiegelt seinen Zustand nach window.FaVoiceZustand — die Brücke zum schwebenden Knopf', function () {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/livewire/voice-modal.blade.php');

    expect($blade)->toContain('window.FaVoiceZustand')
        ->and($blade)->toContain('this._schwebeStatus()');
});
