<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Console\TrendThemenAehnlichkeitCommand;
use Platform\FoodAlchemist\Jobs\TrendEingangErschliessenJob;
use Platform\FoodAlchemist\Livewire\Trendradar\Eingang as EingangReiter;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendBeleg;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\TrendEingangService;
use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Services\TrendThemenService;
use Platform\FoodAlchemist\Support\SichererAbruf;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 93 B · Erschließen (KI, dual) und Bündeln zu automatischen Themen. Plattform-Abruf/Klick → Plattform-KI; MCP → KI-Auftrag
 * (der Plattform-Provider wirft, wenn er gerufen wird), ANTWORT startet den Job neu, Ergebnis gleich. Bündeln über die
 * normalisierte Schreibweise; automatische Themen nie auf der Pinnwand; ein von Hand gesetztes Thema bleibt. Testdaten „TEST-93“.
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
    $this->stub = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public bool $werfen = false;

        /** @var array<string,mixed> */
        public array $werte = ['relevant' => true, 'zusammenfassung' => 'TEST-93 eigene Kurzfassung.', 'thema' => 'Fermentation',
            'kategorie' => 'food', 'sparten' => ['betriebsgastronomie', 'quatsch'], 'tiefe' => 'beduerfnis',
            'tiefe_begruendung' => 'Gesundheit und Geschmack.', 'passt_zu_trend_id' => null, 'begruendung' => 'passt'];

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;
            if ($this->werfen) {
                throw new \RuntimeException('Plattform-Provider darf im MCP-Weg nicht gerufen werden');
            }

            return ['content' => json_encode(['werte' => $this->werte, 'confidence' => 0.9, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE),
                'usage' => [], 'model' => 'stub-1', 'tool_calls' => null];
        }
    };
    app()->instance(LLMProviderContract::class, $this->stub);
    $this->quelle = FoodAlchemistTrendQuelle::create(['team_id' => $this->rootTeam->id, 'name' => 'TEST-93 Fachblatt', 'url' => 'https://fachblatt.beispiel.test/',
        'art' => 'branchenquelle', 'feed_typ' => 'manuell', 'abruf_weg' => 'mcp', 'rhythmus' => 'aus', 'aktiv' => true]);
    $this->rein = fn (string $pfad, string $titel, $team = null, string $von = 'abruf') => $this->eingang->einreichen($team ?? $this->rootTeam,
        ['url' => 'https://artikel.beispiel.test/'.$pfad, 'titel' => $titel, 'teaser' => 'TEST-93 Teaser', 'quelle_id' => ($team ?? $this->rootTeam)->is($this->rootTeam) ? $this->quelle->id : null], $von)['eintrag'];
    $this->antworteOffene = function () {
        $this->stub->werfen = false;
        $vorher = $this->stub->calls;
        $antworten = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->get()->map(fn ($a) => [
            'auftrag_id' => $a->uuid, 'werte' => json_decode($this->stub->chat(json_decode($a->messages, true), [])['content'], true)])->all();
        $this->stub->calls = $vorher;

        return $this->reg->get('foodalchemist.ki_auftraege.ANTWORT')->execute(['antworten' => $antworten, 'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5'], $this->mcp);
    };
});

it('Plattformweg: Merkmale werden übernommen und geprüft, Artikel landet als Beleg in einem automatischen Thema', function () {
    $e = ($this->rein)('a', 'TEST-93 Koji in der Kantine')->refresh();

    expect($this->stub->calls)->toBe(1)
        ->and($e->status)->toBe('gebuendelt')->and($e->relevant)->toBeTrue()
        ->and($e->zusammenfassung)->toBe('TEST-93 eigene Kurzfassung.')
        ->and($e->thema)->toBe('Fermentation')->and($e->thema_quelle)->toBe('ki')
        ->and($e->kategorie)->toBe('food')->and($e->sparten)->toBe(['betriebsgastronomie'])   // ungültige Sparte fällt weg
        ->and($e->tiefe)->toBe('beduerfnis')->and($e->erschlossen_at)->not->toBeNull();
    $thema = FoodAlchemistTrendInspiration::find($e->thema_inspiration_id);
    expect($thema->herkunft)->toBe('automatisch')->and($thema->thema_schluessel)->toBe('fermentation')
        ->and($thema->quellen()->count())->toBe(1);
    $beleg = $thema->quellen()->first();
    expect($beleg->fundstueck)->toBeFalse()->and($beleg->quelle)->toBe('branchenquelle')->and($beleg->trend_id)->toBeNull()
        ->and($e->inspiration_id)->toBeNull();   // nicht auf der Pinnwand
});

it('Bündeln: gleiche normalisierte Schreibweise → dasselbe Thema, anderes Thema → neues', function () {
    $a = ($this->rein)('a', 'TEST-93 Eins');
    $this->stub->werte['thema'] = '  FERMENTATION! ';
    $b = ($this->rein)('b', 'TEST-93 Zwei');
    $this->stub->werte['thema'] = 'Bowl-Konzepte';
    $c = ($this->rein)('c', 'TEST-93 Drei');

    expect(TrendThemenService::schluessel('Fermentation!'))->toBe('fermentation')
        ->and(TrendThemenService::schluessel('Bowl-Konzepte'))->toBe('bowl konzepte')
        ->and($b->refresh()->thema_inspiration_id)->toBe($a->refresh()->thema_inspiration_id)
        ->and($c->refresh()->thema_inspiration_id)->not->toBe($a->thema_inspiration_id)
        ->and(FoodAlchemistTrendInspiration::where('herkunft', 'automatisch')->count())->toBe(2)
        ->and(FoodAlchemistTrendInspiration::find($a->thema_inspiration_id)->quellen()->count())->toBe(2);
});

it('Nicht relevant → verworfen mit Begründung; ungültige Antwort → fehler; passt_zu_trend_id nur aus der Liste', function () {
    $this->stub->werte = ['relevant' => false, 'begruendung' => 'Personalie ohne Food-Bezug'];
    $v = ($this->rein)('v', 'TEST-93 Personalie')->refresh();
    expect($v->status)->toBe('verworfen')->and($v->relevant)->toBeFalse()->and($v->begruendung)->toBe('Personalie ohne Food-Bezug')
        ->and($v->thema_inspiration_id)->toBeNull();

    $this->stub->werte = ['zusammenfassung' => 'ohne relevant'];
    expect(($this->rein)('f', 'TEST-93 Kaputt')->refresh()->status)->toBe('fehler');

    $trend = app(TrendService::class)->anlegen($this->rootTeam, ['name' => 'TEST-93 Fermentation']);
    $this->stub->werte = ['relevant' => true, 'thema' => 'Kimchi', 'passt_zu_trend_id' => 999999];
    expect(($this->rein)('t1', 'TEST-93 Fremd-ID')->refresh()->passt_zu_trend_id)->toBeNull();
    $this->stub->werte['passt_zu_trend_id'] = $trend->id;
    expect(($this->rein)('t2', 'TEST-93 Echte ID')->refresh()->passt_zu_trend_id)->toBe($trend->id);
});

it('Vergleich Plattform = MCP: per MCP KI-Auftrag statt Provider (der wirft), bis zur Antwort nichts geschrieben, danach gleiches Ergebnis', function () {
    $plattform = ($this->rein)('p', 'TEST-93 Plattform')->refresh();
    $callsPlattform = $this->stub->calls;

    $this->stub->werfen = true;
    $r = $this->reg->get('foodalchemist.trend_eingang.POST')->execute(['quelle_id' => $this->quelle->id,
        'url' => 'https://artikel.beispiel.test/m', 'titel' => 'TEST-93 MCP', 'teaser' => 'TEST-93 Teaser'], $this->mcp);
    $mcp = FoodAlchemistTrendEingang::find($r->data['ergebnisse'][0]['id']);

    expect($this->stub->calls)->toBe($callsPlattform, 'Plattform-Provider im MCP-Weg nicht gerufen')
        ->and($mcp->status)->toBe('neu')->and($mcp->zusammenfassung)->toBeNull()
        ->and(DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->where('feature', 'trend.eingang_erschliessen')->count())->toBe(1);

    expect(($this->antworteOffene)()->success)->toBeTrue();
    $mcp->refresh();
    expect($mcp->only(['status', 'thema', 'kategorie', 'tiefe', 'sparten', 'zusammenfassung', 'thema_inspiration_id']))
        ->toBe($plattform->only(['status', 'thema', 'kategorie', 'tiefe', 'sparten', 'zusammenfassung', 'thema_inspiration_id']))
        ->and($this->stub->calls)->toBe($callsPlattform);
});

it('Manuelles Thema: KI fasst es nie wieder an; Thema ändern hängt um, leeres automatisches Thema verschwindet; verwerfen nimmt aus dem Thema', function () {
    $e = ($this->rein)('a', 'TEST-93 Umhängen')->refresh();
    $alt = $e->thema_inspiration_id;

    $this->eingang->entscheiden($this->rootTeam, $e->id, 'thema', 'Koji');
    $e->refresh();
    expect($e->thema)->toBe('Koji')->and($e->thema_quelle)->toBe('manuell')->and($e->status)->toBe('gebuendelt')
        ->and($e->thema_inspiration_id)->not->toBe($alt)
        ->and(FoodAlchemistTrendInspiration::find($alt))->toBeNull()
        ->and(FoodAlchemistTrendInspiration::find($e->thema_inspiration_id)->titel)->toBe('Koji');

    // erneuter Lauf (z. B. nach Fehler): die KI liefert „Fermentation“, das Thema bleibt „Koji“
    $e->update(['status' => 'fehler']);
    (new TrendEingangErschliessenJob($e->id, $this->rootTeam->id))->handle(app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class));
    expect($e->refresh()->thema)->toBe('Koji')->and($e->thema_quelle)->toBe('manuell');

    $this->eingang->entscheiden($this->rootTeam, $e->id, 'verwerfen');
    expect($e->refresh()->thema_inspiration_id)->toBeNull()
        ->and(FoodAlchemistTrendInspiration::where('herkunft', 'automatisch')->count())->toBe(0);
});

it('Pinnwand zeigt nur manuelle Inspirationen; „Auf die Pinnwand“ legt eine manuelle an, das automatische Thema bleibt getrennt', function () {
    $e = ($this->rein)('a', 'TEST-93 Pinnwand-Trennung');
    $svc = app(TrendService::class);
    expect($svc->inspirationen($this->rootTeam, 'alle'))->toHaveCount(0)
        ->and($this->reg->get('foodalchemist.fundstuecke.GET')->execute([], $this->mcp)->data['anzahl'])->toBe(0);

    $this->eingang->entscheiden($this->rootTeam, $e->id, 'pinnwand');
    $pinn = $svc->inspirationen($this->rootTeam, 'alle');
    expect($pinn)->toHaveCount(1)->and($pinn->first()->herkunft)->toBe('manuell')
        ->and($e->refresh()->inspiration_id)->toBe((int) $pinn->first()->id)
        ->and($e->thema_inspiration_id)->not->toBe($e->inspiration_id)
        ->and(TrendThemenService::schluessel($pinn->first()->titel))->not->toBe('');   // Schlüssel auch für manuelle berechenbar (C)
});

it('Grenzen: ERSCHLIESSEN nimmt höchstens 50 (Config) je Aufruf, per MCP extern; Abruf stößt nur die neuen an (N je Quelle)', function () {
    Queue::fake([TrendEingangErschliessenJob::class]);
    config(['foodalchemist.trends.quellen.erschliessen_max_je_aufruf' => 2]);
    foreach (['x1', 'x2', 'x3'] as $p) {
        ($this->rein)($p, 'TEST-93 '.$p);
    }
    Queue::assertPushed(TrendEingangErschliessenJob::class, 3);
    Queue::assertPushed(TrendEingangErschliessenJob::class, fn ($j) => $j->extern === false);   // Abruf = Plattform-KI

    $r = $this->reg->get('foodalchemist.trend_eingang.ERSCHLIESSEN')->execute([], $this->mcp);
    expect($r->success)->toBeTrue()->and($r->data['angestossen'])->toBe(2);
    Queue::assertPushed(TrendEingangErschliessenJob::class, fn ($j) => $j->extern === true);

    $leser = $this->makeUser($this->rootTeam, 'Leser TEST-93', 'viewer');
    expect($this->reg->get('foodalchemist.trend_eingang.ERSCHLIESSEN')->execute([], new ToolContext($leser, $this->rootTeam))->errorCode)->toBe('FORBIDDEN');

    // Abruf: höchstens N neue je Quelle → höchstens N Jobs
    config(['foodalchemist.trends.quellen.max_neue_je_abruf' => 1]);
    Http::fake(['*' => Http::response(file_get_contents(dirname(__DIR__).'/Fixtures/spec93/feed.xml'))]);
    $q = FoodAlchemistTrendQuelle::create(['team_id' => $this->rootTeam->id, 'name' => 'TEST-93 Feed', 'url' => 'https://fachblatt.beispiel.test/feed',
        'art' => 'presse', 'feed_typ' => 'rss', 'abruf_weg' => 'plattform', 'rhythmus' => 'taeglich', 'aktiv' => true]);
    $vorher = Queue::pushed(TrendEingangErschliessenJob::class)->count();
    $this->eingang->quelleAbrufen($q);
    expect(Queue::pushed(TrendEingangErschliessenJob::class)->count() - $vorher)->toBe(1);
});

it('Team-Sicht: Kunde B sieht die Themen von Kunde A nicht; Themen gehören dem Team des Artikels', function () {
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $e = ($this->rein)('ka', 'TEST-93 Kunde A', $this->childA)->refresh();
    $thema = FoodAlchemistTrendInspiration::find($e->thema_inspiration_id);

    expect((int) $thema->team_id)->toBe((int) $this->childA->id)
        ->and(app(TrendThemenService::class)->themen($this->childB)->pluck('id')->all())->not->toContain($thema->id)
        ->and(app(TrendThemenService::class)->themen($this->childA)->pluck('id')->all())->toContain($thema->id);
});

it('Mess-Befehl: --team ist Pflicht, ohne Provider nur Hinweis, schreibt nichts; Kosinus stimmt', function () {
    ($this->rein)('a', 'TEST-93 A');
    $this->stub->werte['thema'] = 'Bowls';
    ($this->rein)('b', 'TEST-93 B');
    $vorher = [FoodAlchemistTrendInspiration::count(), FoodAlchemistTrendBeleg::count(), FoodAlchemistTrendEingang::count()];

    expect(Artisan::call('foodalchemist:trend-themen-aehnlichkeit'))->toBe(1);
    expect(Artisan::call('foodalchemist:trend-themen-aehnlichkeit', ['--team' => $this->rootTeam->id]))->toBe(0);
    expect([FoodAlchemistTrendInspiration::count(), FoodAlchemistTrendBeleg::count(), FoodAlchemistTrendEingang::count()])->toBe($vorher)
        ->and(config('foodalchemist.trends.quellen.buendel_aehnlichkeit'))->toBeNull()
        ->and(round(TrendThemenAehnlichkeitCommand::kosinus([1.0, 0.0], [1.0, 0.0]), 3))->toBe(1.0)
        ->and(round(TrendThemenAehnlichkeitCommand::kosinus([1.0, 0.0], [0.0, 1.0]), 3))->toBe(0.0);
});

it('UI: Themen-Block und Einordnung im Eingang, „Erschließen“ stößt offene an (Plattform-KI)', function () {
    $e = ($this->rein)('ui', 'TEST-93 UI-Thema');
    $this->stub->werte = ['zusammenfassung' => 'kaputt'];
    $f = ($this->rein)('ui2', 'TEST-93 UI-Fehler');

    Livewire::test(EingangReiter::class)
        ->assertSeeHtml('data-eingang-themen')->assertSee('Fermentation · 1')
        ->assertSee('Thema: Fermentation')
        ->assertSee('Bedürfnis (Tiefe)')
        ->assertSeeHtml('data-eingang-erschliessen')->assertSee('KI-Antwort ohne gültiges Feld relevant');
    $this->stub->werte = ['relevant' => true, 'thema' => 'Fermentation'];
    Livewire::test(EingangReiter::class)->call('erschliessen')->assertSee('1 Artikel werden erschlossen');
    expect($f->refresh()->status)->toBe('gebuendelt');
});
