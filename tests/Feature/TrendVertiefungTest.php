<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\TrendEingangVertiefenJob;
use Platform\FoodAlchemist\Livewire\Observatorium\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\TrendVertiefungService;
use Platform\FoodAlchemist\Support\KiUmleitung;
use Platform\FoodAlchemist\Support\SichererAbruf;
use Platform\FoodAlchemist\Support\TrendUrl;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Vertiefung im Trend-Eingang: Zielseite lesen, Kernaussagen mit Zahl/Bezug/Fundstelle, Zahlen-Riegel, Bezahl-/Sperr-Erkennung
 * ohne KI-Aufruf, KI dual (Plattform + MCP-Auftrag), Tageslauf mit Deckel, Rechte, Anzeige. Alle Seiten und Zahlen sind erfunden.
 * Testdaten „TEST-VT“.
 */
const VT_SEITE = <<<'HTML'
<html><body><nav><a href="/">Start</a> 999 Menüpunkte</nav><header><h1>Kopf der Website</h1></header>
<main><article><h1>TEST-VT Küchenbarometer 2026</h1>
<p>Online-Befragung im Auftrag eines TEST-Herstellers, durchgeführt im vierten Quartal 2025 unter insgesamt 240 Küchenleitungen in vier Ländern.</p>
<h2>Kosten</h2><p>In Deutschland berichten 64 % der Befragten von deutlich gestiegenen Energiekosten in den vergangenen zwölf Monaten; über alle Länder sind es 51 %.</p>
<h2>Personal</h2><p>Als größte Herausforderung nennen 58 % den Mangel an Fachkräften. Eine Modernisierung der Küchentechnik planen 81 %.</p>
<p>Die Ergebnisse beruhen auf Selbstauskünften und sind nicht repräsentativ für die gesamte Branche.</p></article></main>
<footer>Impressum 12345 Musterstadt</footer></body></html>
HTML;

function vtEintrag(int $teamId, array $daten = []): FoodAlchemistTrendEingang
{
    $url = $daten['url'] ?? 'https://studie.beispiel.test/barometer';

    return FoodAlchemistTrendEingang::create(array_merge(['team_id' => $teamId, 'url' => $url, 'url_hash' => TrendUrl::hash($url),
        'titel' => 'TEST-VT Küchenbarometer', 'status' => 'verworfen', 'eingereicht_von' => 'mcp',
        'observatorium' => ['verwendungen' => ['markt'], 'branchen' => [], 'herkunft' => 'ki']], $daten));
}

function vtAntwort(array $kern, string $zugang = 'frei'): array
{
    return ['zugang' => $zugang, 'kernaussagen' => $kern, 'hinweis' => 'Auftragsstudie eines TEST-Herstellers, Selbstauskunft.',
        'methodik' => ['stichprobe' => '240 Küchenleitungen in vier Ländern', 'zeitraum' => 'Q4/2025', 'auftraggeber' => 'TEST-Hersteller', 'methode' => 'Online-Befragung']];
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Inhaber TEST-VT');
    $this->leser = $this->makeUser($this->rootTeam, 'Leser TEST-VT', 'viewer');
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    Http::preventStrayRequests();
    app()->instance(SichererAbruf::class, new SichererAbruf(fn () => ['93.184.216.34']));
    $this->reg = app(ToolRegistry::class);
    $this->svc = app(TrendVertiefungService::class);
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $this->stub = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public array $werte = [];

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;

            return ['content' => json_encode(['werte' => $this->werte, 'confidence' => 0.9, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub-1', 'tool_calls' => null];
        }
    };
    $this->stub->werte = vtAntwort([
        ['aussage' => 'In Deutschland melden 64 % deutlich gestiegene Energiekosten.', 'zahl' => '64 %', 'bezug' => 'Deutschland, letzte zwölf Monate', 'fundstelle' => 'Abschnitt Kosten'],
        ['aussage' => 'Über alle Länder liegt der Anteil bei 51 %.', 'zahl' => '51 %', 'bezug' => 'alle vier Länder', 'fundstelle' => 'Abschnitt Kosten'],
        ['aussage' => 'Erfunden: 73 % wollen auf Induktion umstellen.', 'zahl' => '73 %', 'bezug' => 'Deutschland', 'fundstelle' => 'nirgends'],
        ['aussage' => 'Eine Modernisierung der Küchentechnik planen 81 %.', 'zahl' => '81 %', 'bezug' => 'alle Länder', 'fundstelle' => 'Abschnitt Personal'],
    ]);
    app()->instance(LLMProviderContract::class, $this->stub);
});

it('liest den Haupttext ohne Navigation/Kopf/Fuß und erkennt Bezahlschranke, Bot-Sperre und Login-Wand ohne KI', function () {
    Http::fake([
        'https://studie.beispiel.test/barometer' => Http::response(VT_SEITE),
        'https://bezahl.beispiel.test/*' => Http::response('Payment Required', 402),
        'https://gesperrt.beispiel.test/*' => Http::response('Forbidden', 403),
        'https://login.beispiel.test/*' => Http::response('<html><body><main><h1>Dossier TEST-VT</h1><p>Bitte anmelden.</p><form><input type="password"></form></main></body></html>'),
        'https://leer.beispiel.test/*' => Http::response('<html><body><p>Kurz.</p></body></html>'),
        // ohne <main>/<article>: dann zählt der ganze body — Navigation und Fuß müssen trotzdem raus
        'https://ohnemain.beispiel.test/*' => Http::response(str_replace(['<main>', '</main>', '<article>', '</article>'], '', VT_SEITE)),
    ]);
    $frei = $this->svc->quelleLesen('https://studie.beispiel.test/barometer');
    expect($frei['zugang'])->toBe('frei')->and($frei['art'])->toBe('html')
        ->and($frei['text'])->toContain('64 % der Befragten')->not->toContain('999 Menüpunkte')->not->toContain('12345 Musterstadt')->not->toContain('Kopf der Website')
        ->and($this->svc->quelleLesen('https://ohnemain.beispiel.test/x')['text'])->toContain('64 % der Befragten')->not->toContain('999 Menüpunkte')->not->toContain('12345 Musterstadt')
        ->and($this->svc->quelleLesen('https://bezahl.beispiel.test/x')['zugang'])->toBe('bezahl')
        ->and($this->svc->quelleLesen('https://gesperrt.beispiel.test/x')['zugang'])->toBe('gesperrt')
        ->and($this->svc->quelleLesen('https://login.beispiel.test/x')['zugang'])->toBe('bezahl')
        ->and($this->svc->quelleLesen('https://leer.beispiel.test/x')['zugang'])->toBe('nicht_lesbar')
        ->and($this->svc->quelleLesen('http://127.0.0.1/intern')['zugang'])->toBe('nicht_lesbar');
});

it('Zahlen-Riegel: nur Aussagen, deren Zahlen im Text stehen; Tausender und Dezimal werden vereinheitlicht', function () {
    expect(TrendVertiefungService::zahlen('64 %, 1.234 Betriebe, 3,5 Mrd., 2.500 €, 12 500 Gäste'))->toBe(['64', '1234', '3,5', '2500', '12500'])
        ->and(TrendVertiefungService::zahlenBelegt('64 % in Deutschland', 'berichten 64 % der Befragten'))->toBeTrue()
        ->and(TrendVertiefungService::zahlenBelegt('rund 65 %', 'berichten 64 % der Befragten'))->toBeFalse()
        ->and(TrendVertiefungService::zahlenBelegt('1.234 Betriebe', 'insgesamt 1234 Betriebe'))->toBeTrue()
        ->and(TrendVertiefungService::zahlenBelegt('Ohne Zahl', 'beliebig'))->toBeTrue();
});

it('Plattform (Knopf): ein KI-Aufruf, erfundene Zahl fliegt raus, Kernaussagen + Methodik gespeichert, kein Volltext', function () {
    Http::fake(['https://studie.beispiel.test/*' => Http::response(VT_SEITE)]);
    $e = vtEintrag($this->rootTeam->id);
    Livewire::test(Index::class)->call('ansichtSetzen', 'markt')->call('oeffnen', $e->id)->call('vertiefen', $e->id)->assertSee('Vertiefung gestartet');

    $e->refresh();
    expect($this->stub->calls)->toBe(1)->and($e->vertiefung_status)->toBe('fertig')
        ->and(array_column($e->vertiefung['kernaussagen'], 'zahl'))->toBe(['64 %', '51 %', '81 %'])
        ->and($e->vertiefung['verworfen'])->toBe(1)->and($e->vertiefung['methodik']['stichprobe'])->toBe('240 Küchenleitungen in vier Ländern')
        ->and($e->vertiefung['ausloeser'])->toBe('hand')->and(json_encode($e->vertiefung))->not->toContain('Selbstauskünften und sind nicht repräsentativ');

    Livewire::test(Index::class)->call('ansichtSetzen', 'markt')->call('oeffnen', $e->id)
        ->assertSeeHtml('data-observatorium-vertiefung="fertig"')->assertSee('In Deutschland melden 64 % deutlich gestiegene Energiekosten.')
        ->assertSee('Fundstelle: Abschnitt Kosten')->assertDontSee('Erfunden: 73 %')->assertSee('Neu vertiefen');
    Livewire::test(Index::class)->call('ansichtSetzen', 'markt')->assertSeeHtml('data-observatorium-kern')->assertSee('Über alle Länder liegt der Anteil bei 51 %.')
        ->assertDontSee('Eine Modernisierung der Küchentechnik planen 81 %.');
});

it('Bezahlschranke bzw. reine Inhaltsangabe: Etikett statt Kataloghinweis; bei 402 kein KI-Aufruf', function () {
    Http::fake(['https://bezahl.beispiel.test/*' => Http::response('Payment Required', 402), 'https://studie.beispiel.test/*' => Http::response(VT_SEITE)]);
    $zu = vtEintrag($this->rootTeam->id, ['url' => 'https://bezahl.beispiel.test/dossier']);
    $katalog = vtEintrag($this->rootTeam->id);
    $this->svc->anstossen($this->rootTeam, [$zu->id], $this->user->id);
    expect($zu->refresh()->vertiefung_status)->toBe('bezahl')->and($this->stub->calls)->toBe(0);

    $this->stub->werte = vtAntwort([['aussage' => 'Das Dossier umfasst 240 Seiten zu Küchenleitungen.', 'zahl' => null, 'bezug' => null, 'fundstelle' => 'Katalog']], 'nur_inhaltsangabe');
    $this->svc->anstossen($this->rootTeam, [$katalog->id], $this->user->id);
    expect($katalog->refresh()->vertiefung_status)->toBe('bezahl')->and($katalog->vertiefung['zugang'])->toBe('bezahl');
    Livewire::test(Index::class)->call('ansichtSetzen', 'markt')->assertSeeHtml('data-observatorium-bezahl')->assertSee('Bezahlinhalt – nur Inhaltsangabe');
});

it('MCP: VERTIEFEN legt einen KI-Auftrag an (0 Provider-Calls), die Antwort läuft durch denselben Zahlen-Riegel', function () {
    Http::fake(['https://studie.beispiel.test/*' => Http::response(VT_SEITE)]);
    $e = vtEintrag($this->rootTeam->id);
    expect(KiUmleitung::LAUF_FLAG)->toHaveKey('foodalchemist.trend_eingang.VERTIEFEN');
    $r = $this->reg->get('foodalchemist.trend_eingang.VERTIEFEN')->execute(['ids' => [$e->id]], $this->mcp);
    $offen = DB::table('foodalchemist_ki_auftraege')->where('feature', 'trend.eingang_vertiefen')->where('status', 'offen');
    expect($r->success)->toBeTrue()->and($r->data['ids'])->toBe([$e->id])->and($this->stub->calls)->toBe(0)
        ->and($offen->count())->toBe(1)->and($e->refresh()->vertiefung_status)->toBe('laeuft');

    $antworten = $offen->get()->map(fn ($a) => ['auftrag_id' => $a->uuid, 'werte' => ['werte' => vtAntwort([
        ['aussage' => 'In Deutschland melden 64 % gestiegene Energiekosten.', 'zahl' => '64 %', 'bezug' => 'Deutschland', 'fundstelle' => 'Kosten'],
        ['aussage' => 'Erfunden: 99 % kochen sous-vide.', 'zahl' => '99 %', 'bezug' => 'Deutschland', 'fundstelle' => '—'],
    ]), 'confidence' => 0.9, 'reasoning' => 'TEST-VT']])->all();
    $a = $this->reg->get('foodalchemist.ki_auftraege.ANTWORT')->execute(['antworten' => $antworten, 'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5'], $this->mcp);
    expect($a->success)->toBeTrue();
    $e->refresh();
    expect($e->vertiefung_status)->toBe('fertig')->and(array_column($e->vertiefung['kernaussagen'], 'zahl'))->toBe(['64 %'])
        ->and($e->vertiefung['verworfen'])->toBe(1)->and($this->stub->calls)->toBe(0);
});

it('Tageslauf: nur Marktbewegungen und harte Quellen, höchstens auto_je_tag je Team, nie Google Trends, nie doppelt', function () {
    Http::fake(['*' => Http::response(VT_SEITE)]);
    config(['foodalchemist.trends.vertiefung.auto_je_tag' => 2]);
    $hart = FoodAlchemistTrendQuelle::create(['team_id' => $this->rootTeam->id, 'name' => 'TEST-VT Studie', 'url' => 'https://studie.beispiel.test/',
        'art' => 'marktforschung', 'feed_typ' => 'rss', 'abruf_weg' => 'mcp', 'rhythmus' => 'aus', 'aktiv' => true]);
    $markt = vtEintrag($this->rootTeam->id, ['url' => 'https://studie.beispiel.test/a']);
    $quelle = vtEintrag($this->rootTeam->id, ['url' => 'https://studie.beispiel.test/b', 'quelle_id' => $hart->id, 'observatorium' => ['verwendungen' => ['news']]]);
    $news = vtEintrag($this->rootTeam->id, ['url' => 'https://studie.beispiel.test/c', 'observatorium' => ['verwendungen' => ['news']]]);
    $google = vtEintrag($this->rootTeam->id, ['url' => 'https://trends.google.com/trends/explore?q=TEST']);
    $dritte = vtEintrag($this->rootTeam->id, ['url' => 'https://studie.beispiel.test/d']);

    Artisan::call('foodalchemist:trend-eingang-vertiefen');
    expect(FoodAlchemistTrendEingang::whereNotNull('vertiefung_status')->pluck('id')->sort()->values()->all())->toBe([$quelle->id, $dritte->id])
        ->and($dritte->refresh()->vertiefung['ausloeser'])->toBe('auto');
    Artisan::call('foodalchemist:trend-eingang-vertiefen');
    expect(FoodAlchemistTrendEingang::whereNotNull('vertiefung_status')->count())->toBe(2)
        ->and($news->refresh()->vertiefung_status)->toBeNull()->and($google->refresh()->vertiefung_status)->toBeNull();
    $this->travel(1)->days();
    Artisan::call('foodalchemist:trend-eingang-vertiefen');
    expect($markt->refresh()->vertiefung_status)->toBe('fertig')->and($news->refresh()->vertiefung_status)->toBeNull();
});

it('Rechte und Teams: Leser darf nicht vertiefen, fremde Einträge bleiben unberührt, Job ohne „läuft“ tut nichts', function () {
    Http::fake(['*' => Http::response(VT_SEITE)]);
    $eigen = vtEintrag($this->rootTeam->id);
    $fremd = vtEintrag($this->childB->id);
    expect($this->reg->get('foodalchemist.trend_eingang.VERTIEFEN')->execute(['ids' => [$eigen->id]], new ToolContext($this->leser, $this->rootTeam))->success)->toBeFalse()
        ->and($this->svc->anstossen($this->rootTeam, [$fremd->id], $this->user->id))->toBe([])
        ->and($fremd->refresh()->vertiefung_status)->toBeNull();
    $this->actingAs($this->leser);
    Livewire::test(Index::class)->call('ansichtSetzen', 'markt')->call('oeffnen', $eigen->id)->assertDontSeeHtml('data-observatorium-vertiefen')
        ->call('vertiefen', $eigen->id)->assertForbidden();
    (new TrendEingangVertiefenJob($eigen->id, $this->rootTeam->id))->handle(app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class));
    expect($eigen->refresh()->vertiefung_status)->toBeNull()->and($this->stub->calls)->toBe(0);
});
