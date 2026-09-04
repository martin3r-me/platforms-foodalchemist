<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
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
    // ui.ROUTES steht NICHT im Basiskatalog, ist aber lesend → die Policy lässt es zu.
    expect(in_array('foodalchemist.ui.ROUTES', VoiceCommandService::TOOLS, true))->toBeFalse();
    expect(app(ToolRegistry::class)->get('foodalchemist.ui.ROUTES')?->getMetadata()['read_only'] ?? null)->toBeTrue();

    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.ui.ROUTES","arguments":{}}',
        '{"action":"final","text":"Hier sind die Bereiche."}',
    ]);

    $r = app(VoiceCommandService::class)->verarbeite('Welche Bereiche gibt es?');

    expect($r['tool_laeufe'][0]['name'])->toBe('foodalchemist.ui.ROUTES')
        ->and($r['tool_laeufe'][0]['success'])->toBeTrue()
        ->and($r['freigeschaltet'])->toBe(['foodalchemist.ui.ROUTES'])
        ->and($r['text'])->toBe('Hier sind die Bereiche.');

    // Das Audit muss zeigen, WORÜBER der Werkzeugkasten gewachsen ist.
    $summary = DB::table('foodalchemist_ai_call_log')->where('feature', 'voice.command')->latest('id')->value('response_summary');
    expect($summary)->toContain('ui.ROUTES');
});

it('Loop: schreibendes Tool wird abgelehnt, der Loop läuft weiter statt abzubrechen', function () {
    ($this->skript)([
        '{"action":"tool","name":"foodalchemist.gps.POST","arguments":{"hauptzutat":"Zander"}}',
        '{"action":"final","text":"Das darf ich nicht — ich kann es vorschlagen."}',
    ]);

    $r = app(VoiceCommandService::class)->verarbeite('Lege ein Grundprodukt Zander an');

    expect($r['tool_laeufe'])->toBe([])                              // NICHTS ausgeführt
        ->and($r['freigeschaltet'])->toBe([])
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
    // Der Warmstart darf davon ein Zehntel kosten — mehr wäre die Rückkehr zum Vollsortiment.
    expect($zeichen)->toBeLessThan(8000, "Basiskatalog auf {$zeichen} Zeichen gewachsen");
});

it('Platzierung: der Sprach-Agent hängt global in der Sidebar — Knopf und genau EIN Mount', function () {
    $html = Livewire::test(\Platform\FoodAlchemist\Livewire\Sidebar::class)->html();

    expect($html)->toContain('data-voice-global')                    // der Knopf auf Betriebs-Wähler-Ebene
        ->and($html)->toContain('data-voice');                       // das gemountete Modal

    // Der Mount liegt AUSSERHALB des x-show="!collapsed"-Blocks: x-show setzt display:none
    // und würde ein geöffnetes Modal mitverstecken.
    $mount = mb_strpos($html, 'data-voice');
    $xshow = mb_strpos($html, 'x-show="!collapsed" class="px-2"');
    expect($mount)->toBeLessThan($xshow, 'Modal-Mount liegt im x-show-Block');
});

it('Platzierung: die Rezept-Seite mountet KEINEN zweiten Sprach-Agenten', function () {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/livewire/recipes/browser.blade.php');

    // Eine Modal-Identität (`voice-modal`) darf nur einmal im DOM liegen — sonst öffnen
    // zwei Instanzen gleichzeitig und der Upload landet in der falschen.
    expect($blade)->not->toContain('voice-modal');
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
