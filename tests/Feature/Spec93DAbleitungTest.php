<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\TrendAbleitungJob;
use Platform\FoodAlchemist\Jobs\TrendEingangErschliessenJob;
use Platform\FoodAlchemist\Livewire\Trendradar\Ableitung as AbleitungBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendBeleg;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\TrendEingangService;
use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Services\Trends\TrendAbleitungService;
use Platform\FoodAlchemist\Services\Trends\TrendAutomatikService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 93 D · Ableitung „Was heißt das für uns“: Prüfung und Übernahme, nie über manuelle Notizen oder eine manuelle Ableitung,
 * automatisch nur bei Trends der Automatik, Planungs-Briefing nur ohne Fehler, KI dual (MCP = Auftrag, Plattform-Provider wirft),
 * ein Budget-Topf mit dem Erschließen, Team-Sicht, Oberfläche. Testdaten „TEST-93“.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Inhaber TEST-93');
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    $this->reg = app(ToolRegistry::class);
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->trends = app(TrendService::class);
    $this->ab = app(TrendAbleitungService::class);
    $this->stub = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public bool $werfen = false;

        public array $werte = ['kurzfazit' => 'TEST-93 Fermentation bringt Tiefe in die Kantine.',
            'bedeutung' => ['care' => 'Bekömmlich, aber Salz beachten.', 'betriebsgastronomie' => 'Als Topping günstig.', 'quatsch' => 'fällt weg'],
            'impulse' => [['art' => 'gericht', 'titel' => 'Kimchi-Bowl', 'beschreibung' => 'Reis, Kimchi, Ei'],
                ['art' => 'konzept', 'titel' => 'Ferment-Station', 'beschreibung' => 'Selbstbedienung'],
                ['art' => 'gericht', 'titel' => 'Miso-Karotten', 'beschreibung' => null]]];

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
    $this->trend = fn (array $d = []) => $this->trends->anlegen($this->rootTeam, array_merge(['name' => 'TEST-93 Fermentation '.Str::random(4), 'typ' => 'trend',
        'ebene' => 'konsum', 'kategorie' => 'food', 'definition' => 'TEST-93 Definition von Hand.', 'historische_einordnung' => 'TEST-93 Historie von Hand.',
        'einordnung_begruendung' => 'TEST-93 Begründung von Hand.'], $d));
    $this->lauf = fn ($t, bool $extern = false) => (new TrendAbleitungJob($t->id, (int) $t->team_id, $extern))->handle(app(AiGatewayService::class));
    $this->notizen = fn ($t) => json_encode($t->refresh()->only(['definition', 'historische_einordnung', 'einordnung_begruendung', 'name', 'typ', 'ebene', 'kategorie', 'status']));
});

it('Plattformweg: Ableitung wird geprüft übernommen (Sparten nur aus dem Vokabular), manuelle Notizen bleiben byte-gleich', function () {
    $t = ($this->trend)();
    $vorher = ($this->notizen)($t);
    ($this->lauf)($t);
    $t->refresh();

    expect($this->stub->calls)->toBe(1)->and($t->ableitung_quelle)->toBe('ki')->and($t->ableitung_fehler)->toBeNull()
        ->and(array_keys($t->ableitung['bedeutung']))->toBe(['betriebsgastronomie', 'care'])
        ->and(array_column($t->ableitung['impulse'], 'titel'))->toBe(['Kimchi-Bowl', 'Ferment-Station', 'Miso-Karotten'])
        ->and($t->ableitung['impulse'][1]['art'])->toBe('konzept')
        ->and(($this->notizen)($t))->toBe($vorher);
});

it('Ungültige Antwort → ableitung_fehler, die bisherige Ableitung bleibt; das Briefing nimmt die Ableitung nur ohne Fehler', function () {
    $t = ($this->trend)();
    ($this->lauf)($t);
    $gut = $t->refresh()->ableitung;
    $text = json_encode(app(PlanningSessionService::class)->trendKombination($this->rootTeam, [$t->id]), JSON_UNESCAPED_UNICODE);
    expect($text)->toContain('Was heißt das für uns')->and($text)->toContain('Gericht: Kimchi-Bowl')->and($text)->toContain('Konzept: Ferment-Station');

    $this->stub->werte['impulse'] = [['art' => 'gericht', 'titel' => 'nur einer'], ['art' => 'gericht', 'titel' => 'und zwei']];
    ($this->lauf)($t);
    $t->refresh();
    expect($t->ableitung_fehler)->toContain('2 Impulsen')->and($t->ableitung)->toBe($gut);
    $text = json_encode(app(PlanningSessionService::class)->trendKombination($this->rootTeam, [$t->id]), JSON_UNESCAPED_UNICODE);
    expect($text)->not->toContain('Was heißt das für uns')->and($text)->not->toContain('Kimchi-Bowl');
});

it('Manuell bearbeitete Ableitung gilt dauerhaft: Tool und Job überschreiben sie nicht; {} gibt sie wieder frei', function () {
    Queue::fake([TrendAbleitungJob::class]);
    $t = ($this->trend)();
    $put = $this->reg->get('foodalchemist.trends.PUT')->execute(['id' => $t->id, 'ableitung' => ['kurzfazit' => 'TEST-93 von Hand',
        'impulse' => [['art' => 'konzept', 'titel' => 'Unser Ferment-Tag']]]], $this->ctx);
    expect($put->success)->toBeTrue()->and($put->data['ableitung_quelle'])->toBe('manuell');

    $r = $this->reg->get('foodalchemist.trends.ABLEITUNG')->execute(['id' => $t->id], $this->ctx);
    expect($r->data['angestossen'])->toBeFalse();
    Queue::assertNotPushed(TrendAbleitungJob::class);
    ($this->lauf)($t);
    expect($this->stub->calls)->toBe(0)->and($t->refresh()->ableitung['kurzfazit'])->toBe('TEST-93 von Hand')->and($t->ableitung_quelle)->toBe('manuell');
    // zweiter Riegel: auch eine (z. B. verspätete) KI-Antwort wird über einer manuellen Ableitung nicht übernommen
    $this->ab->uebernehmen($t->refresh(), $this->stub->werte);
    expect($t->refresh()->ableitung['kurzfazit'])->toBe('TEST-93 von Hand')->and($t->ableitung_quelle)->toBe('manuell');

    $this->reg->get('foodalchemist.trends.PUT')->execute(['id' => $t->id, 'ableitung' => []], $this->ctx);
    expect($t->refresh()->ableitung)->toBeNull()->and($this->reg->get('foodalchemist.trends.ABLEITUNG')->execute(['id' => $t->id], $this->ctx)->data['angestossen'])->toBeTrue();
});

it('Auslöser: Trend der Automatik erreicht „geprüft“ → Ableitung angestoßen (MCP-Lauf extern); Bestandstrend nie automatisch', function () {
    Queue::fake([TrendAbleitungJob::class, TrendEingangErschliessenJob::class]);
    app(TeamSettingsService::class)->update($this->rootTeam, ['trend_automatik' => 'bis_geprueft', 'trend_automatik_schatten' => false]);
    $insp = FoodAlchemistTrendInspiration::create(['team_id' => $this->rootTeam->id, 'titel' => 'Koji', 'herkunft' => 'automatisch', 'thema_schluessel' => 'koji']);
    foreach ([[5, 'presse'], [40, 'branchenquelle'], [55, 'presse'], [100, 'presse']] as $i => [$tage, $art]) {
        $url = 'https://p'.$i.'.test/a';
        FoodAlchemistTrendEingang::create(['team_id' => $this->rootTeam->id, 'url' => $url, 'url_hash' => hash('sha256', $url), 'titel' => 'TEST-93 '.$i, 'status' => 'gebuendelt',
            'eingereicht_von' => 'abruf', 'thema' => 'Koji', 'thema_inspiration_id' => $insp->id, 'tiefe' => 'beduerfnis', 'kategorie' => 'food', 'relevant' => true]);
        FoodAlchemistTrendBeleg::create(['team_id' => $this->rootTeam->id, 'inspiration_id' => $insp->id, 'quelle' => $art, 'url' => $url,
            'titel' => 'TEST-93 '.$i, 'beobachtet_am' => now()->subDays($tage)->toDateString(), 'fundstueck' => false]);
    }
    $bestand = ($this->trend)();
    $this->trends->statusSetzen($this->rootTeam, $bestand->id, 'geprueft');
    Queue::assertNotPushed(TrendAbleitungJob::class);

    $r = $this->reg->get('foodalchemist.trend_automatik.LAUF')->execute([], $this->mcp);
    $neu = FoodAlchemistTrend::where('herkunft', 'automatisch')->firstOrFail();
    expect($r->success)->toBeTrue()->and($neu->status)->toBe('geprueft');
    Queue::assertPushed(TrendAbleitungJob::class, fn ($j) => $j->trendId === (int) $neu->id && $j->extern === true);
    Queue::assertNotPushed(TrendAbleitungJob::class, fn ($j) => $j->trendId === (int) $bestand->id);
});

it('Vergleich Plattform = MCP: per MCP KI-Auftrag statt Provider (der wirft), bis zur Antwort nichts geschrieben, danach gleiche Ableitung', function () {
    $p = ($this->trend)(['name' => 'TEST-93 Plattform']);
    ($this->lauf)($p);
    $calls = $this->stub->calls;

    $m = ($this->trend)(['name' => 'TEST-93 MCP']);
    $this->stub->werfen = true;
    expect($this->reg->get('foodalchemist.trends.ABLEITUNG')->execute(['id' => $m->id], $this->mcp)->data['angestossen'])->toBeTrue();
    expect($this->stub->calls)->toBe($calls)->and($m->refresh()->ableitung)->toBeNull()->and($m->ableitung_fehler)->toBeNull()
        ->and(DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->where('feature', 'trend.ableitung')->count())->toBe(1);

    $this->stub->werfen = false;
    $antworten = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->get()->map(fn ($a) => ['auftrag_id' => $a->uuid,
        'werte' => ['werte' => $this->stub->werte, 'confidence' => 0.9, 'reasoning' => 'stub']])->all();
    expect($this->reg->get('foodalchemist.ki_auftraege.ANTWORT')->execute(['antworten' => $antworten, 'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5'], $this->mcp)->success)->toBeTrue();
    expect($m->refresh()->ableitung)->toBe($p->refresh()->ableitung)->and($m->ableitung_quelle)->toBe('ki')->and($this->stub->calls)->toBe($calls);
});

it('Budget: ein Topf mit dem Erschließen; erreicht → Fehler am Trend, Provider nicht gerufen; MCP-Weg zählt nicht', function () {
    expect(TrendEingangService::TREND_KI_FEATURES)->toBe(['trend.eingang_erschliessen', 'trend.ableitung']);
    $t = ($this->trend)();
    app(TeamSettingsService::class)->update($this->rootTeam, ['trend_ki_budget_eur' => 1.0]);
    foreach (['trend.eingang_erschliessen' => 0.6, 'trend.ableitung' => 0.5] as $feature => $eur) {
        DB::table('foodalchemist_ai_call_log')->insert(['uuid' => (string) Str::uuid(), 'team_id' => $this->rootTeam->id, 'feature' => $feature,
            'kosten_usd' => $eur, 'usd_eur' => 1.0, 'created_at' => now(), 'updated_at' => now()]);
    }
    expect(app(TrendEingangService::class)->trendKiVerbrauch($this->rootTeam->id))->toBe(1.1)
        ->and(app(TrendEingangService::class)->budgetErreicht($this->rootTeam->id))->toBeTrue();

    ($this->lauf)($t);
    expect($t->refresh()->ableitung_fehler)->toBe('Trend-KI-Budget erreicht')->and($t->ableitung)->toBeNull()->and($this->stub->calls)->toBe(0);
    $this->stub->werfen = true;
    ($this->lauf)($t, true);   // extern: kein Budget-Stopp, sondern Auftrag
    expect(DB::table('foodalchemist_ki_auftraege')->where('feature', 'trend.ableitung')->count())->toBe(1)->and($this->stub->calls)->toBe(0);
});

it('Team-Sicht: Kunde B und ein Kind-Team erzeugen keine Ableitung an fremden Trends', function () {
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $a = $this->trends->anlegen($this->childA, ['name' => 'TEST-93 Kunde A']);
    $parent = ($this->trend)();
    $b = new ToolContext($this->makeUser($this->childB, 'B TEST-93'), $this->childB);
    $kind = new ToolContext($this->makeUser($this->childA, 'A TEST-93'), $this->childA);

    expect($this->reg->get('foodalchemist.trends.ABLEITUNG')->execute(['id' => $a->id], $b)->errorCode)->toBe('NOT_FOUND')
        ->and($this->reg->get('foodalchemist.trends.ABLEITUNG')->execute(['id' => $parent->id], $kind)->errorCode)->toBe('NOT_FOUND')
        ->and($this->reg->get('foodalchemist.trends.PUT')->execute(['id' => $parent->id, 'ableitung' => ['kurzfazit' => 'x']], $kind)->errorCode)->toBe('NOT_FOUND');
});

it('Zwischenspeicher je Lauf: eine neue Pinnwand-Karte zählt im nächsten Lauf', function () {
    $svc = app(TrendAutomatikService::class);
    $insp = FoodAlchemistTrendInspiration::create(['team_id' => $this->rootTeam->id, 'titel' => 'Koji', 'herkunft' => 'automatisch', 'thema_schluessel' => 'koji']);
    $svc->lauf($this->rootTeam);
    expect($svc->passendePinnwand($this->rootTeam, 'koji'))->toHaveCount(0);
    $this->trends->fundstueckAblegen($this->rootTeam, ['titel' => 'Koji', 'url' => 'https://insta.test/koji']);
    expect($svc->passendePinnwand($this->rootTeam, 'koji'))->toHaveCount(1)   // außerhalb eines Laufs immer frisch
        ->and($svc->lauf($this->rootTeam)['liste'][0]['kennzahlen']['davon_pinnwand'])->toBe(1);
});

it('UI: Block im Trend-Detail zeigt die Ableitung, „Erzeugen“ ist Plattform-KI, Bearbeiten macht sie dauerhaft manuell', function () {
    $t = ($this->trend)();
    Livewire::test(AbleitungBlock::class, ['trendId' => $t->id])->assertSee('Noch keine Ableitung')->assertSeeHtml('data-ableitung-erzeugen')
        ->call('erzeugen')->assertSee('Die Ableitung wird erzeugt');
    expect($t->refresh()->ableitung_quelle)->toBe('ki');   // sync-Queue im Test: Plattform-KI lief

    Livewire::test(AbleitungBlock::class, ['trendId' => $t->id])->assertSee('Kimchi-Bowl')->assertSee('Betriebsgastronomie')
        ->call('oeffnen')->set('impulse', "Gericht: Koji-Huhn — mariniert\nKonzept: Ferment-Woche")->set('kurzfazit', 'TEST-93 angepasst')
        ->call('speichern')->assertSee('Von Hand bearbeitet')->assertDontSeeHtml('data-ableitung-erzeugen');
    expect($t->refresh()->ableitung_quelle)->toBe('manuell')
        ->and(array_column($t->ableitung['impulse'], 'titel'))->toBe(['Koji-Huhn', 'Ferment-Woche'])
        ->and($t->ableitung['bedeutung'])->toHaveKey('care');
});
