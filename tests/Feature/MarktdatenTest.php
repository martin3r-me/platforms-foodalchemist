<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Observatorium\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistMarktdaten as Daten;
use Platform\FoodAlchemist\Services\MarktdatenService;
use Platform\FoodAlchemist\Services\TrendEingangService;
use Platform\FoodAlchemist\Services\TrendQuellenService;
use Platform\FoodAlchemist\Services\Trends\GenesisClient;
use Platform\FoodAlchemist\Services\Trends\GenesisCsvLeser;
use Platform\FoodAlchemist\Support\ObservatoriumInhalt;
use Platform\FoodAlchemist\Support\RechercheRelevanz;
use Platform\FoodAlchemist\Support\SichererAbruf;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Erfundenes Flat-File-CSV im offiziellen Format, keine echten Destatis-Werte. */
function marktCsv(string $code = 'CC13-01', string $wert = '110,0', string $einheit = '2020=100'): string
{
    return "time;1_variable_attribute_code;2_variable_attribute_code;value;value_unit;value_variable_label;value_q\n"
        ."2025;MONAT09;{$code};100,0;2020=100;Verbraucherpreisindex;\n"
        ."2026;MONAT09;{$code};{$wert};{$einheit};Verbraucherpreisindex;p\n";
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->owner = $this->makeUser($this->rootTeam, 'TEST Marktdaten');
    $this->viewer = $this->makeUser($this->rootTeam, 'TEST Marktdaten Leser', 'viewer');
    $this->actingAs($this->owner);
    $this->travelTo(Carbon::parse('2026-11-05 09:00:00 UTC'));
    Queue::fake();
    Http::preventStrayRequests();
    config(['foodalchemist.marktdaten.genesis_token' => 'TEST-KEIN-ECHTER-TOKEN']);
    $this->svc = app(MarktdatenService::class);
});

function marktHttp(string $wert = '110,0', string $einheit = '2020=100'): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([GenesisClient::ENDPOINT => fn ($r) => Http::response(marktCsv($r['classifyingkey1'], $wert, $einheit), 200)]);
}

function marktZip(array $dateien): string
{
    $tmp = tmpfile();
    $pfad = stream_get_meta_data($tmp)['uri'];
    $zip = new ZipArchive;
    $zip->open($pfad, ZipArchive::OVERWRITE);
    foreach ($dateien as $name => $inhalt) {
        $zip->addFromString($name, $inhalt);
    }
    $zip->close();
    // libzip ersetzt beim Schreiben die Datei; der alte tmpfile-Handle zeigt noch auf den alten Inode.
    $inhalt = file_get_contents($pfad);
    fclose($tmp);

    return $inhalt;
}

it('liest echte GENESIS-CSV-Strukturen aus begrenzten ZIP-Antworten und erhält unbekannte Monate', function () {
    Http::fake([GenesisClient::ENDPOINT => function ($r) {
        $serie = $r['classifyingkey1'] === 'CC13-01' ? 'lebensmittelpreise' : 'gaststaettenpreise';
        $csv = file_get_contents(dirname(__DIR__).'/Fixtures/destatis/'.$serie.'.csv');

        return Http::response(marktZip(['61111-0004.csv' => $csv]), 200, ['Content-Type' => 'application/zip']);
    }]);
    $ergebnis = $this->svc->abrufen($this->rootTeam, true);
    $vpi = collect($ergebnis['ergebnisse'])->whereIn('serie', ['lebensmittelpreise', 'gaststaettenpreise'])->values()->all();
    expect(array_column($vpi, 'fehler'))->toBe([null, null])
        ->and($vpi[0]['letzter_wert_monat'])->toBe('2026-09')
        ->and($vpi[0]['monate_mit_wert'])->toBe(2);
    $reihen = $this->svc->lesen($this->rootTeam)['reihen'];
    expect($reihen[0]['aktuell']['monat'])->toBe('2026-09')->and($reihen[0]['aktuell']['wert'])->toEqual(137.2)
        ->and($reihen[1]['aktuell']['wert'])->toEqual(137.8)->and($reihen[0]['neuere_monate_ohne_wert'])->toBe(1)
        ->and($reihen[0]['punkte'])->toHaveCount(3)->and($reihen[0]['punkte'][2]['wert'])->toBeNull();
    Livewire::test(Index::class)->call('ansichtSetzen', 'zahlen')->assertSee('137,2')
        ->assertSee('2026-09')->assertSee('Für neuere Monate liegen noch keine Werte vor.');
});

it('verweigert mehrdeutige ZIP-Inhalte und begrenzt die entpackte Größe', function () {
    foreach ([['a.csv' => marktCsv(), 'b.csv' => marktCsv()], ['datei.txt' => marktCsv()], ['gross.csv' => str_repeat('x', 2 * 1024 * 1024 + 1)]] as $dateien) {
        Http::swap(new Factory);
        Http::fake([GenesisClient::ENDPOINT => Http::response(marktZip($dateien), 200)]);
        expect(fn () => app(GenesisClient::class)->laden('61111-0004', 'CC13A5', 'CC13-01'))->toThrow(RuntimeException::class);
    }
    expect(Daten::count())->toBe(0);
});

it('zeigt fehlenden Zugang ohne Netzwerk, Zahlen oder Erfolg zu erfinden', function () {
    config(['foodalchemist.marktdaten.genesis_token' => '']);
    $r = $this->svc->abrufen($this->rootTeam);
    expect($r['probelauf'])->toBeTrue()->and($r['ergebnisse'][0]['fehler'])->toContain('Zugang fehlt')
        ->and(Daten::count())->toBe(0)->and($this->svc->lesen($this->rootTeam)['zugang_eingerichtet'])->toBeFalse();
    Http::assertNothingSent();
    Livewire::test(Index::class)->call('ansichtSetzen', 'zahlen')->assertSee('Noch nicht verbunden')->assertSee('Markt in Zahlen')
        ->assertSee('keine Einkaufs- oder Betriebskosten')->call('ansichtSetzen', 'news')->assertDontSeeHtml('data-observatorium-marktdaten');
});

it('importiert per POST idempotent ohne KI und übernimmt Revisionen mit Quelle und Monatsbasis', function () {
    marktHttp();
    expect(collect($this->svc->abrufen($this->rootTeam)['ergebnisse'])->firstWhere('serie', 'lebensmittelpreise')['monate'])->toBe(2)->and(Daten::count())->toBe(0);
    $this->svc->abrufen($this->rootTeam, true);
    $this->svc->abrufen($this->rootTeam, true);
    $r = $this->svc->lesen($this->rootTeam)['reihen'][0];
    expect(Daten::whereNotNull('abgerufen_at')->count())->toBe(2)->and($r['aktuell']['wert'])->toEqual(110.0)->and($r['aktuell']['original']['value_q'])->toBe('p')
        ->and($r['vorjahr_prozent'])->toBe(10.0)->and($r['aktuell']['monat'])->toBe('2026-09')->and($r['quelle'])->toContain('61111-0004');
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->hasHeader('username', 'TEST-KEIN-ECHTER-TOKEN') && $r['compress'] === 'false');
    marktHttp('115,0');
    $this->svc->abrufen($this->rootTeam, true);
    expect($this->svc->lesen($this->rootTeam)['reihen'][0]['vorjahr_prozent'])->toBe(15.0)->and(Daten::whereNotNull('abgerufen_at')->count())->toBe(2);
    Queue::assertNothingPushed();
});

it('erhält ältere Daten bei Fehlern und verrät keine Zugangsdaten', function () {
    marktHttp();
    $this->svc->abrufen($this->rootTeam, true);
    $stand = Daten::where('serie', 'lebensmittelpreise')->first()->abgerufen_at->toIso8601String();
    $this->travel(2)->days();
    Http::swap(new Factory);
    Http::fake([GenesisClient::ENDPOINT => Http::response('TEST-KEIN-ECHTER-TOKEN', 401)]);
    $this->svc->abrufen($this->rootTeam, true);
    expect(Daten::where('serie', 'lebensmittelpreise')->first()->abgerufen_at->toIso8601String())->toBe($stand)
        ->and(Daten::where('serie', 'lebensmittelpreise')->first()->letzter_fehler)->toContain('HTTP 401')->not->toContain('TEST-KEIN-ECHTER-TOKEN')
        ->and($this->svc->lesen($this->rootTeam)['reihen'][0]['aktuell']['wert'])->toEqual(110.0);
});

it('behandelt fehlende Zahlen und unterschiedliche Indexbasen nicht als Wachstum', function () {
    marktHttp('.');
    $this->svc->abrufen($this->rootTeam, true);
    expect($this->svc->lesen($this->rootTeam)['reihen'][0]['aktuell']['monat'])->toBe('2025-09')
        ->and($this->svc->lesen($this->rootTeam)['reihen'][0]['punkte'][1]['wert'])->toBeNull()
        ->and($this->svc->lesen($this->rootTeam)['reihen'][0]['neuere_monate_ohne_wert'])->toBe(1)
        ->and($this->svc->lesen($this->rootTeam)['reihen'][0]['vorjahr_prozent'])->toBeNull();
    marktHttp('110,0', '2025=100');
    $this->svc->abrufen($this->rootTeam, true);
    expect($this->svc->lesen($this->rootTeam)['reihen'][0]['vorjahr_prozent'])->toBeNull();
});

it('lehnt HTML, API-Fehler, doppelte und unvollständige CSV-Zeilen ab', function () {
    $leser = app(GenesisCsvLeser::class);
    foreach (['<html>Login</html>', '{"Code":15}', marktCsv()."2026;MONAT09;CC13-01;111,0;2020=100;Verbraucherpreisindex;\n", marktCsv()."2026;kaputt\n"] as $csv) {
        expect(fn () => $leser->lesen($csv, 'CC13-01'))->toThrow(RuntimeException::class);
    }
});

it('isoliert Marktdaten und verhindert Abrufe durch Leser auch per MCP', function () {
    marktHttp();
    $this->svc->abrufen($this->rootTeam, true);
    $this->svc->abrufen($this->childA, true);
    $eltern = Daten::where('team_id', $this->rootTeam->id)->first()->punkte;
    $this->actingAs($this->makeUser($this->childB, 'TEST anderes Team'));
    expect($this->svc->lesen($this->childB)['reihen'][0]['team_id'])->toBe($this->rootTeam->id);
    $this->svc->abrufen($this->childB, true);
    expect(Daten::where('team_id', $this->rootTeam->id)->first()->punkte)->toBe($eltern)->and(Daten::whereNotNull('abgerufen_at')->count())->toBe(6);
    $this->actingAs($this->viewer);
    expect(fn () => $this->svc->abrufen($this->rootTeam, true))->toThrow(RuntimeException::class);
    $reg = app(ToolRegistry::class);
    expect($reg->has('foodalchemist.marktdaten.GET'))->toBeTrue()->and($reg->has('foodalchemist.marktdaten.ABRUF'))->toBeTrue();
    $r = $reg->get('foodalchemist.marktdaten.ABRUF')->execute(['apply' => true], new ToolContext($this->viewer, $this->rootTeam));
    expect($r->success)->toBeFalse();
});

it('zeigt gespeicherte Monatswerte mobil ohne Render-Netzwerkaufruf', function () {
    marktHttp();
    $this->svc->abrufen($this->rootTeam, true);
    Http::fake();
    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148 Safari/604.1']);
    Livewire::test(Index::class)->call('ansichtSetzen', 'zahlen')->assertSeeHtml('data-observatorium-marktdaten')
        ->assertSee('2026-09')->assertSee('110,0')->assertSee('10,0')->assertSee('Original-Kennzeichnungen');
    Http::assertNothingSent();
});

it('filtert eindeutige fachfremde RSS-Treffer vor der KI mit derselben Vorschau', function () {
    app()->instance(SichererAbruf::class, new SichererAbruf(fn () => ['93.184.216.34']));
    $q = app(TrendQuellenService::class)->anlegen($this->rootTeam, ['name' => 'TEST RSS', 'url' => 'https://rss.test/feed', 'feed_typ' => 'rss', 'abruf_weg' => 'plattform'])['quelle'];
    Http::fake(['https://rss.test/feed' => Http::response('<rss><channel><item><title>Sturm und Salzburg: Die Aufstellungen</title><link>https://rss.test/sport</link></item><item><title>Stadion-Catering: neue Getränke zur Bundesliga</title><link>https://rss.test/catering</link></item></channel></rss>')]);
    $preview = app(TrendQuellenService::class)->probelauf($this->rootTeam, $q->id);
    expect($preview['gefiltert'])->toHaveCount(1)->and($preview['durch'])->toHaveCount(1);
    $r = app(TrendEingangService::class)->quelleAbrufen($q);
    expect($r['neu'])->toBe(1)->and($r['gefiltert'])->toBe(1);
    expect(RechercheRelevanz::grund('Restaurant meldet Insolvenz'))->toBeNull()
        ->and(RechercheRelevanz::grund('Neuer Küchenchef im Restaurant'))->toBeNull()
        ->and(RechercheRelevanz::grund('Aktienanalyse: neues Kursziel'))->not->toBeNull();
    expect(ObservatoriumInhalt::einordnen(['titel' => 'Stadion-Catering: Speiseangebot zu den Bundesliga-Ergebnissen'])['verwendungen'])->toContain('news');
});

it('formuliert die Klartext-Aussage nur aus den abgerufenen Werten und blendet Monate ohne Wert aus der Tabelle', function () {
    Http::fake([GenesisClient::ENDPOINT => function ($r) {
        $wert = $r['classifyingkey1'] === 'CC13-111' ? '103,1' : '100,3';

        return Http::response(marktCsv($r['classifyingkey1'], $wert)."2026;MONAT10;{$r['classifyingkey1']};...;2020=100;Verbraucherpreisindex;\n", 200);
    }]);
    $this->svc->abrufen($this->rootTeam, true);
    $m = $this->svc->lesen($this->rootTeam);
    expect($m['aussage']['text'])->toBe('Restaurantpreise +3,1 %, Lebensmittel +0,3 % zum Vorjahresmonat: Die Restaurantpreise steigen stärker als die Lebensmittelpreise im Handel (Abstand 2,8 Prozentpunkte).')
        ->and($m['aussage']['monat'])->toBe('2026-09')->and($m['aussage']['quellen'])->toBe(['61111-0004'])
        ->and(array_column($m['reihen'][0]['tabelle_werte'], 'monat'))->toBe(['2026-09', '2025-09'])
        ->and($m['reihen'][0]['tabelle_werte'][0])->not->toHaveKey('original');
    Livewire::test(Index::class)->call('ansichtSetzen', 'zahlen')->assertSeeHtml('data-marktdaten-aussage')
        ->assertSee('Die Restaurantpreise steigen stärker')->assertDontSee('unbekannt')->assertSeeHtml('fa-obs-marktdaten-tabelle');
});

it('trifft keine Aussage bei fehlendem Vergleich, verschiedener Indexbasis oder Bezugsmonat und sagt „etwa gleich“ unter der Schwelle', function () {
    // Beide Reihen aus derselben Tabelle 61111-0004: dort muss auch die Indexbasis gleich sein.
    $reihe = fn (string $serie, ?float $vj, string $monat = '2026-09', string $basis = '2020=100') => ['serie' => $serie, 'tabelle' => '61111-0004', 'vorjahr_prozent' => $vj, 'aktuell' => ['monat' => $monat, 'einheit' => $basis]];
    expect(MarktdatenService::aussage([$reihe('gaststaettenpreise', 3.1), $reihe('lebensmittelpreise', null)]))->toBeNull()
        ->and(MarktdatenService::aussage([$reihe('gaststaettenpreise', 3.1), $reihe('lebensmittelpreise', 0.3, '2026-08')]))->toBeNull()
        ->and(MarktdatenService::aussage([$reihe('gaststaettenpreise', 3.1), $reihe('lebensmittelpreise', 0.3, '2026-09', '2025=100')]))->toBeNull()
        ->and(MarktdatenService::aussage([$reihe('gaststaettenpreise', 2.0), $reihe('lebensmittelpreise', 1.7)])['text'])->toContain('Beide entwickeln sich etwa gleich (Abstand 0,3 Prozentpunkte)')
        ->and(MarktdatenService::aussage([$reihe('gaststaettenpreise', -0.4), $reihe('lebensmittelpreise', -2.0)])['text'])->toContain('Die Restaurantpreise sinken weniger stark als die Lebensmittelpreise im Handel')
        ->and(MarktdatenService::aussage([$reihe('gaststaettenpreise', 1.0), $reihe('lebensmittelpreise', 4.0)])['text'])->toContain('Die Lebensmittelpreise im Handel steigen stärker als die Restaurantpreise');
});

/** Gespeicherte Reihe ohne Netz: Werte erfunden, nur Form wie der CSV-Leser sie ablegt. */
function marktReihe(int $teamId, string $serie, float $vorjahr, float $jetzt, string $basis = '2021=100'): void
{
    Daten::create(['team_id' => $teamId, 'serie' => $serie, 'abgerufen_at' => now(), 'versucht_at' => now(), 'punkte' => [
        ['monat' => '2025-09', 'wert' => $vorjahr, 'einheit' => $basis, 'original' => []],
        ['monat' => '2026-09', 'wert' => $jetzt, 'einheit' => $basis, 'original' => []],
    ]]);
}

it('nimmt den Großhandel statt des VPI Lebensmittel, sobald er Werte hat, mit Unterzeilen und Kalkulations-Aussage über Tabellen hinweg', function () {
    $t = $this->rootTeam->id;
    marktReihe($t, 'lebensmittelpreise', 100.0, 100.3, '2020=100');
    marktReihe($t, 'gaststaettenpreise', 100.0, 103.1, '2020=100');
    expect(array_column($this->svc->lesen($this->rootTeam)['reihen'], 'serie'))->toBe(['lebensmittelpreise', 'gaststaettenpreise']);

    marktReihe($t, 'grosshandel_nahrungsmittel', 100.0, 105.0);
    marktReihe($t, 'grosshandel_fleisch', 100.0, 108.2);
    $m = $this->svc->lesen($this->rootTeam);
    expect(array_column($m['reihen'], 'serie'))->toBe(['grosshandel_nahrungsmittel', 'gaststaettenpreise'])
        ->and(array_column($m['reihen'][0]['unterreihen'], 'serie'))->toBe(['grosshandel_fleisch'])
        ->and($m['aussagen'][0]['text'])->toBe('Großhandel Nahrungsmittel +5,0 %, Restaurantpreise +3,1 % zum Vorjahresmonat: Die Großhandelspreise steigen stärker als die Restaurantpreise (Abstand 1,9 Prozentpunkte) – der Einkauf verteuert sich schneller, die Kalkulation gerät unter Druck.')
        ->and($m['aussagen'][0]['quellen'])->toBe(['61281-0004', '61111-0004']);
    Livewire::test(Index::class)->call('ansichtSetzen', 'zahlen')->assertSeeHtml('data-marktdaten-unterreihen')->assertSee('+8,2 %')
        ->assertSee('Originaltabelle 61281-0004 ansehen')->assertDontSee('Nahrungsmittel & alkoholfreie Getränke (Verbraucherpreisindex)');
});

it('formuliert Umsatz-, Vorlauf- und Energie-Aussagen nur bei Werten im selben Monat', function () {
    $r = fn (string $serie, ?float $vj, string $monat = '2026-09') => ['serie' => $serie, 'tabelle' => MarktdatenService::REIHEN[$serie]['tabelle'], 'vorjahr_prozent' => $vj, 'aktuell' => ['monat' => $monat, 'einheit' => '2015=100']];
    $text = fn (array $reihen) => array_column(MarktdatenService::aussagen($reihen), 'text', 'schluessel');
    expect($text([$r('gastro_umsatz_nominal', 2.4), $r('gastro_umsatz_real', -1.1)])['umsatz'])
        ->toBe('Gastronomie-Umsatz nominal +2,4 %, real -1,1 % zum Vorjahresmonat: Das Plus kommt aus höheren Preisen, nicht aus mehr Gästen oder Absatz.')
        ->and($text([$r('gastro_umsatz_nominal', 3.0), $r('gastro_umsatz_real', 0.8)])['umsatz'])->toContain('Auch preisbereinigt wird mehr umgesetzt.')
        ->and($text([$r('gastro_umsatz_nominal', -0.5), $r('gastro_umsatz_real', -2.0)])['umsatz'])->toContain('Die Branche setzt auch in Euro weniger um.')
        ->and($text([$r('gastro_umsatz_nominal', 3.0), $r('gastro_umsatz_real', 0.8), $r('caterer_umsatz_nominal', 4.1), $r('caterer_umsatz_real', 1.2)])['umsatz'])
            ->toBe('Umsatz Caterer nominal +4,1 %, real +1,2 % zum Vorjahresmonat: Auch preisbereinigt wird mehr umgesetzt.')
        ->and($text([$r('gastro_umsatz_nominal', 3.0), $r('gastro_umsatz_real', 0.8), $r('caterer_umsatz_nominal', 4.1), $r('caterer_umsatz_real', null)])['umsatz'])
            ->toStartWith('Gastronomie-Umsatz nominal +3,0 %')
        ->and($text([$r('gastro_umsatz_nominal', 3.0), $r('gastro_umsatz_real', 0.8, '2026-08')]))->not->toHaveKey('umsatz')
        ->and($text([$r('erzeugerpreise_nahrungsmittel', 4.0), $r('grosshandel_nahrungsmittel', 1.5)])['vorlauf'])
            ->toContain('Bei den Herstellern steigen die Preise stärker als im Großhandel (Abstand 2,5 Prozentpunkte) – Druck auf künftige Einkaufspreise möglich.')
        ->and($text([$r('erzeugerpreise_nahrungsmittel', 0.5), $r('grosshandel_nahrungsmittel', 2.0)])['vorlauf'])->toContain('Im Großhandel steigen die Preise stärker als bei den Herstellern')
        ->and(MarktdatenService::REIHEN)->not->toHaveKey('strom_gewerbe');   // GP19-35 enthält Gas — kein „Strom“-Etikett
});

it('ruft jede Reihe mit ihren Codes ab, Erkunden schlägt nur nach und schreibt nichts', function () {
    Http::fake([
        GenesisClient::ENDPOINT => fn ($r) => Http::response(marktCsv($r['classifyingkey1']), 200),
        GenesisClient::METADATEN_TABELLE => Http::response(['Status' => ['Code' => 0, 'Content' => 'erfolgreich', 'Type' => 'Information'],
            'Object' => ['Code' => '61281-0004', 'Content' => 'Index der Großhandelsverkaufspreise (TEST)', 'Time' => ['From' => '2005'], 'Structure' => ['Columns' => [['Code' => 'TESTWZ', 'Content' => 'TEST-Wirtschaftszweige']]]]]),
        GenesisClient::MERKMAL_WERTE => Http::response(['Status' => ['Code' => 0, 'Type' => 'Information'], 'List' => [
            ['Code' => 'TEST-4632', 'Content' => 'Großhandel mit Fleisch (TEST)'], ['Code' => 'TEST-4633', 'Content' => 'Großhandel mit Milch (TEST)']]]),
    ]);
    $r = $this->svc->abrufen($this->rootTeam);
    expect(array_column($r['ergebnisse'], 'serie'))->toBe(array_keys(MarktdatenService::REIHEN))->and($r['noch_nicht_abrufbar'])->toBe([]);
    Http::assertSentCount(count(MarktdatenService::REIHEN));
    Http::assertSent(fn ($q) => $q->url() === GenesisClient::ENDPOINT && $q['name'] === '45213-0005' && $q['classifyingvariable1'] === 'WZ08W9' && $q['classifyingkey1'] === 'WZ08-56');
    Http::assertSent(fn ($q) => $q->url() === GenesisClient::ENDPOINT && $q['name'] === '45213-0005' && $q['classifyingkey1'] === 'WZ08-562');

    $aufbau = $this->svc->erkunden($this->rootTeam, '61281-0004');
    $werte = $this->svc->erkunden($this->rootTeam, '61281-0004', 'TESTWZ', 'fleisch');
    expect($aufbau['aufbau']['struktur']['Columns'][0]['Code'])->toBe('TESTWZ')
        ->and($werte['werte'])->toBe([['code' => 'TEST-4632', 'inhalt' => 'Großhandel mit Fleisch (TEST)']])
        ->and(fn () => $this->svc->erkunden($this->rootTeam, '99999-0001'))->toThrow(RuntimeException::class, 'Nur konfigurierte Tabellen')
        ->and(fn () => $this->svc->erkunden($this->rootTeam, '61281-0004', 'x; drop'))->toThrow(RuntimeException::class, 'GENESIS-Code')
        ->and(Daten::count())->toBe(0);

    $tool = app(ToolRegistry::class)->get('foodalchemist.marktdaten.ABRUF');
    expect($tool->execute(['erkunden_tabelle' => '61281-0004', 'erkunden_merkmal' => 'TESTWZ', 'suche' => 'milch'], new ToolContext($this->owner, $this->rootTeam))->data['werte'][0]['code'])->toBe('TEST-4633')
        ->and($tool->execute(['erkunden_tabelle' => '61281-0004'], new ToolContext($this->viewer, $this->rootTeam))->success)->toBeFalse()
        ->and(Daten::count())->toBe(0);

    Http::swap(new Factory);
    Http::fake([GenesisClient::METADATEN_TABELLE => Http::response(['Status' => ['Code' => 15, 'Content' => 'Nicht berechtigt', 'Type' => 'ERROR']])]);
    expect(fn () => $this->svc->erkunden($this->rootTeam, '61281-0004'))->toThrow(RuntimeException::class, 'GENESIS-Fehler: Nicht berechtigt');
});

it('liest je Reihe nur die passende Wertvariable und die Pflicht-Schlüssel', function () {
    $csv = "time;1_variable_attribute_code;2_variable_attribute_code;value;value_unit;value_variable_label;value_q\n"
        ."2026;MONAT09;TESTWZ-56;104,0;2015=100;Umsatz in jeweiligen Preisen (nominal);\n"
        ."2026;MONAT09;TESTWZ-56;97,0;2015=100;Umsatz in konstanten Preisen (real);\n"
        ."2026;MONAT08;TESTWZ-56;103,0;2015=100;Umsatz in jeweiligen Preisen (nominal);\n";
    $leser = app(GenesisCsvLeser::class);
    expect(array_column($leser->lesen($csv, 'TESTWZ-56', 'real'), 'wert'))->toEqual([97.0])
        ->and(array_column($leser->lesen($csv, 'TESTWZ-56', 'nominal'), 'wert'))->toEqual([103.0, 104.0])
        ->and(fn () => $leser->lesen($csv, 'TESTWZ-56', 'umsatz'))->toThrow(RuntimeException::class, 'Mehrere GENESIS-Werte')
        ->and(fn () => $leser->lesen($csv, 'TESTWZ-56', 'nominal', ['MONAT12']))->toThrow(RuntimeException::class, 'Keine passenden');
});

it('liest Reihen mit Wertvariable per Code (PRE011, UMS103) und Pflicht-Schlüsseln (REAL/NOMINAL, Originalwert)', function () {
    $kopf = "time;1_variable_attribute_code;2_variable_attribute_code;3_variable_attribute_code;4_variable_attribute_code;value;value_unit;value_variable_code;value_variable_label\n";
    $csv = $kopf
        ."2026;MONAT09;WZ08-56;NOMINAL;WERTORG;104,0;2015=100;UMS103;Umsatzindex TEST\n"
        ."2026;MONAT09;WZ08-56;REAL;WERTORG;97,0;2015=100;UMS103;Umsatzindex TEST\n"
        ."2026;MONAT09;WZ08-56;REAL;X13JDKSB;96,5;2015=100;UMS103;Umsatzindex TEST\n"
        ."2026;MONAT09;WZ08-56;REAL;WERTORG;1,2;Prozent;CH0005;Veränderung TEST\n";
    $leser = app(GenesisCsvLeser::class);
    $real = MarktdatenService::REIHEN['gastro_umsatz_real'];
    expect(array_column($leser->lesen($csv, $real['schluessel'], '', $real['zusatz'], $real['wert_code']), 'wert'))->toEqual([97.0])
        ->and(array_column($leser->lesen($csv, 'WZ08-56', '', ['NOMINAL', 'WERTORG'], 'UMS103'), 'wert'))->toEqual([104.0])
        ->and(fn () => $leser->lesen($csv, 'WZ08-56', '', ['REAL'], 'UMS103'))->toThrow(RuntimeException::class, 'Mehrere GENESIS-Werte');
    foreach (MarktdatenService::REIHEN as $serie => $meta) {
        expect(MarktdatenService::abrufbar($meta))->toBeTrue("{$serie} ohne Codes")
            ->and(isset($meta['wert']) || isset($meta['wert_code']))->toBeTrue("{$serie} ohne Wertvariable")
            ->and(MarktdatenService::TABELLEN)->toContain($meta['tabelle']);
    }
});

it('Kantinen-Aussage gegen den Großhandel (sonst gegen Restaurants), Detailzeilen klappbar mit stärkster Bewegung und Ladenpreisen getrennt', function () {
    $r = fn (string $serie, ?float $vj) => ['serie' => $serie, 'tabelle' => MarktdatenService::REIHEN[$serie]['tabelle'], 'vorjahr_prozent' => $vj, 'aktuell' => ['monat' => '2026-09', 'einheit' => '2020=100']];
    $text = fn (array $reihen) => array_column(MarktdatenService::aussagen($reihen), 'text', 'schluessel');
    expect($text([$r('grosshandel_nahrungsmittel', 4.0), $r('kantinenpreise', 2.5)])['kantine'])
        ->toBe('Großhandel Nahrungsmittel +4,0 %, Kantinen und Mensen +2,5 % zum Vorjahresmonat: Die Großhandelspreise steigen stärker als die Kantinenpreise (Abstand 1,5 Prozentpunkte) – in der Gemeinschaftsverpflegung wird die Kalkulation enger.')
        ->and($text([$r('kantinenpreise', 2.5), $r('gaststaettenpreise', 3.1)])['kantine'])->toContain('Die Restaurantpreise steigen stärker als die Kantinenpreise')
        ->and($text([$r('kantinenpreise', null), $r('gaststaettenpreise', 3.1)]))->not->toHaveKey('kantine');

    $t = $this->rootTeam->id;
    marktReihe($t, 'grosshandel_nahrungsmittel', 100.0, 104.0);
    marktReihe($t, 'grosshandel_fleisch', 100.0, 103.0);
    marktReihe($t, 'grosshandel_getraenke', 100.0, 93.5);
    marktReihe($t, 'vpi_rind', 100.0, 106.1, '2020=100');
    marktReihe($t, 'kantinenpreise', 100.0, 102.5, '2020=100');
    $m = $this->svc->lesen($this->rootTeam);
    $gh = collect($m['reihen'])->firstWhere('serie', 'grosshandel_nahrungsmittel');
    expect(array_column($gh['unterreihen'], 'serie'))->toBe(['grosshandel_fleisch', 'grosshandel_getraenke', 'vpi_rind'])
        ->and($gh['staerkste_unterreihe']['serie'])->toBe('grosshandel_getraenke')
        ->and(array_column($m['reihen'], 'serie'))->toContain('kantinenpreise')->not->toContain('vpi_rind')
        ->and(array_column($m['aussagen'], 'schluessel'))->toContain('kantine');
    Livewire::test(Index::class)->call('ansichtSetzen', 'zahlen')->assertSee('3 Detailzeilen')->assertSee('stärkste Bewegung: Getränke -6,5 %')
        ->assertSee('Verbraucherpreise im Laden (zum Vergleich)')->assertSee('Rind- und Kalbfleisch')->assertSee('Kantinen und Mensen');
});

it('Teil-Abruf: reihen begrenzt Abruf und Schreiben auf die genannten Reihen, unbekannte werden abgelehnt', function () {
    marktHttp();
    $r = $this->svc->abrufen($this->rootTeam, true, ['gaststaettenpreise', 'kantinenpreise']);
    expect(array_column($r['ergebnisse'], 'serie'))->toBe(['gaststaettenpreise', 'kantinenpreise'])
        ->and(Daten::pluck('serie')->sort()->values()->all())->toBe(['gaststaettenpreise', 'kantinenpreise']);
    Http::assertSentCount(2);
    expect(fn () => $this->svc->abrufen($this->rootTeam, false, ['gibt_es_nicht']))->toThrow(RuntimeException::class, 'Unbekannte Reihen: gibt_es_nicht');
    $tool = app(ToolRegistry::class)->get('foodalchemist.marktdaten.ABRUF');
    $t = $tool->execute(['reihen' => ['lebensmittelpreise']], new ToolContext($this->owner, $this->rootTeam));
    expect($t->success)->toBeTrue()->and(array_column($t->data['ergebnisse'], 'serie'))->toBe(['lebensmittelpreise'])
        ->and($tool->execute(['reihen' => ['x']], new ToolContext($this->owner, $this->rootTeam))->success)->toBeFalse();
});

it('Großhandel 61281-0004: je Monat zählt nur die Indexzeile (2021=100), die beiden %-Veränderungsraten mit gleichem PRE011 fallen raus', function () {
    // Aufbau wie der echte Kopf auf io (05.11.), Werte erfunden
    $csv = file_get_contents(dirname(__DIR__).'/Fixtures/destatis/grosshandel_61281.csv');
    $gh = MarktdatenService::REIHEN['grosshandel_nahrungsmittel'];
    $punkte = app(GenesisCsvLeser::class)->lesen($csv, $gh['schluessel'], $gh['wert'] ?? '', $gh['zusatz'] ?? [], $gh['wert_code'] ?? null);
    expect(array_column($punkte, 'monat'))->toBe(['2025-05', '2026-05', '2026-06'])
        ->and(array_column($punkte, 'wert'))->toEqual([124.0, 130.2, null])
        ->and(array_unique(array_column($punkte, 'einheit')))->toBe(['2021=100']);

    Http::fake([GenesisClient::ENDPOINT => Http::response($csv, 200)]);
    $r = $this->svc->abrufen($this->rootTeam, true, ['grosshandel_nahrungsmittel']);
    expect($r['ergebnisse'][0]['fehler'])->toBeNull()->and($r['ergebnisse'][0]['einheit'])->toBe('2021=100');
    expect(collect($this->svc->lesen($this->rootTeam)['reihen'])->firstWhere('serie', 'grosshandel_nahrungsmittel')['vorjahr_prozent'])->toBe(5.0);
});

it('trennt Markt in Zahlen als eigenen Reiter von Markt & Chancen (Dominique 07.11.2026)', function () {
    config(['foodalchemist.marktdaten.genesis_token' => '']);
    expect(array_keys(ObservatoriumInhalt::ANSICHTEN))->toContain('markt', 'zahlen')
        ->and(ObservatoriumInhalt::ANSICHTEN['zahlen'][0])->toBe('Markt in Zahlen');
    Livewire::test(Index::class)->call('ansichtSetzen', 'markt')->assertDontSeeHtml('data-observatorium-marktdaten')->assertSee('Chancen prüfen')
        ->call('ansichtSetzen', 'zahlen')->assertSeeHtml('data-observatorium-marktdaten')->assertDontSee('Alle Marktbewegungen')->assertDontSee('Frische Perspektiven für deine Branche');
});

it('holt Markt in Zahlen monatlich per Kommando ins Master-Team und plant den Lauf am 25. (Dominique 07.11.2026)', function () {
    marktHttp();
    config(['foodalchemist.master_team_id' => null]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();
    \Illuminate\Support\Facades\Auth::logout();
    $this->artisan('foodalchemist:marktdaten-abrufen')->expectsOutputToContain('Kein Master-Team')->assertSuccessful();
    expect(Daten::count())->toBe(0);

    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();
    $this->artisan('foodalchemist:marktdaten-abrufen', ['--probelauf' => true])->expectsOutputToContain('Probelauf')->assertSuccessful();
    expect(Daten::count())->toBe(0);
    $this->artisan('foodalchemist:marktdaten-abrufen')->expectsOutputToContain('gespeichert')->assertSuccessful();
    expect(Daten::where('team_id', $this->rootTeam->id)->whereNotNull('abgerufen_at')->count())->toBeGreaterThan(0)
        ->and(Daten::where('team_id', '!=', $this->rootTeam->id)->count())->toBe(0);
    config(['foodalchemist.master_team_id' => null]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();

    $plan = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'foodalchemist:marktdaten-abrufen'));
    expect($plan)->not->toBeNull()->and($plan->expression)->toBe('20 6 25 * *');
});
