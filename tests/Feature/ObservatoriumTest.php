<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Observatorium\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistObservatoriumMerker;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Services\ObservatoriumService;
use Platform\FoodAlchemist\Services\TrendEingangService;
use Platform\FoodAlchemist\Services\TrendQuellenService;
use Platform\FoodAlchemist\Services\Trends\FeedLeser;
use Platform\FoodAlchemist\Services\Trends\TrendAutomatikService;
use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Support\FaBereiche;
use Platform\FoodAlchemist\Support\GoogleNewsSuche;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Support\ObservatoriumInhalt;
use Platform\FoodAlchemist\Support\SichererAbruf;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->owner = $this->makeUser($this->rootTeam, 'Observatorium Inhaber TEST');
    $this->viewer = $this->makeUser($this->rootTeam, 'Observatorium Leser TEST', 'viewer');
    $this->actingAs($this->owner);
    $this->travelTo(Carbon::parse('2026-11-04 12:00:00 UTC'));
    Queue::fake();
    Http::preventStrayRequests();
    $this->obs = app(ObservatoriumService::class);
    $this->eingang = app(TrendEingangService::class);
});

function obsArtikel(Team $team, string $titel, array $daten = []): FoodAlchemistTrendEingang
{
    return app(TrendEingangService::class)->einreichen($team, $daten + ['url' => 'https://quelle.test/'.sha1($titel.json_encode($daten)),
        'titel' => $titel, 'teaser' => 'TEST – Recherche zur Gastronomie und Küche.', 'veroeffentlicht_am' => now()->toIso8601String()], 'abruf')['eintrag'];
}

it('bewahrt eine Betreiberübernahme als Branchennews, ohne daraus Trendbelege zu machen', function () {
    $e = obsArtikel($this->rootTeam, 'TEST Caterer übernimmt die Betriebsgastronomie eines Unternehmens');
    $this->eingang->erschliessungUebernehmen($e, ['relevant' => false, 'begruendung' => 'Reiner Betreiberwechsel, kein konkretes Trend-Signal.',
        'observatorium' => ['verwendungen' => ['news', 'markt'], 'branchen' => ['betrieb', 'erfunden'], 'einordnung' => 'Neue Marktbeziehung.']], []);
    expect($e->refresh()->status)->toBe('verworfen')->and($e->thema_inspiration_id)->toBeNull()
        ->and($this->obs->gruppiert($this->rootTeam, ['ansicht' => 'news'])->pluck('id')->all())->toBe([$e->id])
        ->and($this->obs->gruppiert($this->rootTeam, ['ansicht' => 'markt'])->count())->toBe(1)
        ->and($e->observatorium['branchen'])->not->toContain('erfunden');
    Livewire::test(Index::class)->call('ansichtSetzen', 'news')->assertSee($e->titel)->call('oeffnen', $e->id)->assertSee('Reiner Betreiberwechsel');
});

it('zeigt reale Recherche statt Beispieldaten und integriert die Reiter in die vorhandene Seitenleiste', function () {
    $e = obsArtikel($this->rootTeam, 'TEST Premiumküche: ein saisonales Gemüsemenü in Berlin');
    $ui = Livewire::test(Index::class)->assertSeeHtml('data-observatorium-navigation')->assertSeeHtml('data-fa-page-sidebar')
        ->assertSee('Trend Research')->assertSee('Mein Briefing')->assertSee($e->titel)->assertDontSee('Streetfood trifft Gala');
    foreach (['news', 'ideen', 'markt', 'merkliste', 'briefing', 'radar'] as $ansicht) {
        $ui->call('ansichtSetzen', $ansicht)->assertSet('ansicht', $ansicht)->assertSeeHtml('data-observatorium-ansicht="'.$ansicht.'"');
    }
    expect(FaBereiche::fuerRoute('foodalchemist.observatorium.index'))->toBe('wissen')
        ->and(FaBereiche::fuerKomponente(Index::class))->toBe('wissen');
});

it('filtert Branchen, Region und Publikationszeit, statt alte Artikel als neue Funde zu zeigen', function () {
    $kita = obsArtikel($this->rootTeam, 'TEST Kita Frühstück in Berlin');
    $premium = obsArtikel($this->rootTeam, 'TEST Fine Dining Menü in Wien');
    $alt = obsArtikel($this->rootTeam, 'TEST Kita Speiseplan aus dem Archiv', ['veroeffentlicht_am' => '2023-01-01']);
    expect($this->obs->gruppiert($this->rootTeam, ['branche' => 'kita'])->pluck('id')->all())->toBe([$kita->id])
        ->and($this->obs->gruppiert($this->rootTeam, ['region' => 'AT'])->pluck('id')->all())->toBe([$premium->id])
        ->and($this->obs->gruppiert($this->rootTeam, ['suche' => 'Archiv', 'tage' => 'alle'])->pluck('id')->all())->toBe([$alt->id]);
    Livewire::test(Index::class)->set('branche', 'premium')->assertSee($premium->titel)->assertDontSee($kita->titel)
        ->set('region', 'DE')->assertSee('Noch keine passenden Meldungen')->call('filterZuruecksetzen')->assertSee($kita->titel);
});

it('isoliert persönliche Merker nach Person und Team und verändert weder Trendstatus noch Artikel', function () {
    $e = obsArtikel($this->rootTeam, 'TEST Restaurant mit kreativer Speisekarte');
    $this->actingAs($this->viewer);
    $this->obs->merken($this->rootTeam, $e->id);
    $this->obs->merken($this->rootTeam, $e->id, true);
    expect($this->obs->gruppiert($this->rootTeam, ['ansicht' => 'merkliste'])->count())->toBe(1)
        ->and($e->refresh()->status)->toBe('neu')->and($e->inspiration_id)->toBeNull();
    $this->actingAs($this->owner);
    expect($this->obs->gruppiert($this->rootTeam, ['ansicht' => 'merkliste'])->count())->toBe(0);
    $this->actingAs($this->viewer);
    $this->obs->merken($this->rootTeam, $e->id); // Prüfung bleibt trotz entferntem Lesezeichen
    expect($this->obs->gruppiert($this->rootTeam, ['ansicht' => 'merkliste', 'pruefen' => true])->count())->toBe(1)
        ->and(FoodAlchemistObservatoriumMerker::count())->toBe(1);
});

it('verhindert Geschwister-Team-Leaks auch über manipulierte Detail- und Merk-Aktionen', function () {
    $parent = obsArtikel($this->rootTeam, 'TEST Restaurant der Zentrale');
    $fremd = obsArtikel($this->childB, 'TEST Restaurant des anderen Teams');
    $user = $this->makeUser($this->childA, 'Observatorium Kind TEST');
    $this->actingAs($user);
    expect($this->obs->gruppiert($this->childA)->pluck('id')->all())->toBe([$parent->id]);
    Livewire::test(Index::class)->call('oeffnen', $fremd->id)->assertStatus(404);
    Livewire::test(Index::class)->call('merken', $fremd->id)->assertStatus(404);
    Livewire::test(Index::class)->set('artikelId', $fremd->id)->assertStatus(404);
    expect(FoodAlchemistObservatoriumMerker::where('eingang_id', $fremd->id)->count())->toBe(0);
});

it('erlaubt am Handy Lesen und persönliche Merker, aber keine Pinnwand-Redaktion', function () {
    $e = obsArtikel($this->rootTeam, 'TEST Gourmetküche mit saisonalen Zutaten');
    Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(Index::class)->call('oeffnen', $e->id)
        ->assertSee($e->titel)->call('merken', $e->id)->assertSee('Gemerkt')->call('aufPinnwand', $e->id);
    expect($e->refresh()->inspiration_id)->toBeNull();
    $this->actingAs($this->viewer);
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(Index::class)->call('aufPinnwand', $e->id)->assertStatus(403);
});

it('bündelt gleichbetitelte Meldungen und speichert eine RSS-Publisherangabe sicher', function () {
    $titel = 'TEST Eine neue Gemüsekomponente für die professionelle Gemeinschaftsverpflegung';
    $a = obsArtikel($this->rootTeam, $titel.' - Fachblatt A', ['url' => 'https://news.google.com/rss/articles/a', 'herausgeber' => 'Fachblatt A', 'herausgeber_url' => 'https://fachblatt-a.test/']);
    $b = obsArtikel($this->rootTeam, $titel.' - Fachblatt B', ['url' => 'https://news.google.com/rss/articles/b', 'herausgeber' => 'Fachblatt B', 'herausgeber_url' => 'https://fachblatt-b.test/']);
    expect($this->obs->gruppiert($this->rootTeam)->count())->toBe(1)->and($a->inhalt_hash)->toBe($b->inhalt_hash)
        ->and($this->obs->weitereQuellen($this->rootTeam, $a)->count())->toBe(1);
    $xml = '<rss><channel><item><title>TEST Küche</title><link>https://news.google.com/rss/articles/c</link><source url="javascript:alert(1)">Fachblatt</source></item></channel></rss>';
    $gelesen = app(FeedLeser::class)->feed($xml, 'https://news.google.com/rss/search?q=Kueche');
    expect($gelesen[0]['herausgeber'])->toBe('Fachblatt')->and($gelesen[0]['herausgeber_url'])->toBeNull();
});

it('zählt Originalverlage statt des Google-Aggregators und dieselbe Meldung nur einmal', function () {
    $e1 = obsArtikel($this->rootTeam, 'TEST Neue ballaststoffreiche Speisen in der Betriebsgastronomie - Verlag A', ['url' => 'https://news.google.com/rss/articles/za', 'herausgeber' => 'Verlag A', 'herausgeber_url' => 'https://verlag-a.test']);
    $e2 = obsArtikel($this->rootTeam, 'TEST Andere ballaststoffreiche Menüs und neue Nachfrage im Restaurant - Verlag B', ['url' => 'https://news.google.com/rss/articles/zb', 'herausgeber' => 'Verlag B', 'herausgeber_url' => 'https://verlag-b.test']);
    $e3 = obsArtikel($this->rootTeam, 'TEST Neue ballaststoffreiche Speisen in der Betriebsgastronomie - Verlag C', ['url' => 'https://news.google.com/rss/articles/zc', 'herausgeber' => 'Verlag C', 'herausgeber_url' => 'https://verlag-c.test']);
    foreach ([$e1, $e2, $e3] as $e) {
        $this->eingang->erschliessungUebernehmen($e, ['relevant' => true, 'thema' => 'TEST Ballaststoffküche', 'kategorie' => 'food', 'tiefe' => 'beduerfnis'], []);
    }
    $svc = app(TrendAutomatikService::class);
    $k = $svc->kennzahlen($this->rootTeam, $e1->refresh()->themaInspiration, $svc->einstellungen($this->rootTeam)['regeln']);
    expect($k['quellen_fenster'])->toBe(2)->and($k['sichtungen'])->toBe(2);
});

it('hält alte Google-News-Funde in Vorschau und Import vor dem KI-Aufruf zurück', function () {
    app()->instance(SichererAbruf::class, new SichererAbruf(fn () => ['93.184.216.34']));
    $url = 'https://news.google.com/rss/search?q=Kueche%20when%3A30d';
    $q = FoodAlchemistTrendQuelle::create(['team_id' => $this->rootTeam->id, 'name' => 'TEST News', 'url' => $url, 'art' => 'presse', 'feed_typ' => 'rss', 'abruf_weg' => 'plattform', 'rhythmus' => 'monatlich', 'aktiv' => true]);
    Http::fake(['news.google.com/*' => Http::response('<rss><channel><item><title>TEST Küche alt</title><link>https://news.google.com/rss/articles/old</link><pubDate>Wed, 01 Feb 2023 10:00:00 GMT</pubDate></item><item><title>TEST Küche aktuell</title><link>https://news.google.com/rss/articles/new</link><pubDate>Wed, 04 Nov 2026 10:00:00 GMT</pubDate></item></channel></rss>')]);
    $p = app(TrendQuellenService::class)->probelauf($this->rootTeam, $q->id);
    expect($p['fehler'])->toBeNull()->and($p['durch'])->toHaveCount(1)->and($p['gefiltert'])->toHaveCount(1);
    $lauf = $this->eingang->quelleAbrufen($q);
    expect($lauf['neu'])->toBe(1)->and($lauf['gefiltert'])->toBe(1)->and(FoodAlchemistTrendEingang::count())->toBe(1);
    expect(GoogleNewsSuche::datumsGrund($url, null))->not->toBeNull()
        ->and(GoogleNewsSuche::datumsGrund($url, now()->addWeek()))->not->toBeNull()
        ->and(GoogleNewsSuche::datumsGrund('https://fachblatt.test/feed', '2023-01-01'))->toBeNull();
});

it('lässt Sport und Polizei auch bei einem falschen KI-Vorschlag nicht als Gastronomie-News durch', function () {
    foreach (['TEST Festnahme mutmaßlicher Taschendiebe auf der ANUGA', 'TEST Fußball: Die Aufstellungen im Überblick'] as $titel) {
        $daten = ObservatoriumInhalt::einordnen(['titel' => $titel], ['verwendungen' => ['news', 'idee'], 'branchen' => ['catering']]);
        expect($daten['verwendungen'])->toBe([]);
    }
});

it('projiziert Bestandsartikel bei der Migration ohne KI und ohne Änderung ihrer Trendentscheidung', function () {
    $e = obsArtikel($this->rootTeam, 'TEST Caterer übernimmt ein neues Betriebsrestaurant');
    $e->update(['status' => 'verworfen', 'begruendung' => 'Keine neue kulinarische Entwicklung.']);
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_12_13_100000_create_observatorium_projection_and_merker.php';
    $migration->down();
    $migration->up();
    $e->refresh();
    expect($e->status)->toBe('verworfen')->and($e->begruendung)->toBe('Keine neue kulinarische Entwicklung.')
        ->and($e->observatorium['verwendungen'])->toContain('news', 'markt')
        ->and($e->observatorium['herkunft'])->toBe('stichworte')->and($e->inhalt_hash)->toHaveLength(64);
});

it('blättert Meldungen serverseitig und beginnt nach einer Filteränderung wieder auf der ersten Seite', function () {
    foreach (range(1, 15) as $nr) {
        obsArtikel($this->rootTeam, 'TEST Restaurant Nachricht '.str_pad((string) $nr, 2, '0', STR_PAD_LEFT));
    }
    Livewire::test(Index::class)->call('ansichtSetzen', 'news')->assertSee('Seite 1 von 2')
        ->assertSee('TEST Restaurant Nachricht 15')->assertDontSee('TEST Restaurant Nachricht 01')
        ->call('nextPage')->assertSee('Seite 2 von 2')->assertSee('TEST Restaurant Nachricht 01')
        ->assertDontSee('TEST Restaurant Nachricht 15')->set('suche', 'Nachricht 15')
        ->assertSee('TEST Restaurant Nachricht 15')->assertDontSee('TEST Restaurant Nachricht 01');
});

it('filtert nur freigegebene Trends im Observatorium mit den bestehenden Radarregeln', function () {
    $svc = app(TrendService::class);
    foreach ([['TEST Fermentation', 'food', 'hype', ['bildung']], ['TEST Getränke', 'getraenke', 'trend', []]] as [$name, $kategorie, $typ, $sparten]) {
        $t = $svc->anlegen($this->rootTeam, ['name' => $name, 'typ' => $typ, 'ebene' => 'mode', 'kategorie' => $kategorie, 'sparten' => $sparten,
            'beleg' => ['quelle' => 'branchenquelle', 'titel' => 'TEST Fachquelle']]);
        $svc->statusSetzen($this->rootTeam, $t->id, 'auf_radar', $this->owner->id);
    }
    $svc->anlegen($this->rootTeam, ['name' => 'TEST Nicht freigegeben', 'kategorie' => 'food']);
    $ui = Livewire::test(Index::class)->call('ansichtSetzen', 'radar')
        ->assertSeeHtml('data-observatorium-radar-filter')->assertSee('TEST Fermentation')->assertSee('TEST Getränke')->assertDontSee('TEST Nicht freigegeben');
    $ui->set('kategorien', ['food'])->assertSee('TEST Fermentation')->assertDontSee('TEST Getränke')
        ->set('typen', ['trend'])->assertSee('Keine Entwicklungen für diese Filter')
        ->call('radarFilterZuruecksetzen')->assertSee('TEST Getränke')
        ->set('sparten', ['care'])->assertDontSee('TEST Fermentation')->assertSee('TEST Getränke')
        ->set('nurBefragung', true)->assertSee('Keine Entwicklungen für diese Filter')
        ->call('radarFilterZuruecksetzen')->set('radarSuche', 'Fermentation')->assertSee('TEST Fermentation')->assertDontSee('TEST Getränke')
        ->set('ebenen', ['mega'])->assertSee('Keine Entwicklungen für diese Filter');
});

it('ordnet Research in der mittleren Spalte und hält die gemeinsame Pinnwand erreichbar', function () {
    $ui = Livewire::test(Platform\FoodAlchemist\Livewire\Trendradar\Index::class)
        ->assertSet('ansicht', 'liste')->assertSeeHtml('data-research-navigation')->assertSee('Trendverwaltung')->assertSee('Veröffentlichtes Radar');
    $ui->call('ansichtSetzen', 'inspiration')->assertSee('Pinnwand')->assertSeeHtml('data-pinnwand-filter');
    $ui->call('ansichtSetzen', 'liste')->assertSeeHtml('data-trend-sparten-filter');
    Livewire::withQueryParams(['ansicht' => 'inspiration'])->withCookie(HandyLesemodus::COOKIE, '1')
        ->test(Platform\FoodAlchemist\Livewire\Trendradar\Index::class)->assertSet('handyReiter', 'pinnwand');
});

it('nutzt am Handy die volle Breite und zeigt freigegebene Entwicklungen als Karten statt Miniaturgrafik', function () {
    $e = obsArtikel($this->rootTeam, 'TEST Restaurant: Neues Gemüsemenü');
    $svc = app(TrendService::class);
    $t = $svc->anlegen($this->rootTeam, ['name' => 'TEST Fermentation mobil', 'typ' => 'trend', 'ebene' => 'konsum', 'kategorie' => 'food',
        'beleg' => ['quelle' => 'branchenquelle', 'titel' => 'TEST Quelle']]);
    $svc->statusSetzen($this->rootTeam, $t->id, 'auf_radar', $this->owner->id);
    $ui = Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(Index::class)
        ->assertSeeHtml('data-observatorium-handy-navigation')->assertDontSeeHtml('data-fa-page-sidebar')
        ->assertSeeHtml('data-observatorium-handy-filterknopf')->assertSee($e->titel)
        ->set('branche', 'kita')->assertSee('1 Filter aktiv')->assertSee('Noch keine passenden Meldungen')
        ->call('filterZuruecksetzen')->assertSee($e->titel)
        ->call('oeffnen', $e->id)->assertSeeHtml('data-observatorium-detail')->call('merken', $e->id)
        ->call('ansichtSetzen', 'merkliste')->assertSee($e->titel)
        ->call('ansichtSetzen', 'radar')->assertSeeHtml('data-observatorium-entwicklungskarten')
        ->assertDontSeeHtml('data-observatorium-radar-chart')->assertSee('TEST Fermentation mobil')->assertSee('Konfidenz:')
        ->set('typen', ['hype'])->assertSee('Keine Entwicklungen für diese Filter')
        ->call('radarFilterZuruecksetzen')->assertSee('TEST Fermentation mobil');
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(Index::class)->call('ansichtSetzen', 'radar')
        ->assertSeeHtml('data-fa-page-sidebar')->assertSeeHtml('data-observatorium-radar-chart')->assertDontSeeHtml('data-observatorium-handy-navigation');
});
