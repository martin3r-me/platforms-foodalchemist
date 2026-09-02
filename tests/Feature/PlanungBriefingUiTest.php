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
        ->set('diktatZiel', 'eingabe.rezept.brief')
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
    $tab = file_get_contents(__DIR__ . '/../../resources/views/livewire/planung/partials/erstellen-tab.blade.php');
    $diktat = file_get_contents(__DIR__ . '/../../resources/views/livewire/planung/partials/diktat.blade.php');

    // Die Knöpfe liegen seit dem Umzug im GETEILTEN Baustein (ein Recorder statt sechs
    // Kopien) — der Erstell-Tab bindet ihn nur ein.
    expect($tab)->toContain("partials.diktat")
        ->and($tab)->toContain('data-planung-leitplanken-befund');
    expect($diktat)->toContain('data-planung-diktat')
        ->and($diktat)->toContain('data-planung-leitplanken-vorschlag')
        ->and($diktat)->toContain("wire:click=\"leitplankenAusBriefing('{{ \$mitLeitplanken }}')\"");
});

/*
 * Das Diktat sitzt an ALLEN Briefing-Feldern der Planungsstelle, nicht nur an den drei
 * Erstell-Scopes (Dominique 2026-09-02: „in der planungsstelle" — der Concept-Tab und die
 * fünf Ausgabeformen hatten keinen Knopf). Das Ziel kommt aus dem Blade und damit vom
 * CLIENT — deshalb läuft es gegen eine Whitelist.
 */
it('Diktat trifft jedes Briefing-Feld der Planungsstelle', function () {
    config(['foodalchemist.stt.provider' => 'fake', 'foodalchemist.stt.fake_text' => 'Empfang für 80 Gäste']);

    foreach (['fbBrief', 'skBrief', 'spBrief', 'offerBrief', 'fmtBrief'] as $feld) {
        $c = Livewire::test(Index::class)
            ->set('diktatZiel', $feld)
            ->set('briefAudio', UploadedFile::fake()->create('d.webm', 1, 'audio/webm'));

        expect($c->get($feld))->toBe('Empfang für 80 Gäste', "Diktat erreicht {$feld} nicht");
    }
});

it('Diktat erreicht auch den Concept-Tab — der hatte als einziger Scope keinen Knopf', function () {
    config(['foodalchemist.stt.provider' => 'fake', 'foodalchemist.stt.fake_text' => 'Sommer-Menü, vier Gänge']);

    $c = Livewire::test(Index::class)
        ->set('diktatZiel', 'eingabe.concept.brief')
        ->set('briefAudio', UploadedFile::fake()->create('d.webm', 1, 'audio/webm'));

    expect($c->get('eingabe.concept.brief'))->toBe('Sommer-Menü, vier Gänge');
});

it('Whitelist: ein fremdes Diktat-Ziel schreibt NICHTS und meldet es', function () {
    config(['foodalchemist.stt.provider' => 'fake', 'foodalchemist.stt.fake_text' => 'Text']);

    // `diktatZiel` ist eine Livewire-Property und damit vom Client setzbar. Ohne Whitelist
    // liesse sich damit jede beliebige Property der Komponente überschreiben.
    $c = Livewire::test(Index::class)
        ->set('form.title', 'Unberührt')
        ->set('diktatZiel', 'form.title')
        ->set('briefAudio', UploadedFile::fake()->create('d.webm', 1, 'audio/webm'));

    expect($c->get('form.title'))->toBe('Unberührt')
        ->and($c->get('fehler'))->toContain('Diktat-Ziel');
});

it('Die Whitelist deckt genau die Felder ab, die im Blade auch einen Knopf haben', function () {
    $index = file_get_contents(__DIR__ . '/../../resources/views/livewire/planung/index.blade.php');
    $tab = file_get_contents(__DIR__ . '/../../resources/views/livewire/planung/partials/erstellen-tab.blade.php');

    // Die fünf Ausgabeformen + Concept stehen literal im Blade; rezept/gericht kommen
    // dynamisch über $scope aus dem Erstell-Tab.
    foreach (['fbBrief', 'skBrief', 'spBrief', 'offerBrief', 'fmtBrief', 'eingabe.concept.brief'] as $ziel) {
        // toContain ist VARIADISCH (mehrere Nadeln) — ein zweites Argument wäre ein
        // weiterer Suchbegriff, keine Meldung. Darum die Erwartung nackt und der
        // Kontext im Kommentar. (Selbst hineingelaufen, 2026-09-02.)
        expect($index)->toContain("'ziel' => '{$ziel}'");
        expect(Index::DIKTAT_ZIELE)->toContain($ziel);
    }
    expect($tab)->toContain("'ziel' => 'eingabe.' . \$scope . '.brief'");
    // Und der Baustein muss NACH dem schliessenden </textarea> stehen. Mitten im
    // ÖFFNENDEN Tag hat er die Ein-Wurzel-Regel von Livewire gebrochen („Multiple root
    // elements") — mehrzeilige textarea-Tags sind die Falle, selbst hineingelaufen
    // 2026-09-02. Zeilenweise statt per Regex: lesbar und ohne Escape-Fallen.
    $zeilen = explode("\n", $index);
    foreach ($zeilen as $nr => $zeile) {
        if (! str_contains($zeile, 'partials.diktat')) {
            continue;
        }
        $davor = $zeilen[$nr - 1] ?? '';
        expect(str_contains($davor, '</textarea>'))->toBeTrue(
            'Diktat-Include in Zeile ' . ($nr + 1) . ' steht nicht direkt nach </textarea>');
    }
    expect(Index::DIKTAT_ZIELE)->toContain('eingabe.rezept.brief')->toContain('eingabe.gericht.brief');
});
