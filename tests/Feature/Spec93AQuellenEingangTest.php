<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Trendradar\Eingang as EingangReiter;
use Platform\FoodAlchemist\Livewire\Trendradar\Index as TrendradarIndex;
use Platform\FoodAlchemist\Livewire\Trendradar\Quellen as QuellenReiter;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\TrendEingangService;
use Platform\FoodAlchemist\Services\TrendQuellenService;
use Platform\FoodAlchemist\Services\Trends\FeedLeser;
use Platform\FoodAlchemist\Support\SichererAbruf;
use Platform\FoodAlchemist\Support\StichwortFilter;
use Platform\FoodAlchemist\Support\TrendUrl;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 93 A · Quellen + Eingang (ohne KI): Dublettenschutz über die Teamfamilie, Stichwort-Filter, Abruf-Weg und
 * Rhythmus je Quelle, automatische Wahl Feed → Übersicht → MCP, Übersicht-Leser, Fehler an der Quelle statt Leerlauf,
 * SSRF-Schutz im Abruf, MCP-Tools, Team-Sicht, Oberfläche. Fixtures sind erfunden (tests/Fixtures/spec93), kein Test
 * spricht echte Seiten an. Testdaten tragen „TEST-93“.
 */
function spec93Fixture(string $name): string
{
    return file_get_contents(dirname(__DIR__).'/Fixtures/spec93/'.$name);
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->inhaber = $this->makeUser($this->rootTeam, 'Inhaber TEST-93');
    $this->leser = $this->makeUser($this->rootTeam, 'Leser TEST-93', 'viewer');
    $this->actingAs($this->inhaber);
    Http::preventStrayRequests();
    // A prüft den Eingang vor dem Erschließen (Abschnitt B, eigener Test) — der Job wird hier nur vorgemerkt
    \Illuminate\Support\Facades\Queue::fake([\Platform\FoodAlchemist\Jobs\TrendEingangErschliessenJob::class]);
    // Kein echtes DNS: Testhosts zeigen auf eine öffentliche Adresse, „intern.test“ ins interne Netz
    app()->instance(SichererAbruf::class, new SichererAbruf(fn (string $host) => str_starts_with($host, 'intern.') ? ['10.0.0.7'] : ['93.184.216.34']));
    $this->quellen = app(TrendQuellenService::class);
    $this->eingang = app(TrendEingangService::class);
    $this->reg = app(ToolRegistry::class);
    $this->ctx = new ToolContext($this->inhaber, $this->rootTeam);
});

function spec93Quelle(Team $team, array $daten = []): FoodAlchemistTrendQuelle
{
    return FoodAlchemistTrendQuelle::create(array_merge([
        'team_id' => $team->id, 'name' => 'TEST-93 Quelle', 'url' => 'https://fachblatt.beispiel.test/feed/',
        'art' => 'presse', 'feed_typ' => 'rss', 'abruf_weg' => 'plattform', 'rhythmus' => 'taeglich', 'aktiv' => true,
    ], $daten));
}

// ── Dublettenschutz ─────────────────────────────────────────────────────

it('Dublettenschutz: gleiche URL mit/ohne utm_, fbclid, Fragment und End-Slash ist eine Zeile', function () {
    expect(TrendUrl::normalisieren('HTTPS://Beispiel.TEST:443/artikel/x/?utm_source=a&b=2&fbclid=9&a=1#oben'))
        ->toBe('https://beispiel.test/artikel/x?a=1&b=2');

    $a = $this->eingang->einreichen($this->rootTeam, ['url' => 'https://beispiel.test/artikel/x/', 'titel' => 'TEST-93 Artikel'], 'mcp');
    $this->travel(5)->minutes();
    $b = $this->eingang->einreichen($this->rootTeam, ['url' => 'https://beispiel.test/artikel/x?utm_campaign=z&igsh=1', 'titel' => 'TEST-93 Artikel'], 'mcp');

    expect($a['ergebnis'])->toBe('neu')->and($b['ergebnis'])->toBe('dublette')
        ->and($b['eintrag']->id)->toBe($a['eintrag']->id)
        ->and($b['eintrag']->zuletzt_gesehen_at->greaterThan($a['eintrag']->zuletzt_gesehen_at))->toBeTrue()
        ->and(FoodAlchemistTrendEingang::count())->toBe(1);
});

it('Dublettenschutz: Treffer in einem anderen Team der Familie → keine neue Zeile, nur Meldung, fremde Zeile bleibt unberührt', function () {
    $erst = $this->eingang->einreichen($this->childA, ['url' => 'https://beispiel.test/a', 'titel' => 'TEST-93 Familie'], 'abruf');
    $gesehen = $erst['eintrag']->zuletzt_gesehen_at->toIso8601String();
    $this->travel(1)->hours();

    $geschwister = $this->eingang->einreichen($this->childB, ['url' => 'https://beispiel.test/a/', 'titel' => 'TEST-93 Familie'], 'abruf');
    $eltern = $this->eingang->einreichen($this->rootTeam, ['url' => 'https://beispiel.test/a?utm_x=1', 'titel' => 'TEST-93 Familie'], 'abruf');

    expect($geschwister['ergebnis'])->toBe('dublette')->and($geschwister['eintrag'])->toBeNull()
        ->and($geschwister['grund'])->toBe('Dublette (Team '.$this->childA->name.')')
        ->and($eltern['ergebnis'])->toBe('dublette')
        ->and(FoodAlchemistTrendEingang::count())->toBe(1)
        ->and($erst['eintrag']->refresh()->zuletzt_gesehen_at->toIso8601String())->toBe($gesehen);
});

// ── Stichwort-Filter ────────────────────────────────────────────────────

it('Stichwort-Filter: ein/aus, leere Liste, Umlaut, Wortanfang, Phrase', function () {
    $p = fn (string $t, array $ein, array $aus = []) => StichwortFilter::pruefen($t, $ein, $aus)['durch'];

    expect($p('Irgendwas', []))->toBeTrue()                                  // leere Ein-Liste = alles
        ->and($p('Neue vegane Bowls', ['vegan']))->toBeTrue()                // Wortanfang
        ->and($p('Antivegan-Debatte', ['vegan']))->toBeFalse()               // nur Wortanfang, nicht mitten im Wort
        ->and($p('Getränke im Bankett', ['Getraenk']))->toBeTrue()           // Umlaut ä = ae
        ->and($p('GETRAENKEKARTE', ['getränk']))->toBeTrue()                 // Groß-/Kleinschreibung, Umlaut im Stichwort
        ->and($p('Food Trends 2027', ['"food trend"']))->toBeFalse()         // Phrase exakt (Trends ≠ trend)
        ->and($p('Der Food Trend des Jahres', ['"food trend"']))->toBeTrue()
        ->and($p('Trend: Insolvenz eines Caterers', ['Trend'], ['Insolvenz']))->toBeFalse()   // aus schlägt ein
        ->and(StichwortFilter::pruefen('Trend: Insolvenz', ['Trend'], ['Insolvenz'])['grund'])->toBe('Aus-Stichwort „Insolvenz“')
        ->and(StichwortFilter::pruefen('Wetter', ['Trend'], [])['grund'])->toBe('kein Ein-Stichwort');
});

it('Stichwort-Filter: Team-Standard greift ohne eigene Liste der Quelle; MCP-Antwort „gefiltert“; Zähler an der Quelle', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['trend_stichworte_ein' => ['Trend'], 'trend_stichworte_aus' => ['Personalie']]);
    $mitTeam = spec93Quelle($this->rootTeam, ['abruf_weg' => 'mcp', 'feed_typ' => 'manuell', 'name' => 'TEST-93 MCP']);
    $eigen = spec93Quelle($this->rootTeam, ['abruf_weg' => 'mcp', 'feed_typ' => 'manuell', 'name' => 'TEST-93 eigen',
        'url' => 'https://eigen.beispiel.test/', 'stichworte_ein' => ['Kombucha']]);

    expect($this->quellen->stichworte($mitTeam)['ein'])->toBe(['Trend'])
        ->and($this->quellen->stichworte($eigen)['ein'])->toBe(['Kombucha'])
        ->and($this->quellen->stichworte($eigen)['aus'])->toBe(['Personalie']);   // je Liste: aus kommt vom Team

    $r = $this->reg->get('foodalchemist.trend_eingang.POST')->execute(['quelle_id' => $mitTeam->id, 'artikel' => [
        ['url' => 'https://x.beispiel.test/1', 'titel' => 'TEST-93 Personalie: neuer Chef'],
        ['url' => 'https://x.beispiel.test/2', 'titel' => 'TEST-93 Wetterbericht'],
        ['url' => 'https://x.beispiel.test/3', 'titel' => 'TEST-93 Trend Kombucha'],
    ]], $this->ctx);

    expect($r->success)->toBeTrue()
        ->and(array_column($r->data['ergebnisse'], 'ergebnis'))->toBe(['gefiltert', 'gefiltert', 'neu'])
        ->and($r->data['ergebnisse'][0]['grund'])->toBe('gefiltert: Aus-Stichwort „Personalie“')
        ->and($r->data['ergebnisse'][1]['grund'])->toBe('gefiltert: kein Ein-Stichwort')
        ->and(FoodAlchemistTrendEingang::count())->toBe(1);
    $mitTeam->refresh();
    expect($mitTeam->gefiltert_letzter_abruf)->toBe(2)->and($mitTeam->gefiltert_gesamt)->toBe(2)
        ->and($mitTeam->letzter_abruf_at)->not->toBeNull();
});

// ── Abruf-Weg und Rhythmus ──────────────────────────────────────────────

it('Rhythmus: Fälligkeit je Rhythmus, „aus“ und pausiert nie fällig, neue Quelle erbt den Team-Standard („aus“)', function () {
    $q = spec93Quelle($this->rootTeam, ['rhythmus' => 'woechentlich', 'letzter_abruf_at' => now()]);
    expect($this->quellen->istFaellig($q))->toBeFalse()
        ->and($this->quellen->istFaellig($q, now()->addDays(6)))->toBeFalse()
        ->and($this->quellen->istFaellig($q, now()->addDays(7)))->toBeTrue();
    $q->update(['rhythmus' => 'taeglich']);
    expect($this->quellen->istFaellig($q->refresh(), now()->addDay()))->toBeTrue();
    $q->update(['rhythmus' => 'monatlich']);
    expect($this->quellen->istFaellig($q->refresh(), now()->addDays(29)))->toBeFalse()
        ->and($this->quellen->istFaellig($q, now()->addDays(30)))->toBeTrue();
    $q->update(['rhythmus' => 'aus']);
    expect($this->quellen->faelligSeit($q->refresh()))->toBeNull();
    $q->update(['rhythmus' => 'taeglich', 'aktiv' => false]);
    expect($this->quellen->faelligSeit($q->refresh()))->toBeNull();

    $neu = $this->quellen->anlegen($this->rootTeam, ['url' => 'https://neu.beispiel.test/', 'abruf_weg' => 'mcp'])['quelle'];
    expect($neu->rhythmus)->toBe('aus')->and($neu->feed_typ)->toBe('manuell');
    app(TeamSettingsService::class)->update($this->rootTeam, ['trend_quellen_rhythmus' => 'woechentlich']);
    expect($this->quellen->anlegen($this->rootTeam, ['url' => 'https://neu2.beispiel.test/', 'abruf_weg' => 'mcp'])['quelle']->rhythmus)->toBe('woechentlich');
});

it('MCP-Quelle: der Command ruft sie nie ab (0 Anfragen), trend_quellen.GET liefert fällige zuerst, Einreichen setzt letzter_abruf_at', function () {
    Http::fake();
    $mcp = spec93Quelle($this->rootTeam, ['name' => 'TEST-93 MCP alt', 'abruf_weg' => 'mcp', 'feed_typ' => 'manuell', 'letzter_abruf_at' => now()->subDays(3)]);
    $nichtFaellig = spec93Quelle($this->rootTeam, ['name' => 'TEST-93 MCP frisch', 'abruf_weg' => 'mcp', 'feed_typ' => 'manuell',
        'url' => 'https://frisch.beispiel.test/', 'letzter_abruf_at' => now()]);
    spec93Quelle($this->rootTeam, ['name' => 'TEST-93 Plattform aus', 'rhythmus' => 'aus']);

    Artisan::call('foodalchemist:trend-quellen-abrufen');
    Artisan::call('foodalchemist:trend-quellen-abrufen', ['--quelle' => $mcp->id]);
    Http::assertNothingSent();

    $liste = $this->reg->get('foodalchemist.trend_quellen.GET')->execute(['abruf_weg' => 'mcp'], $this->ctx);
    expect(array_column($liste->data['quellen'], 'name'))->toBe(['TEST-93 MCP alt', 'TEST-93 MCP frisch'])
        ->and($liste->data['quellen'][0]['faellig'])->toBeTrue()->and($liste->data['quellen'][1]['faellig'])->toBeFalse()
        ->and($liste->data['faellig'])->toBe(1);

    $this->reg->get('foodalchemist.trend_eingang.POST')->execute(['quelle_id' => $mcp->id, 'url' => 'https://x.beispiel.test/neu', 'titel' => 'TEST-93 Fund'], $this->ctx);
    expect($mcp->refresh()->letzter_abruf_at->isToday())->toBeTrue()->and($this->quellen->istFaellig($mcp))->toBeFalse();
});

// ── Plattform-Abruf ─────────────────────────────────────────────────────

it('Abruf RSS: nur fällige plattform-Quellen, höchstens N neue je Abruf, Dubletten zählen nicht, Stichworte filtern', function () {
    config(['foodalchemist.trends.quellen.max_neue_je_abruf' => 1]);
    Http::fake(['https://fachblatt.beispiel.test/*' => Http::response(spec93Fixture('feed.xml'), 200, ['Content-Type' => 'application/rss+xml'])]);
    $q = spec93Quelle($this->rootTeam, ['stichworte_aus' => ['Personalie']]);

    $r1 = $this->eingang->quelleAbrufen($q);
    expect($r1)->toMatchArray(['neu' => 1, 'gefiltert' => 0, 'fehler' => null]);   // Grenze 1 erreicht, Rest wartet
    config(['foodalchemist.trends.quellen.max_neue_je_abruf' => 20]);
    $r2 = $this->eingang->quelleAbrufen($q->refresh());
    expect($r2)->toMatchArray(['neu' => 1, 'dubletten' => 1, 'gefiltert' => 1, 'fehler' => null]);

    $titel = FoodAlchemistTrendEingang::orderBy('id')->pluck('titel')->all();
    expect($titel)->toBe(['Neue Trends im Catering: Bowls mit Gemüse aus der Region', 'Getränke-Konzept: Kombucha vom Fass']);
    $erster = FoodAlchemistTrendEingang::orderBy('id')->first();
    expect($erster->teaser)->toBe('Erfundener Teaser über Bowls im Catering.')     // Tags entfernt, kein Volltext
        ->and($erster->url_hash)->toBe(TrendUrl::hash('https://fachblatt.beispiel.test/artikel/bowls-region/'))
        ->and($erster->eingereicht_von)->toBe('abruf')->and($erster->status)->toBe('neu')
        ->and($q->refresh()->gefiltert_letzter_abruf)->toBe(1)->and($q->letzter_fehler)->toBeNull();
});

it('Abruf Übersicht: Artikel-Links ohne Navigation, Fuß, Seitenleiste, Tag-, Blätter- und Fremd-Links; Atom-Feed', function () {
    $treffer = app(FeedLeser::class)->uebersicht(spec93Fixture('uebersicht.html'), 'https://www.beispielmagazin.test/schlagwort/food-trends/');

    expect(array_column($treffer, 'titel'))->toBe(['Fermentierte Saucen erobern die Kantine', 'Alkoholfreie Aperitifs im Bankett',
        'Pflanzliche Proteine in der Schulverpflegung'])
        ->and($treffer[0]['url'])->toBe('https://www.beispielmagazin.test/fermentierte-saucen-erobern-die-kantine/')
        ->and($treffer[0]['veroeffentlicht_am'])->toStartWith('2026-10-20')
        ->and($treffer[0]['teaser'])->toBe('Erfundener Teaser: Betriebsrestaurants setzen auf Koji und Miso.');

    $atom = app(FeedLeser::class)->feed(spec93Fixture('atom.xml'), 'https://atom.beispiel.test/');
    expect($atom[0])->toMatchArray(['titel' => 'Gastronomie testet Mehrweg-Bowls', 'url' => 'https://atom.beispiel.test/mehrweg-bowls']);
});

it('Ehrlichkeit: Übersicht ohne Treffer, Bot-Sperre und SSRF landen als Fehler an der Quelle, fehler_in_folge zählt, kein Abbruch', function () {
    Http::fake([
        'https://leer.beispiel.test/*' => Http::response('<html><body><nav><h2><a href="/x-artikel-in-der-navigation/">Artikel in der Navigation</a></h2></nav></body></html>'),
        'https://gesperrt.beispiel.test/*' => Http::response('Forbidden', 403),
        'https://weiter.beispiel.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
    ]);
    $leer = spec93Quelle($this->rootTeam, ['name' => 'TEST-93 leer', 'url' => 'https://leer.beispiel.test/rubrik/', 'feed_typ' => 'uebersicht']);
    $gesperrt = spec93Quelle($this->rootTeam, ['name' => 'TEST-93 gesperrt', 'url' => 'https://gesperrt.beispiel.test/feed']);
    $intern = spec93Quelle($this->rootTeam, ['name' => 'TEST-93 intern', 'url' => 'http://intern.beispiel.test/feed']);
    $lokal = spec93Quelle($this->rootTeam, ['name' => 'TEST-93 lokal', 'url' => 'http://127.0.0.1/feed']);
    $weiter = spec93Quelle($this->rootTeam, ['name' => 'TEST-93 weiter', 'url' => 'https://weiter.beispiel.test/feed']);

    Artisan::call('foodalchemist:trend-quellen-abrufen');
    Artisan::call('foodalchemist:trend-quellen-abrufen', ['--quelle' => $leer->id]);

    expect($leer->refresh()->letzter_fehler)->toBe('keine Artikel erkannt – auf MCP umstellen')
        ->and($leer->fehler_in_folge)->toBe(2)
        ->and($gesperrt->refresh()->letzter_fehler)->toBe('Abruf gesperrt (HTTP 403) – auf MCP umstellen')
        ->and($intern->refresh()->letzter_fehler)->toContain('interne Netz')
        ->and($lokal->refresh()->letzter_fehler)->toContain('interne Netz')
        ->and($weiter->refresh()->letzter_fehler)->toContain('interne Netz')
        ->and(FoodAlchemistTrendEingang::count())->toBe(0);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '169.254') || str_contains($req->url(), '127.0.0.1') || str_contains($req->url(), 'intern.'));
    // Bot-Sperre wird nicht umgangen: genau ein Versuch je Lauf, mit dem eigenen User-Agent
    Http::assertSent(fn ($req) => str_contains($req->url(), 'gesperrt') && $req->header('User-Agent')[0] === SichererAbruf::USER_AGENT);
});

// ── Automatische Wahl beim Anlegen ──────────────────────────────────────

it('Auto-Wahl 1: Feed über <link rel=alternate> → rss, plattform', function () {
    Http::fake([
        'https://fachblatt.beispiel.test/rubrik/feed.xml' => Http::response(spec93Fixture('feed.xml'), 200),
        'https://fachblatt.beispiel.test/rubrik/' => Http::response(spec93Fixture('mit_feedlink.html'), 200),
    ]);
    $r = $this->reg->get('foodalchemist.trend_quellen.POST')->execute(['url' => 'https://fachblatt.beispiel.test/rubrik/', 'name' => 'TEST-93 Fachblatt'], $this->ctx);

    expect($r->success)->toBeTrue()
        ->and($r->data['quelle'])->toMatchArray(['feed_typ' => 'rss', 'abruf_weg' => 'plattform', 'feed_url' => 'https://fachblatt.beispiel.test/rubrik/feed.xml', 'rhythmus' => 'aus'])
        ->and($r->data['pruefung']['erkannt'])->toBe('Feed (RSS) über Feed-Link auf der Seite')
        ->and($r->data['quelle']['pruef_hinweis'])->toContain('gewählt: Plattform holt ab');
});

it('Auto-Wahl 2: kein Feed, Übersicht mit ≥ 3 Artikeln → uebersicht, plattform', function () {
    Http::fake([
        'https://www.beispielmagazin.test/schlagwort/food-trends/' => Http::response(spec93Fixture('uebersicht.html'), 200),
        '*' => Http::response('nicht da', 404),
    ]);
    $r = $this->quellen->anlegen($this->rootTeam, ['url' => 'https://www.beispielmagazin.test/schlagwort/food-trends/']);

    expect($r['quelle']->feed_typ)->toBe('uebersicht')->and($r['quelle']->abruf_weg)->toBe('plattform')
        ->and($r['pruefung']['treffer'])->toBe(3)->and($r['quelle']->name)->toBe('www.beispielmagazin.test');
});

it('Auto-Wahl 3: zu wenig Artikel oder Bot-Sperre → mcp, manuell, mit Hinweis; „neu prüfen“ setzt den Weg neu', function () {
    Http::fake([
        'https://portal.beispiel.test/' => Http::sequence()->push(spec93Fixture('duenn.html'))->push(spec93Fixture('feed.xml')),
        'https://sperre.beispiel.test/' => Http::response('nein', 403),
        '*' => Http::response('nicht da', 404),
    ]);
    $duenn = $this->quellen->anlegen($this->rootTeam, ['url' => 'https://portal.beispiel.test/'])['quelle'];
    $sperre = $this->quellen->anlegen($this->rootTeam, ['url' => 'https://sperre.beispiel.test/'])['quelle'];

    expect($duenn->abruf_weg)->toBe('mcp')->and($duenn->feed_typ)->toBe('manuell')
        ->and($duenn->pruef_hinweis)->toContain('kein Feed, Seite nicht lesbar – wird per MCP gesichtet')
        ->and($sperre->abruf_weg)->toBe('mcp')->and($sperre->pruef_hinweis)->toContain('Abruf gesperrt (HTTP 403)');

    $neu = $this->reg->get('foodalchemist.trend_quellen.PUT')->execute(['id' => $duenn->id, 'pruefen' => true], $this->ctx);
    expect($neu->data['quelle']['abruf_weg'])->toBe('plattform')->and($neu->data['quelle']['feed_typ'])->toBe('rss')
        ->and($neu->data['pruefung']['erkannt'])->toBe('Feed (RSS)');
});

it('Probelauf „Was käme durch?“ zeigt durchgelassen vs. gefiltert und speichert nichts', function () {
    Http::fake(['https://fachblatt.beispiel.test/*' => Http::response(spec93Fixture('feed.xml'), 200)]);
    $q = spec93Quelle($this->rootTeam, ['stichworte_aus' => ['Personalie']]);

    $r = $this->reg->get('foodalchemist.trend_quellen.PUT')->execute(['id' => $q->id, 'probelauf' => true], $this->ctx);

    expect(count($r->data['probelauf']['durch']))->toBe(2)
        ->and($r->data['probelauf']['gefiltert'][0]['grund'])->toBe('Aus-Stichwort „Personalie“')
        ->and(FoodAlchemistTrendEingang::count())->toBe(0)
        ->and($q->refresh()->letzter_abruf_at)->toBeNull();
});

// ── Entscheiden, Pinnwand, Rechte, Team-Sicht ───────────────────────────

it('Entscheiden: verwerfen, Thema, „Auf die Pinnwand“ legt eine manuelle Inspiration an — nichts Automatisches auf der Pinnwand', function () {
    $e = $this->eingang->einreichen($this->rootTeam, ['url' => 'https://x.beispiel.test/p', 'titel' => 'TEST-93 Pinnwand', 'teaser' => 'Kurz.'], 'mcp')['eintrag'];
    expect(FoodAlchemistTrendInspiration::count())->toBe(0);

    $ent = $this->reg->get('foodalchemist.trend_eingang.ENTSCHEIDEN');
    expect($ent->execute(['ids' => [$e->id], 'aktion' => 'thema', 'wert' => 'Pinnwand-Test'], $this->ctx)->data['eintraege'][0]['thema'])->toBe('Pinnwand-Test');
    $p = $ent->execute(['ids' => [$e->id], 'aktion' => 'pinnwand'], $this->ctx);
    expect($p->success)->toBeTrue()->and(FoodAlchemistTrendInspiration::count())->toBe(1)
        ->and($p->data['eintraege'][0]['inspiration_id'])->toBe((int) FoodAlchemistTrendInspiration::first()->id)
        ->and($ent->execute(['ids' => [$e->id], 'aktion' => 'pinnwand'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');
    expect($ent->execute(['ids' => [$e->id], 'aktion' => 'verwerfen', 'wert' => 'TEST-93 doppelt'], $this->ctx)->data['eintraege'][0]['status'])->toBe('verworfen');
});

it('Rechte und Team-Sicht: Lesen darf nicht schreiben; Kunde B sieht Quellen und Eingang von Kunde A nicht; Kind sieht Eltern, ändert sie nicht', function () {
    $q = spec93Quelle($this->rootTeam, ['abruf_weg' => 'mcp', 'feed_typ' => 'manuell']);
    $leserCtx = new ToolContext($this->leser, $this->rootTeam);
    expect($this->reg->get('foodalchemist.trend_quellen.POST')->execute(['url' => 'https://y.beispiel.test/', 'abruf_weg' => 'mcp'], $leserCtx)->errorCode)->toBe('FORBIDDEN')
        ->and($this->reg->get('foodalchemist.trend_eingang.POST')->execute(['url' => 'https://y.beispiel.test/1', 'titel' => 'TEST-93'], $leserCtx)->errorCode)->toBe('FORBIDDEN')
        ->and($this->reg->get('foodalchemist.trend_quellen.GET')->execute([], $leserCtx)->data['anzahl'])->toBe(1);

    // Master-Team mit zwei Kunden: A und B sehen sich nicht
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $qa = spec93Quelle($this->childA, ['name' => 'TEST-93 Kunde A', 'url' => 'https://kunde-a.beispiel.test/']);
    $ea = $this->eingang->einreichen($this->childA, ['url' => 'https://a.beispiel.test/1', 'titel' => 'TEST-93 Kunde A'], 'abruf')['eintrag'];
    $nutzerB = $this->makeUser($this->childB, 'B TEST-93');
    $ctxB = new ToolContext($nutzerB, $this->childB);

    $quellenB = $this->reg->get('foodalchemist.trend_quellen.GET')->execute([], $ctxB)->data;
    expect(array_column($quellenB['quellen'], 'id'))->not->toContain($qa->id)
        ->and($this->reg->get('foodalchemist.trend_eingang.GET')->execute([], $ctxB)->data['anzahl'])->toBe(0)
        ->and($this->reg->get('foodalchemist.trend_quellen.GET')->execute(['id' => $qa->id], $ctxB)->errorCode)->toBe('NOT_FOUND')
        ->and($this->reg->get('foodalchemist.trend_eingang.ENTSCHEIDEN')->execute(['ids' => [$ea->id], 'aktion' => 'verwerfen'], $ctxB)->errorCode)->toBe('NOT_FOUND')
        ->and($this->reg->get('foodalchemist.trend_eingang.POST')->execute(['quelle_id' => $qa->id, 'url' => 'https://b.beispiel.test/', 'titel' => 'TEST-93'], $ctxB)->errorCode)->toBe('NOT_FOUND');

    // Kind sieht die Quelle der Eltern (Lesen wie Spec 79), darf sie aber nicht ändern
    $nutzerA = $this->makeUser($this->childA, 'A TEST-93');
    $ctxA = new ToolContext($nutzerA, $this->childA);
    expect(array_column($this->reg->get('foodalchemist.trend_quellen.GET')->execute([], $ctxA)->data['quellen'], 'id'))->toContain($q->id)
        ->and($this->reg->get('foodalchemist.trend_quellen.PUT')->execute(['id' => $q->id, 'rhythmus' => 'taeglich'], $ctxA)->errorCode)->toBe('NOT_FOUND')
        ->and($this->reg->get('foodalchemist.trend_quellen.DELETE')->execute(['id' => $q->id, 'confirm' => true], $ctxA)->errorCode)->toBe('NOT_FOUND');
});

it('Deploy-sicher: Scheduler-Schalter und Team-Rhythmus stehen ab Werk auf aus', function () {
    expect(config('foodalchemist.scheduler.trend_quellen_enabled'))->toBeFalse()
        ->and(app(TeamSettingsService::class)->trendQuellenRhythmus($this->rootTeam))->toBe('aus')
        ->and(app(TeamSettingsService::class)->trendStichworte($this->rootTeam))->toBe(['ein' => [], 'aus' => []]);
    $befehle = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");
    expect($befehle)->not->toContain('trend-quellen-abrufen');
});

// ── Oberfläche ──────────────────────────────────────────────────────────

it('UI: Reiter Eingang und Quellen, Quelle anlegen, Eintrag verwerfen und auf die Pinnwand legen', function () {
    Http::fake(['https://sperre.beispiel.test/' => Http::response('nein', 403), '*' => Http::response('', 404)]);
    $e = $this->eingang->einreichen($this->rootTeam, ['url' => 'https://x.beispiel.test/ui', 'titel' => 'TEST-93 UI-Artikel'], 'mcp')['eintrag'];

    Livewire::test(TrendradarIndex::class)->call('ansichtSetzen', 'eingang')->assertSet('ansicht', 'eingang')
        ->assertSeeHtml('data-tab-eingang')->assertSeeHtml('data-tab-quellen');

    Livewire::test(EingangReiter::class)->assertSee('TEST-93 UI-Artikel')
        ->call('entscheiden', $e->id, 'pinnwand')->assertSee('Als Inspiration auf die Pinnwand gelegt')
        ->call('entscheiden', $e->id, 'verwerfen')->assertDontSeeHtml('data-eingang-eintrag="'.$e->id.'"')
        ->set('filter', 'verworfen')->assertSeeHtml('data-eingang-eintrag="'.$e->id.'"')->assertSee('TEST-93 UI-Artikel');

    Livewire::test(QuellenReiter::class)
        ->set('neu.url', 'https://sperre.beispiel.test/')->set('neu.name', 'TEST-93 UI-Quelle')->call('anlegen')
        ->assertSee('TEST-93 UI-Quelle')->assertSee('Per MCP gesichtet')->assertSee('Abruf gesperrt');

    $this->actingAs($this->leser);
    Livewire::test(QuellenReiter::class)->assertDontSeeHtml('data-quelle-neu')->assertDontSee('Quelle neu prüfen');
});
