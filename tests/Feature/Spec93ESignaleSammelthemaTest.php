<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendBeleg;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\TrendEingangService;
use Platform\FoodAlchemist\Services\Trends\TrendAutomatikService;
use Platform\FoodAlchemist\Support\SichererAbruf;
use Platform\FoodAlchemist\Support\TrendAutomatikRegeln;
use Platform\FoodAlchemist\Support\TrendSammelthema;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 93 E · Studien und Trendlisten in Einzelsignale zerlegen (signale[] beim Einreichen) und Sammelthemen wie
 * „Trendprognose 2027“ im Code ablehnen. Ein Beitrag zählt je Thema nur einmal. Testdaten „TEST-93“.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Inhaber TEST-93');
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    Http::preventStrayRequests();
    app()->instance(SichererAbruf::class, new SichererAbruf(fn () => ['93.184.216.34']));
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $this->reg = app(ToolRegistry::class);
    $this->eingang = app(TrendEingangService::class);
    // Thema je Signal aus dem Titel: so landen Signale einer Studie in verschiedenen Themen wie im echten Erschließen
    $this->stub = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public bool $werfen = false;

        public ?string $festesThema = null;

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;
            if ($this->werfen) {
                throw new \RuntimeException('Plattform-Provider darf im MCP-Weg nicht gerufen werden');
            }
            $text = json_encode($messages, JSON_UNESCAPED_UNICODE);
            $thema = $this->festesThema ?? (str_contains($text, 'Bean Boom') ? 'Hülsenfrucht' : (str_contains($text, 'Ferment Forward') ? 'Fermentation' : 'Bitternote'));
            $werte = ['relevant' => true, 'zusammenfassung' => 'TEST-93 Kurzfassung.', 'thema' => $thema, 'kategorie' => 'food',
                'sparten' => [], 'tiefe' => 'beduerfnis', 'tiefe_begruendung' => 'TEST-93', 'passt_zu_trend_id' => null, 'begruendung' => 'TEST-93'];

            return ['content' => json_encode(['werte' => $werte, 'confidence' => 0.9, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE),
                'usage' => [], 'model' => 'stub-1', 'tool_calls' => null];
        }
    };
    app()->instance(LLMProviderContract::class, $this->stub);
    $this->quelle = FoodAlchemistTrendQuelle::create(['team_id' => $this->rootTeam->id, 'name' => 'TEST-93 Studie', 'url' => 'https://studie.beispiel.test/',
        'art' => 'marktforschung', 'feed_typ' => 'manuell', 'abruf_weg' => 'mcp', 'rhythmus' => 'aus', 'aktiv' => true]);
    $this->beitrag = ['url' => 'https://studie.beispiel.test/trends-2027?utm_source=x', 'titel' => 'TEST-93 Trends 2027', 'quelle_id' => $this->quelle->id];
    $this->signale = [['titel' => 'Bean Boom', 'teaser' => 'Hülsenfrüchte als Hauptkomponente.'],
        ['titel' => 'Ferment Forward', 'teaser' => 'Fermentation im Alltag.'], ['titel' => 'Bitter Done Better', 'teaser' => 'Bitternoten.']];
});

it('signale: je Signal ein Eintrag mit eigenem Thema; gleiche Studien-URL mit #signal=, Titel „Signal — Beitrag“, Dublette = URL + Signal', function () {
    $r = $this->eingang->signaleEinreichen($this->rootTeam, $this->beitrag, $this->signale, 'hand', $this->user->id);

    expect(array_column($r, 'ergebnis'))->toBe(['neu', 'neu', 'neu'])->and($this->stub->calls)->toBe(3);
    $e = FoodAlchemistTrendEingang::where('team_id', $this->rootTeam->id)->orderBy('id')->get();
    expect($e)->toHaveCount(3)
        ->and($e->pluck('titel')->all())->toBe(['Bean Boom — TEST-93 Trends 2027', 'Ferment Forward — TEST-93 Trends 2027', 'Bitter Done Better — TEST-93 Trends 2027'])
        ->and($e[0]->url)->toBe('https://studie.beispiel.test/trends-2027#signal=bean-boom')   // Tracking-Parameter weg, Fragment dran
        ->and($e->pluck('url_hash')->unique())->toHaveCount(3)
        ->and($e->pluck('status')->unique()->all())->toBe(['gebuendelt'])
        ->and($e->pluck('thema')->all())->toBe(['Hülsenfrucht', 'Fermentation', 'Bitternote'])
        ->and($e->pluck('thema_inspiration_id')->unique())->toHaveCount(3);
    // jeder Beleg trägt seinen eigenen Link — das Bündeln (Beleg je URL im Thema) verwechselt die Signale nicht
    expect(FoodAlchemistTrendBeleg::where('fundstueck', false)->pluck('url')->unique())->toHaveCount(3);

    // erneut: Dublette je Signal (auch mit anderer Schreibweise des Signal-Titels), nichts Neues, kein Call
    $nochmal = $this->eingang->signaleEinreichen($this->rootTeam, $this->beitrag, [['titel' => '  bean boom! '], $this->signale[1]], 'hand', $this->user->id);
    expect(array_column($nochmal, 'ergebnis'))->toBe(['dublette', 'dublette'])->and($this->stub->calls)->toBe(3)
        ->and(FoodAlchemistTrendEingang::count())->toBe(3);

    // die Studie ohne Signale ist ein anderer Eintrag (eigene URL ohne Fragment) — kein Konflikt mit den Signalen
    $ganz = $this->eingang->einreichen($this->rootTeam, $this->beitrag, 'hand', $this->user->id);
    expect($ganz['ergebnis'])->toBe('neu')->and($ganz['eintrag']->url)->toBe('https://studie.beispiel.test/trends-2027?utm_source=x');
});

it('signale: höchstens 12 (Config), ein Signal ohne Titel bricht vor dem Anlegen ab, doppeltes Signal zählt einmal', function () {
    config(['foodalchemist.trends.quellen.max_signale_je_beitrag' => 2]);
    expect(fn () => $this->eingang->signaleEinreichen($this->rootTeam, $this->beitrag, $this->signale, 'hand', $this->user->id))
        ->toThrow(\RuntimeException::class, 'Höchstens 2 Signale je Beitrag (3 übergeben).');
    config(['foodalchemist.trends.quellen.max_signale_je_beitrag' => 12]);
    expect(fn () => $this->eingang->signaleEinreichen($this->rootTeam, $this->beitrag, [$this->signale[0], ['titel' => ' '], $this->signale[2]], 'hand', $this->user->id))
        ->toThrow(\RuntimeException::class, 'Jedes Signal braucht einen Titel.');
    expect(FoodAlchemistTrendEingang::count())->toBe(0)->and($this->stub->calls)->toBe(0);

    $r = $this->eingang->signaleEinreichen($this->rootTeam, $this->beitrag, [$this->signale[0], ['titel' => 'BEAN-BOOM']], 'hand', $this->user->id);
    expect($r)->toHaveCount(1)->and(FoodAlchemistTrendEingang::count())->toBe(1);
});

it('Automatik: zwei Signale derselben Studie im selben Thema zählen als eine Sichtung und eine Quelle; ein zweiter Beitrag zählt dazu', function () {
    $this->stub->festesThema = 'Fermentation';
    $this->eingang->signaleEinreichen($this->rootTeam, $this->beitrag, [$this->signale[1], ['titel' => 'Koji Everywhere']], 'hand', $this->user->id);
    $thema = FoodAlchemistTrendInspiration::where('herkunft', 'automatisch')->sole();
    expect($thema->quellen()->count())->toBe(2);   // zwei Belege (eigene Links) …
    $svc = app(TrendAutomatikService::class);
    $r = TrendAutomatikRegeln::startwerte();
    $k = $svc->kennzahlen($this->rootTeam, $thema, $r);
    expect($k['sichtungen'])->toBe(1)->and($k['quellen_fenster'])->toBe(1)->and($k['quellenarten'])->toBe(['marktforschung']);   // … aber ein Beitrag

    $this->eingang->einreichen($this->rootTeam, ['url' => 'https://presse.beispiel.test/koji', 'titel' => 'TEST-93 Koji in der Kantine'], 'hand', $this->user->id);
    $k = $svc->kennzahlen($this->rootTeam, $thema->refresh(), $r);
    expect($k['sichtungen'])->toBe(2)->and($k['quellen_fenster'])->toBe(2);
});

it('Abruf danach: derselbe Artikel per Feed (auch mit Tracking/Fragment) → genau 1 neuer Eintrag ohne Fragment; die Signal-Einträge bleiben unberührt', function () {
    $this->eingang->signaleEinreichen($this->rootTeam, $this->beitrag, $this->signale, 'hand', $this->user->id);
    $vorher = FoodAlchemistTrendEingang::orderBy('id')->get()->map(fn ($e) => $e->only(['id', 'url', 'url_hash', 'status', 'thema']) + ['gesehen' => (string) $e->zuletzt_gesehen_at])->all();
    $this->travel(1)->hours();

    $a = $this->eingang->einreichen($this->rootTeam, ['url' => 'https://studie.beispiel.test/trends-2027/#kommentare', 'titel' => 'TEST-93 Trends 2027'], 'abruf');
    $b = $this->eingang->einreichen($this->rootTeam, ['url' => 'https://STUDIE.beispiel.test/trends-2027?utm_medium=feed', 'titel' => 'TEST-93 Trends 2027'], 'abruf');

    expect($a['ergebnis'])->toBe('neu')->and($b['ergebnis'])->toBe('dublette')->and($b['eintrag']->id)->toBe($a['eintrag']->id)
        ->and($a['eintrag']->url)->not->toContain('#signal')
        ->and(FoodAlchemistTrendEingang::count())->toBe(4);
    $nachher = FoodAlchemistTrendEingang::whereKeyNot($a['eintrag']->id)->orderBy('id')->get()
        ->map(fn ($e) => $e->only(['id', 'url', 'url_hash', 'status', 'thema']) + ['gesehen' => (string) $e->zuletzt_gesehen_at])->all();
    expect($nachher)->toBe($vorher);
});

it('Anzeige: Link ohne #signal= (Liste, MCP-GET, Pinnwand-Brücke); gespeichert bleibt das Fragment', function () {
    $e = $this->eingang->signaleEinreichen($this->rootTeam, $this->beitrag, [$this->signale[0]], 'hand', $this->user->id)[0]['eintrag'];

    expect($e->url)->toEndWith('#signal=bean-boom')->and($e->link())->toBe('https://studie.beispiel.test/trends-2027')
        ->and($this->eingang->alsArray($e)['url'])->toBe('https://studie.beispiel.test/trends-2027');
    $get = $this->reg->get('foodalchemist.trend_eingang.GET')->execute([], $this->mcp);
    expect($get->data['eintraege'][0]['url'])->toBe('https://studie.beispiel.test/trends-2027');
    $this->eingang->entscheiden($this->rootTeam, $e->id, 'pinnwand', null, $this->user->id);
    expect(FoodAlchemistTrendBeleg::where('fundstueck', true)->sole()->url)->toBe('https://studie.beispiel.test/trends-2027');
    // ein Link mit eigenem Fragment, das kein Signal ist, bleibt wie er ist
    expect((new FoodAlchemistTrendEingang(['url' => 'https://x.test/a#abschnitt']))->link())->toBe('https://x.test/a#abschnitt');
});

it('Automatik: 10 Signale einer Studie in EINEM Thema = 1 Beitrag/1 Quellenart; 2 Signale aus 2 Studien = 2', function () {
    $this->stub->festesThema = 'Fermentation';
    $zehn = array_map(fn ($i) => ['titel' => 'TEST-93 Signal '.$i], range(1, 10));
    $this->eingang->signaleEinreichen($this->rootTeam, $this->beitrag, $zehn, 'hand', $this->user->id);
    $thema = FoodAlchemistTrendInspiration::where('herkunft', 'automatisch')->sole();
    $svc = app(TrendAutomatikService::class);
    $r = TrendAutomatikRegeln::startwerte();
    $k = $svc->kennzahlen($this->rootTeam, $thema, $r);
    expect($thema->quellen()->count())->toBe(10)
        ->and($k['sichtungen'])->toBe(1)->and($k['sichtungen_fenster'])->toBe(1)->and($k['sichtungen_30t'])->toBe(1)
        ->and($k['quellen_fenster'])->toBe(1)->and($k['quellenarten'])->toBe(['marktforschung'])
        ->and($svc->einordnen($k, $r)['kandidat'])->toBeFalse();

    $this->eingang->signaleEinreichen($this->rootTeam, ['url' => 'https://andere-studie.beispiel.test/2027', 'titel' => 'TEST-93 Andere', 'quelle_id' => $this->quelle->id],
        [['titel' => 'TEST-93 Signal X']], 'hand', $this->user->id);
    $k = $svc->kennzahlen($this->rootTeam, $thema->refresh(), $r);
    expect($k['sichtungen'])->toBe(2)->and($k['quellen_fenster'])->toBe(2);
});

it('Sammelthema: KI liefert „Trendprognose 2027“ → fehler, kein Thema; von Hand gesetzt gilt es', function () {
    $this->stub->festesThema = 'Trendprognose 2027';
    $e = $this->eingang->einreichen($this->rootTeam, $this->beitrag, 'hand', $this->user->id)['eintrag']->refresh();
    expect($e->status)->toBe('fehler')->and($e->begruendung)->toContain('Sammelthema „Trendprognose 2027“ abgelehnt')
        ->and($e->thema_inspiration_id)->toBeNull()
        ->and(FoodAlchemistTrendInspiration::where('herkunft', 'automatisch')->count())->toBe(0);

    $this->eingang->entscheiden($this->rootTeam, $e->id, 'thema', 'Trendprognose 2027', $this->user->id);
    $this->eingang->erschliessenAnstossen($this->rootTeam, [$e->id], $this->user->id);
    expect($e->refresh()->status)->toBe('gebuendelt')->and($e->thema_quelle)->toBe('manuell')->and($e->thema)->toBe('Trendprognose 2027');
});

it('Sammelthema-Erkennung: nur Rubrik-, Füllwörter und Zahlen; ein Sachwort macht es konkret', function () {
    foreach (['Trendprognose 2027', 'Foodtrends 2026', 'Top 10 Food Trends', 'Food & Beverage Trends 2027', 'Ausblick', 'Trends', 'Ernährungstrends',
        'Global Food Forecast 2027', 'Trendreport'] as $t) {
        expect(TrendSammelthema::ist($t))->toBeTrue("„{$t}“ ist ein Sammelthema");
    }
    foreach (['Fermentation', 'Ube-Trend 2027', 'Topping', 'Reportage-Dinner', 'Hülsenfrucht', 'Trendküche', 'Bitternote', 'Snacking 2027', ''] as $t) {
        expect(TrendSammelthema::ist($t))->toBeFalse("„{$t}“ ist kein Sammelthema");
    }
});

it('MCP-Tool: signale je Artikel → je Signal ein KI-Auftrag statt Provider; mehr als 50 Einträge (Signale einzeln) abgelehnt', function () {
    $this->stub->werfen = true;
    $tool = $this->reg->get('foodalchemist.trend_eingang.POST');
    $res = $tool->execute(['quelle_id' => $this->quelle->id, 'artikel' => [$this->beitrag + ['signale' => $this->signale]]], $this->mcp);

    expect($res->success)->toBeTrue()->and($res->data['zaehler'])->toBe(['neu' => 3])
        ->and(array_column($res->data['ergebnisse'], 'signal'))->toBe(['Bean Boom', 'Ferment Forward', 'Bitter Done Better'])
        ->and($this->stub->calls)->toBe(0)
        ->and(DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->count())->toBe(3);

    $viele = array_map(fn ($i) => ['titel' => 'TEST-93 Signal '.$i], range(1, 12));
    $zuViel = $tool->execute(['artikel' => array_map(fn ($i) => ['url' => 'https://s.test/'.$i, 'titel' => 'TEST-93 '.$i, 'signale' => $viele], range(1, 5))], $this->mcp);
    expect($zuViel->success)->toBeFalse()->and($zuViel->error)->toContain('Höchstens 50 Einträge je Aufruf');
});

it('Quellenart verbraucherstudie: eigenes Vokabular, als Quelle anlegbar, zählt in trend_quellenarten als eigene Art und bestätigt', function () {
    expect(\Platform\FoodAlchemist\Support\TrendVokabular::QUELLEN['verbraucherstudie'])->toBe('Verbraucherstudie')
        ->and(\Platform\FoodAlchemist\Support\TrendVokabular::QUELLEN['befragung'])->toBe('Mitarbeiterbefragung');
    $q = $this->reg->get('foodalchemist.trend_quellen.POST')->execute(['url' => 'https://ernaehrungsreport.beispiel.test/', 'name' => 'TEST-93 Ernährungsreport',
        'art' => 'verbraucherstudie', 'abruf_weg' => 'mcp', 'rhythmus' => 'aus'], $this->mcp);
    expect($q->success)->toBeTrue()->and($q->data['quelle']['art'])->toBe('verbraucherstudie')->and($q->data['quelle']['art_label'])->toBe('Verbraucherstudie');

    $this->stub->festesThema = 'Fermentation';
    $this->eingang->einreichen($this->rootTeam, ['url' => 'https://ernaehrungsreport.beispiel.test/2026', 'titel' => 'TEST-93 Report',
        'quelle_id' => $q->data['quelle']['id']], 'hand', $this->user->id);
    $this->eingang->einreichen($this->rootTeam, ['url' => 'https://studie.beispiel.test/fermentation', 'titel' => 'TEST-93 Studie',
        'quelle_id' => $this->quelle->id], 'hand', $this->user->id);
    $thema = FoodAlchemistTrendInspiration::where('herkunft', 'automatisch')->sole();
    $k = app(TrendAutomatikService::class)->kennzahlen($this->rootTeam, $thema, TrendAutomatikRegeln::startwerte());
    expect($k['quellenarten'])->toBe(['marktforschung', 'verbraucherstudie']);

    $trend = app(\Platform\FoodAlchemist\Services\TrendService::class)->anlegen($this->rootTeam, ['name' => 'TEST-93 Fermentation',
        'beleg' => ['quelle' => 'verbraucherstudie', 'titel' => 'TEST-93 Report']]);
    // bestätigende Quelle allein = „mittel“ mit Bestätigung (eine bloße sonstige Quelle allein wäre „niedrig“); kein Befragungs-Siegel
    $b = app(\Platform\FoodAlchemist\Services\TrendService::class)->bewertung($trend);
    expect($b['konfidenz'])->toBe('mittel')->and($b['begruendung'])->toContain('Bestätigt durch Verbraucherstudie')->and($b['befragung'])->toBeFalse();
});

it('Budget greift je Signal: erreicht → jedes Signal fehler, Provider nicht gerufen', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['trend_ki_budget_eur' => 0]);
    $this->eingang->signaleEinreichen($this->rootTeam, $this->beitrag, $this->signale, 'hand', $this->user->id);

    expect($this->stub->calls)->toBe(0)
        ->and(FoodAlchemistTrendEingang::pluck('begruendung')->unique()->all())->toBe(['Trend-KI-Budget erreicht']);
});
