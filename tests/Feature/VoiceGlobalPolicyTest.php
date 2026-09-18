<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\VoiceCommandService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Phase C2: der Sprach-Agent steuert den ganzen FoodAlchemist über eine POLICY
 * statt über eine Pflegeliste. Diese Tests pinnen die Sicherheitsgrenze — sie ist
 * der Grund, warum die Whitelist wachsen DARF.
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
});

it('Policy: lesendes FA-Tool ausserhalb des Basiskatalogs ist erlaubt', function () {
    $reg = app(ToolRegistry::class);
    $kandidat = collect($reg->all())
        ->first(fn ($t) => str_starts_with($t->getName(), 'foodalchemist.')
            && ! in_array($t->getName(), VoiceCommandService::TOOLS, true)
            && (($t->getMetadata()['read_only'] ?? null) === true));

    expect($kandidat)->not->toBeNull('Kein lesendes FA-Tool ausserhalb des Basiskatalogs — Fixture prüfen');
    expect(VoiceCommandService::darfNutzen($kandidat->getName(), $kandidat))->toBeTrue();
});

it('Policy: schreibendes FA-Tool ist gesperrt — auch mit »proposals« im Namen', function () {
    $reg = app(ToolRegistry::class);

    // match_proposals.PUT ÜBERNIMMT einen Vorschlag (accept/reject) — die Falle, in die
    // ein Filter nach Namensmuster laufen würde.
    $uebernahme = $reg->get('foodalchemist.match_proposals.PUT');
    expect($uebernahme)->not->toBeNull();
    expect(VoiceCommandService::darfNutzen('foodalchemist.match_proposals.PUT', $uebernahme))->toBeFalse();

    // Gegenprobe: der echte Vorschlag ist erlaubt.
    $wunsch = $reg->get('foodalchemist.gp_proposals.POST');
    expect($wunsch)->not->toBeNull();
    expect(VoiceCommandService::darfNutzen('foodalchemist.gp_proposals.POST', $wunsch))->toBeTrue();
});

it('Policy: fremdes Modul ist gesperrt, auch wenn es lesend ist', function () {
    $reg = app(ToolRegistry::class);
    $fremd = collect($reg->all())
        ->first(fn ($t) => ! str_starts_with($t->getName(), 'foodalchemist.')
            && (($t->getMetadata()['read_only'] ?? null) === true));

    expect($fremd)->not->toBeNull('Kein fremdes lesendes Tool gefunden — Fixture prüfen');
    expect(VoiceCommandService::darfNutzen($fremd->getName(), $fremd))->toBeFalse();
});

it('Policy ist fail-closed: fehlendes read_only-Flag gilt als schreibend', function () {
    $ohneFlag = new class
    {
        public function getMetadata(): array
        {
            return ['category' => 'utility'];                        // kein read_only
        }
    };
    $wahrheitsnah = new class
    {
        public function getMetadata(): array
        {
            return ['read_only' => 'true'];                          // String, nicht bool
        }
    };

    expect(VoiceCommandService::darfNutzen('foodalchemist.irgendwas.GET', $ohneFlag))->toBeFalse();
    expect(VoiceCommandService::darfNutzen('foodalchemist.irgendwas.GET', $wahrheitsnah))->toBeFalse();
});

it('GL-07: Commit-Flags werden entschärft — kein Direkt-Write über Sprache', function () {
    foreach (VoiceCommandService::COMMIT_FLAGS as $flag) {
        $raus = VoiceCommandService::entschaerfeArgumente('foodalchemist.recipe_klasse.POST', ['recipe_id' => 7, $flag => true]);
        expect($raus[$flag])->toBeFalse("Commit-Flag {$flag} überlebt den Sprachpfad");
        expect($raus['recipe_id'])->toBe(7);                         // Fachargumente unangetastet
    }

    // Nicht vorhandene Flags werden NICHT erfunden (sonst schickt der Loop unbekannte Keys).
    expect(VoiceCommandService::entschaerfeArgumente('foodalchemist.recipes.SEARCH', ['q' => 'BBQ']))
        ->toBe(['q' => 'BBQ']);
});

it('Loop: entdecktes lesendes Tool wird ausgeführt und als freigeschaltet protokolliert', function () {
    FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'bbq', 'name' => 'Sauce: BBQ', 'status' => 'approved',
    ]);
    // suppliers.SEARCH steht NICHT im Basiskatalog, ist aber lesend → die Policy lässt es zu.
    // (Live-Bruch 2026-09-18: `ui.ROUTES` — das ursprüngliche Beispiel hier — steht seitdem SELBST
    // im Katalog, als Rückfall für die kurzen route_key-Labels im ui.NAVIGATE-Schema, siehe
    // Token-Deckel-Test. suppliers.SEARCH beweist dieselbe Discovery-Eigenschaft weiterhin.)
    expect(in_array('foodalchemist.suppliers.SEARCH', VoiceCommandService::TOOLS, true))->toBeFalse();
    expect(app(ToolRegistry::class)->get('foodalchemist.suppliers.SEARCH')?->getMetadata()['read_only'] ?? null)->toBeTrue();

    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.suppliers.SEARCH","arguments":{"q":"Chefs"}}',
        '{"action":"final","text":"Hier sind die Lieferanten."}',
    ]);

    $r = app(VoiceCommandService::class)->verarbeite('Suche den Lieferanten Chefs');

    expect($r['tool_laeufe'][0]['name'])->toBe('foodalchemist.suppliers.SEARCH')
        ->and($r['tool_laeufe'][0]['success'])->toBeTrue()
        ->and($r['freigeschaltet'])->toBe(['foodalchemist.suppliers.SEARCH'])
        ->and($r['text'])->toBe('Hier sind die Lieferanten.');

    // Das Audit muss zeigen, WORÜBER der Werkzeugkasten gewachsen ist.
    $summary = DB::table('foodalchemist_ai_call_log')->where('feature', 'voice.command')->latest('id')->value('response_summary');
    expect($summary)->toContain('suppliers.SEARCH');
});

it('Paket F (1b): in fragen/auto_sicher wird ein schreibendes Tool NICHT abgelehnt, sondern zum Schreibvorschlag (kein DB-Write)', function () {
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.gps.POST","arguments":{"hauptzutat":"Zander"}}',
        '{"action":"final","text":"Vorschlag angelegt — bitte bestätigen."}',
    ]);

    $r = app(VoiceCommandService::class)->verarbeite('Lege ein Grundprodukt Zander an');

    expect($r['tool_laeufe'])->toHaveCount(1)                        // abgefangen, nicht abgelehnt
        ->and($r['tool_laeufe'][0]['success'])->toBeTrue()           // kein Fehler ans Modell (SET_ACTIVE-Muster)
        // `freigeschaltet` protokolliert weiterhin JEDEN Aufruf ausserhalb des Warmstart-Katalogs
        // (unabhängig von 1b) — gps.POST steht nicht in TOOLS, taucht also trotzdem hier auf.
        ->and($r['freigeschaltet'])->toBe(['foodalchemist.gps.POST'])
        ->and($r['proposals'][0]['type'])->toBe('schreibaktion')
        ->and($r['proposals'][0]['tool'])->toBe('foodalchemist.gps.POST')
        ->and($r['text'])->toBe('Vorschlag angelegt — bitte bestätigen.');
});

it('Paket F (1b): nur_lesen sperrt schreibende Tools weiter strukturell (kein Vorschlag, kein Write)', function () {
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.gps.POST","arguments":{"hauptzutat":"Zander"}}',
        '{"action":"final","text":"Das darf ich in diesem Modus nicht."}',
    ]);

    $r = app(VoiceCommandService::class)->verarbeite('Lege ein Grundprodukt Zander an', null, 'nur_lesen');

    expect($r['tool_laeufe'])->toBe([])                              // NICHTS ausgeführt, kein Vorschlag
        ->and($r['proposals'])->toBe([])
        ->and($r['runden'])->toBe(2)                                 // Ablehnung beendet den Loop nicht
        ->and($r['text'])->toContain('nicht');
});

it('Token-Deckel: der Basiskatalog bleibt klein — er wird in JEDER Runde bezahlt', function () {
    $reg = app(ToolRegistry::class);
    $zeichen = collect(VoiceCommandService::TOOLS)
        ->map(fn ($n) => $reg->get($n))
        ->filter()
        ->sum(fn ($t) => mb_strlen((string) json_encode(
            ['name' => $t->getName(), 'description' => $t->getDescription(), 'schema' => $t->getSchema()],
            JSON_UNESCAPED_UNICODE,
        )));

    // Live gemessen: alle 111 lesenden FA-Tools wären 78.348 Zeichen ≈ 26.000 Token je Runde.
    // Spec 53/D (2026-09-17): +`ui.NAVIGATE` (8.000 → 8.340), dann +die drei Planungs-Vorschlags-
    // Tools DIREKT im Katalog statt hinter tool_registry.SEARCH (8.340 → 10.047) — Review-Befund
    // cooking-jarvis-03: mit MAX_RUNDEN=4 und der „ablauf.GET zuerst"-Anweisung frässe ein SEARCH-
    // Umweg für „Erstelle ein Gericht …" eine ganze Runde, der Befehl läge exakt am Limit. Runden-
    // budget-Sicherheit wiegt hier schwerer als das ursprüngliche „ein Zehntel"-Ziel (~13 % statt
    // ~10 % des Vollsortiments) — der Deckel bleibt trotzdem eine Wand, keine Formsache: jedes
    // künftige Tool braucht wieder eine bewusste Entscheidung Katalog vs. Discovery.
    // Live-Bruch Dominique (2026-09-18): „Öffne die Seite der Basisrezepte" brauchte 3 Runden
    // (SEARCH → ui.ROUTES → NAVIGATE), weil route_key nur als "aus ui.ROUTES" beschrieben war,
    // ohne den Katalog je im Warmstart zu nennen. Die 27 route_key-Kurzlabel jetzt direkt im
    // ui.NAVIGATE-Schema + `ui.ROUTES` selbst als Rückfall im Katalog (10.047 → 10.970) — Navigation
    // ist damit wieder EINE Runde statt drei, das war der teurere Fehler.
    expect($zeichen)->toBeLessThan(11200, "Basiskatalog auf {$zeichen} Zeichen gewachsen");
});

/*
 * Spec 53/F Stufe 2: der Sprach-Agent mountet NICHT MEHR in der Sidebar (deren `x-ui-sidebar`-
 * Modul-Slot liegt in einem `x-if` und verschwindet beim Einklappen komplett — traf früher auch
 * das dort gemountete Modal). Neu: JEDE FA-Vollseite bindet `foodalchemist::partials.agent-mount`
 * im eigenen Root-Element ein; die Sidebar behält nur noch den Öffnen-Knopf.
 */

it('Platzierung: die Sidebar hat NUR noch den Öffnen-Knopf, KEIN Modal-Mount mehr', function () {
    $html = Livewire::test(\Platform\FoodAlchemist\Livewire\Sidebar::class)->html();

    expect($html)->toContain('data-voice-global')                    // der Knopf bleibt
        ->and($html)->not->toContain('data-voice-float-mount')       // kein Modal-Mount mehr im Sidebar-HTML
        ->and($html)->not->toContain('data-voice-float-button');

    $blade = file_get_contents(__DIR__ . '/../../resources/views/livewire/sidebar.blade.php');
    expect($blade)->not->toContain("@livewire('foodalchemist.voice-modal')");
});

it('Platzierung: agent-mount.blade.php mountet das Modal (Modal-Identität bleibt einmalig pro Seite)', function () {
    $partial = file_get_contents(__DIR__ . '/../../resources/views/partials/agent-mount.blade.php');

    expect($partial)->toContain("@livewire('foodalchemist.voice-modal')")
        ->and($partial)->toContain('data-voice-float-mount')
        ->and($partial)->toContain('data-voice-float-button');
});

it('agent-mount.blade.php: „dauerhaft aktiv" AUS (Default) → Alpine-State aktiv:false', function () {
    $html = view('foodalchemist::partials.agent-mount')->render();
    expect($html)->toContain('aktiv: false');
});

it('agent-mount.blade.php: „dauerhaft aktiv" AN (Team-Setting) → Alpine-State aktiv:true', function () {
    app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->update($this->rootTeam, [
        'voice_agent_dauerhaft_aktiv' => true,
    ]);

    $html = view('foodalchemist::partials.agent-mount')->render();
    expect($html)->toContain('aktiv: true');
});

/**
 * Alle 26 FA-Vollseiten (Routen-Ziele, `grep -oE "Livewire\\\\[A-Za-z0-9_\\\\]+::class" routes/web.php`
 * gegengeprüft, 2026-09-17) — hart hinterlegt statt aus routes/web.php geparst: ein Regex über
 * Bare-Imports (`use ...Dashboard;`) vs. FQCN-Referenzen wäre selbst fehleranfällig, und genau
 * DAS soll dieser Test verlässlich prüfen, nicht neu erfinden.
 */
const FA_VOLLSEITEN_VIEWS = [
    'angebote/index', 'concepter/browser', 'concepts/index', 'controlling/cockpit', 'dashboard',
    'demnaechst', 'favorites/index', 'food-dna/index', 'foodbooks/index', 'formate/browser',
    'geschirr/index', 'gps/browser', 'knowledge/browser', 'orders/index', 'pakete/index',
    'planung/index', 'produktion/browser', 'produktion/tagesplan', 'recipes/browser',
    'review-queue', 'settings/index', 'speisekarte/index', 'speiseplan/index', 'suppliers/index',
    'trendradar/index', 'verkauf/browser',
];

it('Platzierung: JEDE FA-Vollseite bindet das agent-mount-Partial GENAU EINMAL im Root-Element ein', function () {
    $fehlend = [];
    foreach (FA_VOLLSEITEN_VIEWS as $view) {
        $pfad = __DIR__ . "/../../resources/views/livewire/{$view}.blade.php";
        if (! file_exists($pfad)) {
            $fehlend[] = "{$view} (View-Datei fehlt — Liste veraltet?)";

            continue;
        }
        $treffer = substr_count(file_get_contents($pfad), "@include('foodalchemist::partials.agent-mount')");
        if ($treffer !== 1) {
            $fehlend[] = "{$view} ({$treffer}x statt 1x im Root)";
        }
    }

    expect($fehlend)->toBe([], 'Fehlendes/doppeltes agent-mount-Include: ' . implode(', ', $fehlend));
});

/**
 * Review-Fund cooking-jarvis-03: die hardcodierte Liste oben beweist nur, dass SIE SELBST
 * vollständig ist — nicht, dass sie die tatsächlich gerouteten Vollseiten trifft. Dieser Test
 * misst stattdessen live aus `routes/web.php`: jede `foodalchemist.*`-Route, deren Ziel eine
 * Livewire-Komponentenklasse ist (keine Closure/kein Controller — Presentation-Routen sind
 * öffentliche Kundenbuch-Seiten mit eigenem Layout OHNE Sidebar/Agent und fallen bewusst raus),
 * wird gerendert und auf GENAU EIN `data-voice-float-mount` geprüft. Ein künftiger Routen-Zugang
 * ohne Include fällt hier durch, ohne dass jemand die Liste oben nachpflegen muss.
 */
it('Platzierung (gemessen): JEDE geroutete FA-Livewire-Vollseite rendert data-voice-float-mount UND data-voice-tts genau 1×', function () {
    // Spec 53/F (3): `data-voice-tts` (das <audio>-Wiedergabe-Element) liegt hinter
    // `@if($aufnahmeMoeglich)` — ohne STT-Zugang bliebe der ganze Recorder-Block weg und
    // der Test würde fälschlich 0x statt 1x melden, nicht weil das Audio-Element fehlt,
    // sondern weil die Vorbedingung fehlt.
    config(['foodalchemist.stt.provider' => 'openai', 'services.openai.api_key' => 'sk-test']);

    $geprueft = [];
    $fehlendMount = [];
    $fehlendAudio = [];

    foreach (app('router')->getRoutes() as $route) {
        $name = $route->getName();
        if ($name === null || ! str_starts_with($name, 'foodalchemist.')) {
            continue;
        }
        $klasse = $route->getActionName();
        if (! is_string($klasse) || ! str_starts_with($klasse, 'Platform\\FoodAlchemist\\Livewire\\')) {
            continue; // Closure oder Controller (Dokument-/Karten-/Präsentations-Routen) — keine App-Vollseite
        }
        if (! is_subclass_of($klasse, \Livewire\Component::class)) {
            continue;
        }
        if (in_array($klasse, $geprueft, true)) {
            continue; // dieselbe Komponente über mehrere Routen (Tagesplan/Wandmonitor) — einmal reicht
        }
        $geprueft[] = $klasse;

        $html = Livewire::test($klasse)->html();
        if (substr_count($html, 'data-voice-float-mount') !== 1) {
            $fehlendMount[] = "{$klasse} ({$name}): " . substr_count($html, 'data-voice-float-mount') . 'x statt 1x';
        }
        if (substr_count($html, 'data-voice-tts') !== 1) {
            $fehlendAudio[] = "{$klasse} ({$name}): " . substr_count($html, 'data-voice-tts') . 'x statt 1x';
        }
    }

    // Riegel gegen einen wirkungslosen Test: wenn die Introspektion nichts findet, weil sich
    // z. B. das Action-Format ändert, würde der Test sonst grün lügen (nichts geprüft = nichts gefunden).
    expect($geprueft)->toHaveCount(26, 'Routen-Introspektion hat nicht 26 FA-Livewire-Vollseiten gefunden — Action-Format geändert?');
    expect($fehlendMount)->toBe([], 'Fehlendes/doppeltes agent-mount-Include (live gerendert): ' . implode(', ', $fehlendMount));
    expect($fehlendAudio)->toBe([], 'Fehlendes/doppeltes TTS-Audio-Element (live gerendert): ' . implode(', ', $fehlendAudio));
});

it('Loop: erfundener Tool-Name führt nicht zum Fatal, sondern zur Ablehnung', function () {
    // Null-Guard: `$registry->get()` liefert null — vorher lief hier ein ToolResult::error,
    // beim Umbau auf die Policy wäre daraus ein Aufruf auf null geworden.
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.gibtsnicht.SEARCH","arguments":{}}',
        '{"action":"final","text":"Das Werkzeug kenne ich nicht."}',
    ]);

    $r = app(VoiceCommandService::class)->verarbeite('Mach irgendwas Erfundenes');

    expect($r['tool_laeufe'])->toBe([])
        ->and($r['freigeschaltet'])->toBe([])
        ->and($r['runden'])->toBe(2)
        ->and($r['text'])->toBe('Das Werkzeug kenne ich nicht.');
});

/*
 * Spec 53/D — Befund 2026-09-17 (demo-Call-Log, User 7, 16.09.): 2 von 5 Läufen liefen bis
 * maxRuns=6 durch (~60 s, ~91.560 Input-Token), endeten mit `final=false` und einer LEEREN
 * Ergebnisbox — für den Nutzer nach fast einer Minute Stille „nichts ist passiert".
 */

it('MAX_RUNDEN ist 4 (vorher 6 — die 2 von 5 demo-Läufen liefen bis dahin ins Leere)', function () {
    expect((new ReflectionClass(VoiceCommandService::class))->getConstant('MAX_RUNDEN'))->toBe(4);
});

/**
 * Spec 54 (3): `FakeAiProvider::chat()` ignoriert `$options` komplett — ein Spy auf die
 * ÜBERGEBENEN Optionen ist die einzige Stelle, an der ein reales Provider-Verhalten
 * (`OpenAiService::buildMessagesWithContext()`, gated über `with_context`) NICHT selbst
 * mitgetestet werden kann, aber die STRUKTURELLE Weichenstellung dahin schon: ohne
 * `with_context: false` würde Core vor JEDE Runde eine eigene Persona + `'Zeit: '.now()`
 * (Cache-Killer) + eine plattformweite Tools-Übersicht hängen — pro Runde erneut, MAX_RUNDEN=4.
 */
it('Spec 54 (3): callWithTools schaltet Cores Kontext-Einspritzung aus — wie propose() es schon tut', function () {
    $spy = new class extends FakeAiProvider
    {
        public array $gesehen = [];

        public function chat(array $messages, array $options = []): array
        {
            $this->gesehen[] = $options;

            return ['content' => '{"action":"final","text":"ok"}', 'model' => 'fake-voice', 'usage' => []];
        }
    };
    app()->singleton(FakeAiProvider::class, fn () => $spy);

    app(AiGatewayService::class)->callWithTools('Test', ['foodalchemist.recipes.SEARCH'], 4, []);

    expect($spy->gesehen)->not->toBeEmpty();
    foreach ($spy->gesehen as $options) {
        expect($options['with_context'] ?? null)->toBeFalse()
            ->and($options['tools'] ?? null)->toBeFalse();
    }
});

/**
 * Spec 54 (1): Tier D hängt an mehreren anderen Prompt-Keys (demo.echo, gp.condition,
 * recipe.category, recipe.name_putzen) — eine eigene, unabhängige Einstellung für den
 * Voice-Loop statt eines Umbaus an Tier D selbst. `callWithTools()`s `$optionen['model']`
 * hat Vorrang; ohne gesetzten Aufrufer-`model` bleibt Tier D unverändert der Fallback.
 */
it('Spec 54 (1): callWithTools gibt ein explizit übergebenes model an den Provider weiter — Tier D bleibt der Fallback', function () {
    $spy = new class extends FakeAiProvider
    {
        public array $gesehen = [];

        public function chat(array $messages, array $options = []): array
        {
            $this->gesehen[] = $options;

            return ['content' => '{"action":"final","text":"ok"}', 'model' => 'fake-voice', 'usage' => []];
        }
    };
    app()->singleton(FakeAiProvider::class, fn () => $spy);
    config(['foodalchemist.ai.tiers.D' => 'tier-d-fallback']);

    app(AiGatewayService::class)->callWithTools('Test', ['foodalchemist.recipes.SEARCH'], 1, ['model' => 'gpt-4o-mini-2024-07-18']);
    expect($spy->gesehen[0]['model'] ?? null)->toBe('gpt-4o-mini-2024-07-18');

    $spy->gesehen = [];
    app(AiGatewayService::class)->callWithTools('Test', ['foodalchemist.recipes.SEARCH'], 1, []);
    expect($spy->gesehen[0]['model'] ?? null)->toBe('tier-d-fallback');
});

it('Spec 54 (1): VoiceCommandService reicht foodalchemist.ai.voice_model als model weiter — Default null ändert nichts', function () {
    $spy = new class extends FakeAiProvider
    {
        public array $gesehen = [];

        public function chat(array $messages, array $options = []): array
        {
            $this->gesehen[] = $options;

            return ['content' => '{"action":"final","text":"ok"}', 'model' => 'fake-voice', 'usage' => []];
        }
    };
    app()->singleton(FakeAiProvider::class, fn () => $spy);
    config(['foodalchemist.ai.tiers.D' => 'tier-d-fallback']);

    expect(config('foodalchemist.ai.voice_model'))->toBeNull();          // Default, bevor gesetzt
    app(VoiceCommandService::class)->verarbeite('Suche BBQ Sauce');
    expect($spy->gesehen[0]['model'] ?? null)->toBe('tier-d-fallback'); // unverändert ohne Konfiguration

    $spy->gesehen = [];
    config(['foodalchemist.ai.voice_model' => 'gpt-4o-mini-2024-07-18']);
    app(VoiceCommandService::class)->verarbeite('Suche BBQ Sauce');
    expect($spy->gesehen[0]['model'] ?? null)->toBe('gpt-4o-mini-2024-07-18');
});

it('Zeitbudget: callWithTools bricht ab, ohne einen Modellaufruf zu starten, wenn die Zeit schon um ist', function () {
    ($this->skript)(['{"action":"final","text":"sollte nie ankommen"}']);

    $resultat = app(AiGatewayService::class)
        ->callWithTools('Test', ['foodalchemist.recipes.SEARCH'], 6, ['zeitbudget_ms' => 0]);

    expect($resultat['text'])->toBeNull()
        ->and($resultat['runden'])->toBe(0);
});

it('Frühabbruch: gleiches Tool mit gleichen Argumenten zweimal ⇒ Loop endet mit final=false statt zu wiederholen', function () {
    FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'x', 'name' => 'X', 'status' => 'approved']);
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.recipes.SEARCH","arguments":{"q":"X"}}',
        '{"action":"tool","name":"foodalchemist.recipes.SEARCH","arguments":{"q":"X"}}',   // exakt wiederholt
        '{"action":"final","text":"sollte nie ankommen"}',
    ]);

    $r = app(VoiceCommandService::class)->verarbeite('Suche X');

    expect($r['tool_laeufe'])->toHaveCount(1)                          // der zweite, identische Aufruf lief NICHT
        ->and($r['unklar'])->toBeTrue();
});

it('Ehrliches final=false: nennt die versuchten Werkzeuge statt einer leeren Ergebnisbox', function () {
    FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'x', 'name' => 'X', 'status' => 'approved']);
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.recipes.SEARCH","arguments":{"q":"eins"}}',
        '{"action":"tool","name":"foodalchemist.recipes.SEARCH","arguments":{"q":"zwei"}}',
        '{"action":"tool","name":"foodalchemist.recipes.SEARCH","arguments":{"q":"drei"}}',
        '{"action":"tool","name":"foodalchemist.recipes.SEARCH","arguments":{"q":"vier"}}',
    ]);                                                                 // 4 Antworten = MAX_RUNDEN erschöpft, kein 'final'

    $r = app(VoiceCommandService::class)->verarbeite('Mach irgendwas Unklares');

    expect($r['unklar'])->toBeTrue()
        ->and($r['text'])->toContain('foodalchemist.recipes.SEARCH')
        ->and($r['runden'])->toBe(4);
});

it('Tool-Ergebnis-Kappung: eine grosse Tool-Antwort wächst den Prompt nicht unbegrenzt', function () {
    $ki = app(AiGatewayService::class);
    $methode = (new ReflectionClass($ki))->getMethod('kappeToolErgebnis');
    $methode->setAccessible(true);

    $gross = str_repeat('x', 5000);
    $gekappt = $methode->invoke($ki, $gross);

    expect(mb_strlen($gekappt))->toBeLessThan(2100)
        ->and($gekappt)->toContain('gekürzt')
        ->and($methode->invoke($ki, 'kurz'))->toBe('kurz');            // unter der Grenze unverändert
});

it('GL-07: die drei neuen Planungs-Vorschlags-Tools sind für den Sprach-Loop erreichbar', function () {
    $reg = app(ToolRegistry::class);
    foreach ([
        'foodalchemist.planung_vorschlag.POST',
        'foodalchemist.anreicherung_vorschlag.POST',
        'foodalchemist.planung_kaskade.LETZTE',
    ] as $name) {
        $tool = $reg->get($name);
        expect($tool)->not->toBeNull("Tool {$name} nicht registriert");
        expect(VoiceCommandService::darfNutzen($name, $tool))->toBeTrue("{$name} sollte erreichbar sein");
        expect(in_array($name, VoiceCommandService::TOOLS, true))->toBeTrue("{$name} sollte im Warmstart stehen");
    }
});

it('GL-07: die echten Schreiber planung_session.POST und planung_kaskade.START bleiben gesperrt', function () {
    $reg = app(ToolRegistry::class);
    foreach (['foodalchemist.planung_session.POST', 'foodalchemist.planung_kaskade.START'] as $name) {
        $tool = $reg->get($name);
        expect($tool)->not->toBeNull("Tool {$name} nicht registriert");
        expect(VoiceCommandService::darfNutzen($name, $tool))->toBeFalse("{$name} sollte GESPERRT sein");
    }
});

/*
 * Spec 53 / Paket F — Agenten-Modus. AUTO_ERLAUBT ist eine explizite Liste (kein Namensmuster,
 * Memory feedback_agent_tool_freigabe_nach_eigenschaft) — dieser Test pinnt ihren Inhalt, damit
 * eine künftige Erweiterung eine BEWUSSTE Code-Änderung braucht, kein stilles Reinrutschen.
 */
it('AUTO_ERLAUBT (auto_sicher) enthält genau die drei geprüft-reversiblen Proposal-Typen', function () {
    expect(VoiceCommandService::AUTO_ERLAUBT)->toBe(['planung_start', 'anreicherung', 'speisen_klasse']);
});

it('Modus nur_lesen sperrt Proposal-Tools STRUKTURELL (die Policy lehnt ab, der Loop läuft weiter statt final)', function () {
    $hg = FoodAlchemistDishMainGroup::create(['code' => 'HGF', 'label' => 'Hauptgang F']);
    $klasse = FoodAlchemistDishClass::create(['dish_main_group_id' => $hg->id, 'code' => 'HGF_X', 'label' => 'X', 'diet_form' => 'fleisch']);
    $vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'nl1', 'name' => 'HG: Filet', 'status' => 'draft',
        'is_sales_recipe' => true, 'dish_class_id' => $klasse->id,
    ]);
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.recipe_klasse.POST","arguments":{"recipe_id":' . $vk->id . '}}',
        '{"action":"final","text":"Das darf ich in diesem Modus nicht vorschlagen."}',
    ]);

    $r = app(VoiceCommandService::class)->verarbeite('Klassifiziere das Filet', null, 'nur_lesen');

    expect($r['tool_laeufe'])->toBe([])                                  // Tool wurde NIE ausgeführt
        ->and($r['freigeschaltet'])->toBe([])
        ->and($r['proposals'])->toBe([])
        ->and($r['text'])->toBe('Das darf ich in diesem Modus nicht vorschlagen.');
});

it('Review-Fix: nur_lesen sperrt die 4 Vorschlags-Tools, LÄSST aber planung_kaskade.LETZTE (reines Lesen) durch', function () {
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.planung_kaskade.LETZTE","arguments":{}}',
        '{"action":"final","text":"Keine laufende Generierung gefunden."}',
    ]);

    $r = app(VoiceCommandService::class)->verarbeite('Wie weit ist die Generierung?', null, 'nur_lesen');

    expect($r['tool_laeufe'])->toHaveCount(1)
        ->and($r['tool_laeufe'][0]['name'])->toBe('foodalchemist.planung_kaskade.LETZTE')
        ->and($r['tool_laeufe'][0]['success'])->toBeTrue()
        ->and($r['text'])->toBe('Keine laufende Generierung gefunden.');
});

it('AUTO_SICHER_DIREKT_TOOLS enthält genau die drei explizit freigegebenen Tool-Namen', function () {
    expect(VoiceCommandService::AUTO_SICHER_DIREKT_TOOLS)->toBe([
        'foodalchemist.recipes.DUPLICATE', 'foodalchemist.recipes.RECOMPUTE', 'foodalchemist.recipes.ENRICH',
    ]);
});

it('Paket F (1b) auto_sicher: ein AUTO_SICHER_DIREKT_TOOLS-Tool läuft wirklich, OHNE Vorschlag', function () {
    $rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'rc1', 'name' => 'Basis: Fond', 'status' => 'approved',
    ]);
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.recipes.RECOMPUTE","arguments":{"id":' . $rezept->id . '}}',
        '{"action":"final","text":"Neu berechnet."}',
    ]);

    $r = app(VoiceCommandService::class)->verarbeite('Berechne das Rezept neu', null, 'auto_sicher');

    expect($r['tool_laeufe'])->toHaveCount(1)
        ->and($r['tool_laeufe'][0]['success'])->toBeTrue()
        ->and($r['tool_laeufe'][0]['data']['recomputed'] ?? null)->toBeTrue()   // ECHT ausgeführt, kein Schreibvorschlag
        ->and($r['proposals'])->toBe([]);
});

/**
 * Live-Bruch Dominique (2026-09-18): der Interceptor hatte KEINE Modul-Grenze — er fing JEDEN
 * Aufruf ab, auch `tool_registry.SEARCH`/`.GET` (Core, kein FA-Tool, also nie `read_only=true`
 * in FA-Metadaten). Der Agent verlor dadurch die Fähigkeit, überhaupt ein Werkzeug zu FINDEN:
 * jede Registry-Suche wurde zu einer sinnlosen Schreib-Karte „SEARCH: tool_registry.SEARCH —
 * bitte bestätigen", das Ergebnis blieb bei „0 Runde(n) · 0 Tool-Aufruf(e)" stehen.
 */
it('Review-Fix: der Interceptor fasst NUR foodalchemist.*-Tools an — tool_registry.SEARCH läuft im Modus fragen normal durch', function () {
    ($this->skript)([
        '{"action":"tool","name":"tool_registry.SEARCH","arguments":{"query":"foodbook kapitel","name_glob":"foodalchemist.*"}}',
        '{"action":"final","text":"Gefunden."}',
    ]);

    $r = app(VoiceCommandService::class)->verarbeite('Suche ein Werkzeug für Foodbook-Kapitel', null, 'fragen');

    expect($r['tool_laeufe'])->toHaveCount(1)
        ->and($r['tool_laeufe'][0]['name'])->toBe('tool_registry.SEARCH')
        ->and($r['tool_laeufe'][0]['success'])->toBeTrue()               // ECHT ausgeführt, kein Abfangen
        ->and($r['proposals'])->toBe([]);                                // KEINE Schreib-Karte für eine Suche
});

it('Review-Fix: foodalchemist.gps.POST bleibt weiterhin eine Karte — die Modul-Grenze schwächt den Schreibschutz nicht ab', function () {
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.gps.POST","arguments":{"hauptzutat":"Zander"}}',
        '{"action":"final","text":"Vorschlag — bitte bestätigen."}',
    ]);

    $r = app(VoiceCommandService::class)->verarbeite('Lege ein Grundprodukt an', null, 'fragen');

    expect($r['proposals'])->toHaveCount(1)
        ->and($r['proposals'][0]['type'])->toBe('schreibaktion')
        ->and($r['proposals'][0]['tool'])->toBe('foodalchemist.gps.POST');
});

it('ui.NAVIGATE/ui.OPEN sind foodalchemist.*-Tools MIT read_only=true — die Modul-Grenze ändert an ihrem Verhalten nichts (gemessen, nicht vermutet)', function () {
    expect((new \Platform\FoodAlchemist\Tools\UiNavigateTool())->getMetadata()['read_only'] ?? null)->toBeTrue()
        ->and((new \Platform\FoodAlchemist\Tools\UiOpenTool())->getMetadata()['read_only'] ?? null)->toBeTrue();
});

/**
 * Spec 54 (2): eine zweite LLM-Runde NUR für den Schlusssatz kostet ~8-10 s Latenz für nichts —
 * bei einem erfolgreichen `ui.NAVIGATE` steht mit `label` (aus dem Katalog) bereits alles im
 * Tool-Ergebnis, was ein kurzer, korrekter Satz braucht. Nur EINE gescriptete Antwort: würde der
 * Loop trotzdem eine zweite Runde einlegen, träfe er auf dasselbe Tool mit denselben Argumenten
 * (Frühabbruch-Dedup, `runden` bliebe zwar 2, aber `text` bliebe `null` → „Kein passendes
 * Werkzeug…“) — die Assertion auf `runden === 1` UND den echten Antworttext unterscheidet beide Fälle.
 */
it('Spec 54 (2): erfolgreiches ui.NAVIGATE beantwortet sich SELBST — keine zweite LLM-Runde für den Schlusssatz', function () {
    ($this->skript)(['{"action":"tool","name":"foodalchemist.ui.NAVIGATE","arguments":{"route_key":"recipes"}}']);

    $resultat = app(VoiceCommandService::class)->verarbeite('Öffne die Basisrezepte');

    expect($resultat['runden'])->toBe(1)
        ->and($resultat['tool_laeufe'])->toHaveCount(1)
        ->and($resultat['unklar'])->toBeFalse()
        ->and($resultat['text'])->toBe('Öffne Basisrezepte.');
});

it('Spec 54 (2): ui.OPEN bleibt bewusst AUSSEN vor — sein Tool-Ergebnis trägt kein Label für einen freundlichen Satz', function () {
    expect(VoiceCommandService::fruehesFinale(
        'foodalchemist.ui.OPEN',
        ['type' => 'recipe', 'id' => 42],
        \Platform\Core\Contracts\ToolResult::success(['open' => ['type' => 'recipe', 'id' => 42]]),
    ))->toBeNull();
});

/**
 * Live-Bruch Dominique (2026-09-18, Punkt d): eine Endlos-Schleife entstand, weil das Modell auf
 * Rauschen/unklares Gemurmel trotzdem eine muntere Füllantwort gab ("Alles klar, ich warte …"),
 * die vorgelesen wurde und den nächsten automatischen Zyklus auslöste. Die clientseitige VAD-
 * Schwelle (Stille) ist die primäre Sicherung — dieser Systemprompt-Hinweis die zweite, für den
 * Fall, dass ETWAS akustisch als Sprache durchkam.
 */
it('Systemprompt enthält die Rauschen/"wartet"-Regel — spy auf die tatsächlich gesendete System-Message', function () {
    // Das Spy-OBJEKT wird VORHER erzeugt und das Singleton liefert nur diese eine, feste
    // Instanz zurück — kein Referenz-Einfang nötig (eine Arrow Function erfasst äussere
    // Variablen ohnehin bei WERT, das hätte eine `&$x`-Property-Promotion nur auf die eigene
    // Kopie der Arrow Function bezogen, nicht auf die äussere Testvariable).
    $spy = new class extends FakeAiProvider
    {
        public ?array $gesendet = null;

        public function chat(array $messages, array $options = []): array
        {
            $this->gesendet = $messages;

            return ['content' => '{"action":"final","text":"ok"}', 'model' => 'fake-voice', 'usage' => []];
        }
    };
    app()->singleton(FakeAiProvider::class, fn () => $spy);

    app(VoiceCommandService::class)->verarbeite('Suche BBQ Sauce');

    $systemMessage = collect($spy->gesendet)->firstWhere('role', 'system')['content'] ?? '';
    expect($systemMessage)->toContain('RAUSCHEN')
        ->and($systemMessage)->toContain('wartet')
        ->and($systemMessage)->not->toContain('"nicht stoppbar"');   // kein " zurück in den Prompt-Text
});

it('VoiceModal spricht ein reines "wartet"-Signal NICHT vor — sonst hätte sich der Agent selbst wieder angehört', function () {
    config(['foodalchemist.tts.provider' => 'fake']);
    app(TeamSettingsService::class)->update($this->rootTeam, ['voice_tts_vorlesen' => true]);
    ($this->skript)(['{"action":"final","text":"wartet"}']);

    Livewire::test(\Platform\FoodAlchemist\Livewire\VoiceModal::class)
        ->call('verarbeiteText', 'ghsjdf mmpf')
        ->assertNotDispatched('voice-tts-bereit');
});
