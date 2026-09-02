<?php

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Planung\Index;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Stt\SttServiceContract;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Phase C2, zweite Ebene: Briefing → Leitplanken IN DER OBERFLÄCHE. Bis hierher war die
 * Brücke nur per MCP erreichbar — „ein Produkt sollte das in sich über die UI lösen können"
 * (Dominique 2026-09-02). Diese Tests pinnen genau das, was per MCP nicht geprüft war:
 * dass der Vorschlag in den Reglern DIESES Tabs landet, formtreu, und dass nichts still
 * verschwindet.
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
});

it('füllt die Leitplanken DIESES Tabs — und lässt die anderen Tabs unberührt', function () {
    ($this->stub)(['leitplanken' => ['occasion' => 'dinner', 'serviceform' => 'tellerservice', 'level' => 'gehoben']]);

    $c = Livewire::test(Index::class)
        ->set('eingabe.gericht.brief', 'Abendessen für 40 Gäste, gehoben, am Platz serviert')
        ->call('leitplankenAusBriefing', 'gericht');

    expect($c->get('regler.gericht.occasion'))->toBe('dinner')
        ->and($c->get('regler.gericht.serviceform'))->toBe('tellerservice')
        ->and($c->get('regler.gericht.level'))->toBe('gehoben');

    // Tab-Unabhängigkeit (Kaskaden-Regel: am Go zählt NUR der Start-Tab).
    expect($c->get('regler.rezept.occasion'))->toBe(Index::REGLER_DEFAULT['occasion']);

    $befund = $c->get('leitplankenBefund');
    expect($befund['scope'])->toBe('gericht')
        ->and($befund['gesetzt'])->toContain('occasion');
});

it('Formtreue: ein Multi-Regler bleibt Array, auch wenn die KI einen String liefert', function () {
    // `diaet_hart` ist ein MULTI_REGLER — ein String darin würde reglerPill() zerlegen.
    ($this->stub)(['leitplanken' => ['diaet_hart' => 'vegan']]);

    $c = Livewire::test(Index::class)
        ->set('eingabe.gericht.brief', 'rein pflanzlich')
        ->call('leitplankenAusBriefing', 'gericht');

    expect($c->get('regler.gericht.diaet_hart'))->toBe(['vegan']);
});

it('meldet erfundene Werte als verworfen und fremde Felder als »nicht auf diesem Tab«', function () {
    ($this->stub)(['leitplanken' => [
        'occasion' => 'Gala',                                        // erfunden → verworfen
        'serviceform' => 'buffet',                                   // gültig  → gesetzt
        'menue_quote_vegan_pct' => 30,                               // gültiger Sitzungs-Param, kein Regler
    ]]);

    $c = Livewire::test(Index::class)
        ->set('eingabe.gericht.brief', 'Gala-Buffet, 30 % vegan')
        ->call('leitplankenAusBriefing', 'gericht');

    $b = $c->get('leitplankenBefund');
    expect($b['gesetzt'])->toBe(['serviceform'])
        ->and($b['verworfen'])->toContain('occasion=Gala')
        ->and($b['ignoriert'])->toContain('menue_quote_vegan_pct')
        ->and($c->get('regler.gericht.serviceform'))->toBe('buffet');
});

it('offene Punkte werden zur Rückfrage, nicht zur Annahme', function () {
    ($this->stub)(['leitplanken' => ['occasion' => 'lunch'], 'unklar' => ['Wie viele Gäste?', 'Budget pro Person?']]);

    $c = Livewire::test(Index::class)
        ->set('eingabe.gericht.brief', 'Mittagessen im Sommer')
        ->call('leitplankenAusBriefing', 'gericht');

    // Nur die Property, nicht das Markup: `Index` rendert den Erstell-Tab erst im
    // Editor-Zustand mit Sitzung — ohne den kommt die Landing-Ansicht. Dass das Panel
    // im Partial existiert, pinnt der letzte Test dieser Datei.
    expect($c->get('leitplankenBefund')['unklar'])->toBe(['Wie viele Gäste?', 'Budget pro Person?'])
        ->and($c->get('leitplankenBefund')['gesetzt'])->toBe(['occasion']);
});

it('ohne Briefing kein Call — Fehler statt leerem KI-Aufruf', function () {
    ($this->stub)(['leitplanken' => ['occasion' => 'dinner']]);

    $c = Livewire::test(Index::class)
        ->set('eingabe.gericht.brief', '   ')
        ->call('leitplankenAusBriefing', 'gericht');

    expect($c->get('fehler'))->toContain('erst ein Briefing')
        ->and($c->get('leitplankenBefund'))->toBeNull()
        ->and($c->get('regler.gericht.occasion'))->toBe(Index::REGLER_DEFAULT['occasion']);
});

it('Diktat hängt an und überschreibt ein bestehendes Briefing NIE', function () {
    config(['foodalchemist.stt.provider' => 'fake', 'foodalchemist.stt.fake_text' => 'und bitte glutenfrei']);
    expect(app(SttServiceContract::class)->transcribe('BLOB'))->toBe('und bitte glutenfrei');

    // Echter Upload-Pfad: `set` löst den updated-Hook aus, der Hook delegiert.
    $c = Livewire::test(Index::class)
        ->set('eingabe.rezept.brief', 'Tomatensauce, klassisch')
        ->set('diktatScope', 'rezept')
        ->set('briefAudio', UploadedFile::fake()->create('diktat.webm', 1, 'audio/webm'));

    // Angehängt, nicht ersetzt — ein überschriebenes Briefing wäre nicht wiederherstellbar.
    expect($c->get('eingabe.rezept.brief'))->toBe('Tomatensauce, klassisch und bitte glutenfrei')
        ->and($c->get('briefAudio'))->toBeNull();                    // Blob nach der Übernahme freigegeben
});

it('Diktat ohne Blob fasst nichts an', function () {
    $c = Livewire::test(Index::class)
        ->set('eingabe.rezept.brief', 'Tomatensauce, klassisch')
        ->call('briefDiktatUebernehmen');

    expect($c->get('eingabe.rezept.brief'))->toBe('Tomatensauce, klassisch')
        ->and($c->get('fehler'))->toBeNull();
});

it('Die UI-Fläche trägt beide Knöpfe — Diktat und Leitplanken', function () {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/livewire/planung/partials/erstellen-tab.blade.php');

    expect($blade)->toContain('data-planung-diktat')
        ->and($blade)->toContain('data-planung-leitplanken-vorschlag')
        ->and($blade)->toContain('data-planung-leitplanken-befund')
        ->and($blade)->toContain("wire:click=\"leitplankenAusBriefing('{{ \$scope }}')\"");
});
