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
    expect(array_column($ergebnis['ergebnisse'], 'fehler'))->toBe([null, null])
        ->and($ergebnis['ergebnisse'][0]['letzter_wert_monat'])->toBe('2026-09')
        ->and($ergebnis['ergebnisse'][0]['monate_mit_wert'])->toBe(2);
    $reihen = $this->svc->lesen($this->rootTeam)['reihen'];
    expect($reihen[0]['aktuell']['monat'])->toBe('2026-09')->and($reihen[0]['aktuell']['wert'])->toEqual(137.2)
        ->and($reihen[1]['aktuell']['wert'])->toEqual(137.8)->and($reihen[0]['neuere_monate_ohne_wert'])->toBe(1)
        ->and($reihen[0]['punkte'])->toHaveCount(3)->and($reihen[0]['punkte'][2]['wert'])->toBeNull();
    Livewire::test(Index::class)->call('ansichtSetzen', 'markt')->assertSee('137,2')
        ->assertSee('2026-09')->assertSee('Für neuere Monate liegen noch keine Werte vor.');
});

it('verweigert mehrdeutige ZIP-Inhalte und begrenzt die entpackte Größe', function () {
    foreach ([['a.csv' => marktCsv(), 'b.csv' => marktCsv()], ['datei.txt' => marktCsv()], ['gross.csv' => str_repeat('x', 2 * 1024 * 1024 + 1)]] as $dateien) {
        Http::swap(new Factory);
        Http::fake([GenesisClient::ENDPOINT => Http::response(marktZip($dateien), 200)]);
        expect(fn () => app(GenesisClient::class)->laden('CC13-01'))->toThrow(RuntimeException::class);
    }
    expect(Daten::count())->toBe(0);
});

it('zeigt fehlenden Zugang ohne Netzwerk, Zahlen oder Erfolg zu erfinden', function () {
    config(['foodalchemist.marktdaten.genesis_token' => '']);
    $r = $this->svc->abrufen($this->rootTeam);
    expect($r['probelauf'])->toBeTrue()->and($r['ergebnisse'][0]['fehler'])->toContain('Zugang fehlt')
        ->and(Daten::count())->toBe(0)->and($this->svc->lesen($this->rootTeam)['zugang_eingerichtet'])->toBeFalse();
    Http::assertNothingSent();
    Livewire::test(Index::class)->call('ansichtSetzen', 'markt')->assertSee('Noch nicht verbunden')->assertSee('Markt in Zahlen')
        ->assertSee('keine Einkaufs- oder Betriebskosten')->call('ansichtSetzen', 'news')->assertDontSeeHtml('data-observatorium-marktdaten');
});

it('importiert per POST idempotent ohne KI und übernimmt Revisionen mit Quelle und Monatsbasis', function () {
    marktHttp();
    expect($this->svc->abrufen($this->rootTeam)['ergebnisse'][0]['monate'])->toBe(2)->and(Daten::count())->toBe(0);
    $this->svc->abrufen($this->rootTeam, true);
    $this->svc->abrufen($this->rootTeam, true);
    $r = $this->svc->lesen($this->rootTeam)['reihen'][0];
    expect(Daten::count())->toBe(2)->and($r['aktuell']['wert'])->toEqual(110.0)->and($r['aktuell']['original']['value_q'])->toBe('p')
        ->and($r['vorjahr_prozent'])->toBe(10.0)->and($r['aktuell']['monat'])->toBe('2026-09')->and($r['quelle'])->toContain('61111-0004');
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->hasHeader('username', 'TEST-KEIN-ECHTER-TOKEN') && $r['compress'] === 'false');
    marktHttp('115,0');
    $this->svc->abrufen($this->rootTeam, true);
    expect($this->svc->lesen($this->rootTeam)['reihen'][0]['vorjahr_prozent'])->toBe(15.0)->and(Daten::count())->toBe(2);
    Queue::assertNothingPushed();
});

it('erhält ältere Daten bei Fehlern und verrät keine Zugangsdaten', function () {
    marktHttp();
    $this->svc->abrufen($this->rootTeam, true);
    $stand = Daten::first()->abgerufen_at->toIso8601String();
    $this->travel(2)->days();
    Http::swap(new Factory);
    Http::fake([GenesisClient::ENDPOINT => Http::response('TEST-KEIN-ECHTER-TOKEN', 401)]);
    $this->svc->abrufen($this->rootTeam, true);
    expect(Daten::first()->abgerufen_at->toIso8601String())->toBe($stand)
        ->and(Daten::first()->letzter_fehler)->toContain('HTTP 401')->not->toContain('TEST-KEIN-ECHTER-TOKEN')
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
    expect(Daten::where('team_id', $this->rootTeam->id)->first()->punkte)->toBe($eltern)->and(Daten::count())->toBe(6);
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
    Livewire::test(Index::class)->call('ansichtSetzen', 'markt')->assertSeeHtml('data-observatorium-marktdaten')
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
