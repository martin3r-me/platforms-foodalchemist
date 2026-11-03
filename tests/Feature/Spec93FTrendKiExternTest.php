<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Settings\Trendradar as TrendradarEinstellungen;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\TrendEingangService;
use Platform\FoodAlchemist\Services\Trends\TrendAbleitungService;
use Platform\FoodAlchemist\Support\SichererAbruf;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 93 · Trend-KI je Team „extern“ (Dominique 03.11., „Weg a“): Erschließen und Ableitung legen IMMER einen KI-Auftrag an — auch
 * vom Scheduler-Abruf und aus der Oberfläche —, beantwortet per MCP, keine Plattform-Kosten. Standard „plattform“ bleibt wie heute.
 * Ein abgelaufener Auftrag lässt den Eintrag „neu“, der nächste Anstoß legt einen neuen an. Testdaten „TEST-93“.
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

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;
            $werte = ['relevant' => true, 'zusammenfassung' => 'TEST-93', 'thema' => 'Fermentation', 'kategorie' => 'food', 'sparten' => [],
                'tiefe' => 'beduerfnis', 'tiefe_begruendung' => 'TEST-93', 'passt_zu_trend_id' => null, 'begruendung' => 'TEST-93'];

            return ['content' => json_encode(['werte' => $werte, 'confidence' => 0.9, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub-1', 'tool_calls' => null];
        }
    };
    app()->instance(LLMProviderContract::class, $this->stub);
    $this->feed = function () {
        Http::fake(['*' => Http::response(file_get_contents(dirname(__DIR__).'/Fixtures/spec93/feed.xml'))]);
        config(['foodalchemist.trends.quellen.max_neue_je_abruf' => 2]);

        return FoodAlchemistTrendQuelle::create(['team_id' => $this->rootTeam->id, 'name' => 'TEST-93 Feed', 'url' => 'https://fachblatt.beispiel.test/feed',
            'art' => 'presse', 'feed_typ' => 'rss', 'abruf_weg' => 'plattform', 'rhythmus' => 'taeglich', 'aktiv' => true]);
    };
    $this->offen = fn (string $feature = 'trend.eingang_erschliessen') => DB::table('foodalchemist_ki_auftraege')->where('feature', $feature)->where('status', 'offen');
    $this->antworten = function () {
        $a = ($this->offen)()->get()->map(fn ($x) => ['auftrag_id' => $x->uuid, 'werte' => ['werte' => ['relevant' => true, 'zusammenfassung' => 'TEST-93',
            'thema' => 'Koji', 'kategorie' => 'food', 'sparten' => [], 'tiefe' => 'unklar', 'tiefe_begruendung' => 'TEST-93', 'passt_zu_trend_id' => null,
            'begruendung' => 'TEST-93'], 'confidence' => 0.9, 'reasoning' => 'TEST-93']])->all();

        return $this->reg->get('foodalchemist.ki_auftraege.ANTWORT')->execute(['antworten' => $a, 'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5'], $this->mcp);
    };
});

it('Standard plattform: Scheduler-Abruf erschließt über den Plattform-Provider, keine KI-Aufträge (wie bisher)', function () {
    expect(app(TeamSettingsService::class)->trendKiWeg($this->rootTeam))->toBe('plattform');
    $this->eingang->quelleAbrufen(($this->feed)());

    expect($this->stub->calls)->toBe(2)->and(($this->offen)()->count())->toBe(0)
        ->and(FoodAlchemistTrendEingang::pluck('status')->unique()->all())->toBe(['gebuendelt']);
});

it('extern: Scheduler-Abruf legt je Eintrag einen KI-Auftrag an, 0 Provider-Calls, Einträge bleiben neu; nach der Antwort gebündelt', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['trend_ki_weg' => 'extern']);
    $this->eingang->quelleAbrufen(($this->feed)());

    expect($this->stub->calls)->toBe(0)->and(($this->offen)()->count())->toBe(2)
        ->and(FoodAlchemistTrendEingang::pluck('status')->unique()->all())->toBe(['neu']);

    expect(($this->antworten)()->data['angenommen'])->toBe(2);
    expect(FoodAlchemistTrendEingang::pluck('status')->unique()->all())->toBe(['gebuendelt'])
        ->and(FoodAlchemistTrendEingang::pluck('thema')->unique()->all())->toBe(['Koji'])
        ->and($this->stub->calls)->toBe(0)->and(($this->offen)()->count())->toBe(0);
});

it('extern: auch der Klick in der Oberfläche (ERSCHLIESSEN ohne MCP) und die Ableitung legen KI-Aufträge an, kein Provider', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['trend_ki_weg' => 'extern']);
    $url = 'https://b.test/klick';
    $e = FoodAlchemistTrendEingang::create(['team_id' => $this->rootTeam->id, 'url' => $url, 'url_hash' => hash('sha256', $url), 'titel' => 'TEST-93 Klick',
        'status' => 'neu', 'eingereicht_von' => 'abruf']);
    $this->eingang->erschliessenAnstossen($this->rootTeam, [$e->id], $this->user->id);
    expect($this->stub->calls)->toBe(0)->and(($this->offen)()->count())->toBe(1)->and($e->refresh()->status)->toBe('neu');

    $trend = app(\Platform\FoodAlchemist\Services\TrendService::class)->anlegen($this->rootTeam, ['name' => 'TEST-93 Koji'], $this->user->id);
    app(TrendAbleitungService::class)->anstossen($this->rootTeam, (int) $trend->id, $this->user->id);
    expect($this->stub->calls)->toBe(0)->and(($this->offen)('trend.ableitung')->count())->toBe(1)
        ->and($trend->refresh()->ableitung)->toBeNull();
});

it('extern: abgelaufener Auftrag (24 h) lässt den Eintrag neu, ohne Thema; der nächste Anstoß legt einen neuen Auftrag an', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['trend_ki_weg' => 'extern']);
    $this->eingang->quelleAbrufen(($this->feed)());
    $alt = ($this->offen)()->pluck('uuid')->all();
    expect($alt)->toHaveCount(2);

    $this->travel(25)->hours();
    $this->eingang->erschliessenAnstossen($this->rootTeam, null, $this->user->id);

    $e = FoodAlchemistTrendEingang::get();
    expect($e->pluck('status')->unique()->all())->toBe(['neu'])
        ->and($e->pluck('thema')->filter()->all())->toBe([])->and($e->pluck('thema_inspiration_id')->filter()->all())->toBe([])
        // die alten gelten nicht mehr (abgelaufen bzw. Ablaufzeit vorbei), je Eintrag ein neuer, gültiger Auftrag
        ->and(DB::table('foodalchemist_ki_auftraege')->whereIn('uuid', $alt)->where('status', 'offen')->where('laeuft_ab_at', '>', now())->count())->toBe(0)
        ->and(($this->offen)()->whereNotIn('uuid', $alt)->where('laeuft_ab_at', '>', now())->count())->toBe(2)
        ->and($this->stub->calls)->toBe(0);
});

it('Einstellung: per MCP (trend_automatik.PUT ki_weg) und in der Oberfläche setzbar, nur plattform|extern; GET zeigt sie', function () {
    $put = $this->reg->get('foodalchemist.trend_automatik.PUT');
    expect($put->execute(['ki_weg' => 'extern'], $this->mcp)->data['ki_weg'])->toBe('extern')
        ->and($put->execute(['ki_weg' => 'quatsch'], $this->mcp)->success)->toBeFalse()
        ->and($this->reg->get('foodalchemist.trend_automatik.GET')->execute([], $this->mcp)->data['ki_weg'])->toBe('extern');

    Livewire::test(TrendradarEinstellungen::class)->assertSet('kiWeg', 'extern')->assertSeeHtml('data-trendradar-ki-weg')
        ->call('bearbeitenStarten')->set('kiWeg', 'plattform')->call('speichern');
    expect(app(TeamSettingsService::class)->trendKiWeg($this->rootTeam))->toBe('plattform');
});
