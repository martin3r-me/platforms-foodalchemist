<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Jobs\TrendEingangErschliessenJob;
use Platform\FoodAlchemist\Livewire\Trendradar\Automatik as AutomatikReiter;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendBeleg;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Services\TrendThemenService;
use Platform\FoodAlchemist\Services\Trends\TrendAutomatikService;
use Platform\FoodAlchemist\Support\TrendAutomatikRegeln;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 93 C · Einordnung nach Regeln und Automatik: je Regelzeile genau auf und eins unter der Schwelle, mega/meta nie,
 * manuelle Felder bleiben, Stufen, Schatten-Modus (schreibt nichts, reproduzierbar), Lauf-Grenze, Bestandstrend nur Belege,
 * Pinnwand als Sichtung (Karte unverändert), Signale über SignalDetektorService::laufen() und selbst schließend,
 * Trend-KI-Budget, Team-Sicht. Testdaten „TEST-93“.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->inhaber = $this->makeUser($this->rootTeam, 'Inhaber TEST-93');
    $this->actingAs($this->inhaber);
    Queue::fake([TrendEingangErschliessenJob::class]);
    $this->svc = app(TrendAutomatikService::class);
    $this->trends = app(TrendService::class);
    $this->reg = app(ToolRegistry::class);
    $this->ctx = new ToolContext($this->inhaber, $this->rootTeam);
    $this->r = TrendAutomatikRegeln::startwerte();
    $this->nr = 0;
    // Ein automatisches Thema mit Artikeln: je Artikel ein Eingang-Eintrag und ein Beleg (wie nach dem Bündeln in B)
    $this->thema = function (string $titel, array $artikel, ?Team $team = null): FoodAlchemistTrendInspiration {
        $team ??= $this->rootTeam;
        $insp = FoodAlchemistTrendInspiration::create(['team_id' => $team->id, 'titel' => $titel, 'herkunft' => 'automatisch',
            'thema_schluessel' => TrendThemenService::schluessel($titel)]);
        foreach ($artikel as $a) {
            $n = ++$this->nr;
            $url = 'https://'.($a['host'] ?? 'presse'.$n.'.beispiel.test').'/artikel-'.$n;
            $datum = now()->subDays($a['tage'] ?? 1);
            FoodAlchemistTrendEingang::create(['team_id' => $team->id, 'url' => $url, 'url_hash' => hash('sha256', $url), 'titel' => 'TEST-93 '.$titel.' '.$n,
                'status' => 'gebuendelt', 'eingereicht_von' => 'abruf', 'thema' => $titel, 'thema_quelle' => 'ki', 'thema_inspiration_id' => $insp->id,
                'tiefe' => $a['tiefe'] ?? 'beduerfnis', 'kategorie' => $a['kategorie'] ?? 'food', 'sparten' => $a['sparten'] ?? ['betriebsgastronomie'],
                'passt_zu_trend_id' => $a['passt'] ?? null, 'relevant' => true, 'veroeffentlicht_am' => $datum, 'erschlossen_at' => now()]);
            FoodAlchemistTrendBeleg::create(['team_id' => $team->id, 'inspiration_id' => $insp->id, 'quelle' => $a['art'] ?? 'presse', 'titel' => 'TEST-93 '.$n,
                'url' => $url, 'beobachtet_am' => $datum->toDateString(), 'fundstueck' => false]);
        }

        return $insp->refresh();
    };
    // Kennzahlen-Grundlage, die genau einen Trend ergibt (für die Grenzfälle wird je ein Wert verschoben)
    $this->k = fn (array $anders = []) => array_merge(['sichtungen_fenster' => 3, 'quellen_fenster' => 2, 'sichtungen_30t' => 3, 'spitze_ohne_sockel' => false,
        'monate' => 3, 'quellenarten' => ['branchenquelle', 'presse'], 'harte_quelle' => true, 'anteil_beduerfnis' => 0.5, 'sparten' => ['care'],
        'letzte_sichtung' => now()->toDateString()], $anders);
    $this->stufe = fn (string $stufe, bool $schatten = false) => app(TeamSettingsService::class)->update($this->rootTeam,
        ['trend_automatik' => $stufe, 'trend_automatik_schatten' => $schatten]);
    // Trend-Rezept: 3 Sichtungen im 60-Tage-Fenster + eine ältere (3 Kalendermonate), 2 Quellenarten mit Fachpresse, alle „Bedürfnis“ → Trend
    $this->trendArtikel = [['tage' => 5, 'art' => 'presse'], ['tage' => 40, 'art' => 'branchenquelle'], ['tage' => 55, 'art' => 'presse'], ['tage' => 100, 'art' => 'presse']];
});

// ── Regelzeilen mit Grenzfällen ─────────────────────────────────────────

it('Kandidat: genau auf der Schwelle ja, eine Sichtung oder eine Quelle darunter nein', function () {
    expect($this->svc->einordnen(($this->k)(), $this->r)['kandidat'])->toBeTrue()
        ->and($this->svc->einordnen(($this->k)(['sichtungen_fenster' => 2]), $this->r)['kandidat'])->toBeFalse()
        ->and($this->svc->einordnen(($this->k)(['quellen_fenster' => 1]), $this->r)['kandidat'])->toBeFalse();
});

it('Trend: Monate, Quellenarten, harte Quelle und Tiefe genau auf der Schwelle; je eins darunter kein Trend', function () {
    expect($this->svc->einordnen(($this->k)(), $this->r)['typ'])->toBe('trend')
        ->and($this->svc->einordnen(($this->k)(['monate' => 2]), $this->r)['typ'])->toBeNull()
        ->and($this->svc->einordnen(($this->k)(['quellenarten' => ['presse']]), $this->r)['typ'])->toBeNull()
        ->and($this->svc->einordnen(($this->k)(['harte_quelle' => false, 'quellenarten' => ['instagram', 'social_media']]), $this->r)['typ'])->toBeNull()
        ->and($this->svc->einordnen(($this->k)(['anteil_beduerfnis' => 0.49]), $this->r)['typ'])->toBeNull();
});

it('Hype: 30-Tage-Sichtungen genau auf der Schwelle oder „Spitze ohne Sockel“, Tiefe unter der Schwelle; eins darunter kein Hype', function () {
    $h = ['anteil_beduerfnis' => 0.49, 'sichtungen_30t' => 5, 'monate' => 1];
    expect($this->svc->einordnen(($this->k)($h), $this->r)['typ'])->toBe('hype')
        ->and($this->svc->einordnen(($this->k)(['sichtungen_30t' => 4] + $h), $this->r)['typ'])->toBeNull()
        ->and($this->svc->einordnen(($this->k)(['sichtungen_30t' => 4, 'spitze_ohne_sockel' => true] + $h), $this->r)['typ'])->toBe('hype')
        ->and($this->svc->einordnen(($this->k)(['anteil_beduerfnis' => 0.5] + array_diff_key($h, ['anteil_beduerfnis' => 1])), $this->r)['typ'])->not->toBe('hype');
});

it('Ebene: mode Standard, konsum ab Sparten- oder Monats-Schwelle (je eins darunter mode); mega/meta nie', function () {
    expect($this->svc->einordnen(($this->k)(['sparten' => ['care']]), $this->r)['ebene'])->toBe('mode')
        ->and($this->svc->einordnen(($this->k)(['sparten' => ['care', 'bildung']]), $this->r)['ebene'])->toBe('konsum')
        ->and($this->svc->einordnen(($this->k)(['monate' => 5]), $this->r)['ebene'])->toBe('mode')
        ->and($this->svc->einordnen(($this->k)(['monate' => 6]), $this->r)['ebene'])->toBe('konsum');
    foreach ([0, 1, 3, 5, 6, 12] as $m) {
        foreach ([[], ['care', 'bildung', 'delivery', 'care2', 'x', 'y']] as $sp) {
            expect($this->svc->einordnen(($this->k)(['monate' => $m, 'sparten' => $sp]), $this->r)['ebene'])->not->toBeIn(['mega', 'meta']);
        }
    }
});

it('Abklingen: genau ab der Monatsgrenze ohne Sichtung, einen Tag davor noch nicht', function () {
    expect($this->svc->einordnen(($this->k)(['letzte_sichtung' => now()->subMonths(6)->subDay()->toDateString()]), $this->r)['abgeklungen'])->toBeTrue()
        ->and($this->svc->einordnen(($this->k)(['letzte_sichtung' => now()->subMonths(6)->addDay()->toDateString()]), $this->r)['abgeklungen'])->toBeFalse();
});

// ── Kennzahlen aus Daten, Pinnwand als Sichtung ─────────────────────────

it('Kennzahlen aus Artikeln; Pinnwand-Fundstück mit gleichem Schlüssel (Titel oder Schlagwort) zählt als Sichtung, Karte bleibt unverändert', function () {
    $thema = ($this->thema)('Fermentation', [['tage' => 2, 'host' => 'a.test'], ['tage' => 3, 'host' => 'a.test']]);
    $vorher = $this->svc->kennzahlen($this->rootTeam, $thema, $this->r);
    expect($vorher['sichtungen'])->toBe(2)->and($vorher['quellen_fenster'])->toBe(1)->and($this->svc->einordnen($vorher, $this->r)['kandidat'])->toBeFalse();

    $karte = $this->trends->fundstueckAblegen($this->rootTeam, ['titel' => 'TEST-93 Kimchi-Bowl', 'url' => 'https://insta.test/p/1', 'quelle' => 'instagram',
        'schlagworte' => ['fermentation']]);
    $insp = FoodAlchemistTrendInspiration::find($karte->inspiration_id);
    $bild = fn () => json_encode([$insp->refresh()->only(['titel', 'trend_id', 'schlagworte', 'herkunft']), (string) $insp->updated_at,
        $karte->refresh()->only(['trend_id', 'inspiration_id', 'titel', 'url']), (string) $karte->updated_at]);
    $schnappschuss = $bild();

    $nachher = $this->svc->kennzahlen($this->rootTeam, $thema, $this->r);
    expect($nachher['sichtungen'])->toBe(3)->and($nachher['davon_pinnwand'])->toBe(1)->and($nachher['quellen_fenster'])->toBe(2)
        ->and($nachher['quellenarten'])->toBe(['instagram', 'presse'])
        ->and($this->svc->einordnen($nachher, $this->r)['kandidat'])->toBeTrue();
    expect($bild())->toBe($schnappschuss);
});

// ── Lauf: Stufen, Schatten, Grenze, Schutz ──────────────────────────────

it('Schatten-Modus und Stufe „aus“ schreiben keine Trends; der Schatten-Lauf ist reproduzierbar', function () {
    ($this->thema)('Koji', $this->trendArtikel);
    expect(app(TeamSettingsService::class)->trendAutomatik($this->rootTeam))->toBe('aus')
        ->and(app(TeamSettingsService::class)->trendAutomatikSchatten($this->rootTeam))->toBeTrue();

    $a = $this->svc->lauf($this->rootTeam);
    ($this->stufe)('radar', true);
    $b = $this->svc->lauf($this->rootTeam);
    $c = $this->svc->lauf($this->rootTeam);

    expect($a['geschrieben'])->toBeFalse()->and($b['geschrieben'])->toBeFalse()->and(FoodAlchemistTrend::count())->toBe(0)
        ->and($b['liste'][0]['aktion'])->toBe('anlegen')->and($b['liste'][0]['einordnung']['typ'])->toBe('trend')
        ->and(json_encode($b))->toBe(json_encode($c));
});

it('Stufe bis_geprueft legt an und setzt nie auf_radar; Stufe radar nur bei radarHindernis() === null', function () {
    ($this->thema)('Koji', $this->trendArtikel);
    ($this->stufe)('bis_geprueft');
    $this->svc->lauf($this->rootTeam);
    $t = FoodAlchemistTrend::firstOrFail();
    expect($t->status)->toBe('geprueft')->and($t->herkunft)->toBe('automatisch')->and($t->typ)->toBe('trend')->and($t->ebene)->toBe('mode')
        ->and($t->einordnung_quelle)->toBe('ki')->and($t->manuell_felder)->toBeNull()
        ->and($t->belege()->count())->toBe(4);

    ($this->stufe)('radar');
    $this->svc->lauf($this->rootTeam);
    expect($t->refresh()->status)->toBe('auf_radar')->and($this->trends->radarHindernis($t))->toBeNull();

    // nur Social-Signale (Hype): Radar-Hindernis → bleibt geprüft, Grund steht in der Begründung
    ($this->thema)('Dubai-Schokolade', array_map(fn ($i) => ['tage' => $i, 'art' => 'instagram', 'tiefe' => 'oberflaeche', 'host' => 'h'.$i.'.test'], range(1, 5)));
    $this->svc->lauf($this->rootTeam);
    $hype = FoodAlchemistTrend::where('name', 'Dubai-Schokolade')->firstOrFail();
    expect($hype->typ)->toBe('hype')->and($hype->status)->toBe('geprueft')->and($hype->einordnung_begruendung)->toContain('Nicht aufs Radar');
});

it('max_neue_trends_je_lauf greift; der Rest bleibt Kandidat mit Signal (über SignalDetektorService::laufen)', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['trend_automatik' => 'bis_geprueft', 'trend_automatik_schatten' => false,
        'trend_automatik_regeln' => ['max_neue_trends_je_lauf' => 1]]);
    ($this->thema)('Koji', $this->trendArtikel);
    ($this->thema)('Miso', $this->trendArtikel);

    $r = $this->svc->lauf($this->rootTeam);
    expect($r['neue_trends'])->toBe(1)->and($r['gestaut'])->toBe(1)->and(FoodAlchemistTrend::count())->toBe(1)
        ->and(array_column($r['liste'], 'aktion'))->toBe(['anlegen', 'gestaut']);

    app(SignalDetektorService::class)->laufen($this->rootTeam);
    $sig = fn (SignalTyp $t) => FoodAlchemistSignal::where('team_id', $this->rootTeam->id)->where('type', $t->value)->where('status', SignalStatus::Offen->value);
    expect($sig(SignalTyp::TrendradarKandidatenGestaut)->count())->toBe(1)->and($sig(SignalTyp::TrendradarNeuerTrend)->count())->toBe(1);

    $this->svc->lauf($this->rootTeam);   // nächster Lauf legt den Rest an
    app(SignalDetektorService::class)->laufen($this->rootTeam);
    expect(FoodAlchemistTrend::count())->toBe(2)->and($sig(SignalTyp::TrendradarKandidatenGestaut)->count())->toBe(0);
});

it('Manuell gesetzte Felder bleiben nach erneutem Lauf; Hype wird Trend nur ohne Merker; Signal schließt, sobald ein Mensch anfasst', function () {
    ($this->stufe)('bis_geprueft');
    $hypeArtikel = array_map(fn ($i) => ['tage' => $i, 'art' => 'presse', 'tiefe' => 'oberflaeche', 'host' => 'h'.$i.'.test'], range(1, 5));
    $thema = ($this->thema)('Bubble Waffles', $hypeArtikel);
    $this->svc->lauf($this->rootTeam);
    $t = FoodAlchemistTrend::firstOrFail();
    expect($t->typ)->toBe('hype');

    // Mehr Tiefe und Dauer → Trend-Regel: der Typ wechselt (Ereignis „Hype wird Trend“)
    FoodAlchemistTrendEingang::where('thema_inspiration_id', $thema->id)->update(['tiefe' => 'beduerfnis']);
    FoodAlchemistTrendBeleg::where('inspiration_id', $thema->id)->orderBy('id')->limit(2)->get()
        ->each(fn ($b, $i) => $b->update(['beobachtet_am' => now()->subDays(35 + 30 * $i)->toDateString(), 'quelle' => $i === 0 ? 'branchenquelle' : 'presse']));
    $this->svc->lauf($this->rootTeam);
    expect($t->refresh()->typ)->toBe('trend')->and(array_column($t->automatik_ereignisse, 'art'))->toContain('hype_wird_trend');

    app(SignalDetektorService::class)->laufen($this->rootTeam);
    $offen = fn (SignalTyp $typ) => FoodAlchemistSignal::where('type', $typ->value)->where('status', SignalStatus::Offen->value)->count();
    expect($offen(SignalTyp::TrendradarHypeWirdTrend))->toBe(1)->and($offen(SignalTyp::TrendradarNeuerHype))->toBe(1);

    // Ein Mensch setzt Typ und Ebene → Merker; der nächste Lauf fasst sie nicht an, Signale schließen sich
    $this->trends->aendern($this->rootTeam, $t->id, ['typ' => 'hype', 'ebene' => 'mega'], $this->inhaber->id);
    $this->svc->lauf($this->rootTeam);
    expect($t->refresh()->typ)->toBe('hype')->and($t->ebene)->toBe('mega')->and($t->manuell_felder)->toBe(['ebene', 'typ']);
    app(SignalDetektorService::class)->laufen($this->rootTeam);
    expect($offen(SignalTyp::TrendradarHypeWirdTrend))->toBe(0)->and($offen(SignalTyp::TrendradarNeuerHype))->toBe(0);
});

it('Bestandstrend (Sarah-artig, herkunft manuell, Megatrend): die Automatik hängt nur Belege an und bewertet neu, Felder bleiben', function () {
    ($this->stufe)('radar');
    $sarah = $this->trends->anlegen($this->rootTeam, ['name' => 'TEST-93 Pflanzenbasierte Ernährung', 'typ' => 'trend', 'ebene' => 'mega', 'kategorie' => 'food',
        'beleg' => ['quelle' => 'befragung', 'titel' => 'Befragung', 'anteil' => 40]]);
    $this->trends->statusSetzen($this->rootTeam, $sarah->id, 'geprueft');
    $felder = $sarah->refresh()->only(['typ', 'ebene', 'kategorie', 'status', 'sparten', 'herkunft', 'einordnung_quelle', 'einordnung_begruendung']);
    ($this->thema)('Tofu im Bankett', array_map(fn ($a) => $a + ['passt' => $sarah->id, 'tiefe' => 'oberflaeche', 'sparten' => ['event_bankett', 'care']],
        array_map(fn ($i) => ['tage' => $i, 'host' => 'p'.$i.'.test'], range(1, 5))));

    $r = $this->svc->lauf($this->rootTeam);
    expect($r['liste'][0]['aktion'])->toBe('anhaengen')->and($r['liste'][0]['ergebnis'])->toBe('angehaengt');
    expect($sarah->refresh()->only(array_keys($felder)))->toBe($felder)
        ->and($sarah->belege()->count())->toBe(6)->and($sarah->konfidenz)->toBe('hoch');
    $this->svc->lauf($this->rootTeam);
    expect($sarah->refresh()->only(array_keys($felder)))->toBe($felder);
});

it('Trend löschen lässt die Artikel des Themas bestehen (nur die Zuordnung fällt weg)', function () {
    ($this->stufe)('bis_geprueft');
    $thema = ($this->thema)('Koji', $this->trendArtikel);
    $this->svc->lauf($this->rootTeam);
    $t = FoodAlchemistTrend::firstOrFail();
    $this->trends->loeschen($this->rootTeam, $t->id);
    expect(FoodAlchemistTrendBeleg::where('inspiration_id', $thema->id)->count())->toBe(4)
        ->and(FoodAlchemistTrendBeleg::where('inspiration_id', $thema->id)->whereNotNull('trend_id')->count())->toBe(0);
});

// ── Signale über SignalDetektorService::laufen() ────────────────────────

it('Signale: Quelle ab 3 Fehlern in Folge (2 nicht), MCP-Quelle fällig, Thema abgeklungen, Pinnwand-Vorschlag — und sie schließen sich selbst', function () {
    $q = FoodAlchemistTrendQuelle::create(['team_id' => $this->rootTeam->id, 'name' => 'TEST-93 Feed', 'url' => 'https://f.test/feed', 'art' => 'presse',
        'feed_typ' => 'rss', 'abruf_weg' => 'plattform', 'rhythmus' => 'taeglich', 'aktiv' => true, 'fehler_in_folge' => 2, 'letzter_fehler' => 'HTTP 500 beim Abruf']);
    $mcp = FoodAlchemistTrendQuelle::create(['team_id' => $this->rootTeam->id, 'name' => 'TEST-93 MCP', 'url' => 'https://m.test/', 'art' => 'instagram',
        'feed_typ' => 'manuell', 'abruf_weg' => 'mcp', 'rhythmus' => 'woechentlich', 'aktiv' => true, 'letzter_abruf_at' => now()->subDays(8)]);
    $alt = ($this->thema)('Freakshakes', [['tage' => 200, 'host' => 'x.test'], ['tage' => 210, 'host' => 'y.test']]);
    ($this->stufe)('bis_geprueft');
    ($this->thema)('Koji', $this->trendArtikel);
    $this->svc->lauf($this->rootTeam);
    $karte = $this->trends->fundstueckAblegen($this->rootTeam, ['titel' => 'Koji', 'url' => 'https://insta.test/koji']);
    $offen = fn (SignalTyp $typ) => FoodAlchemistSignal::where('team_id', $this->rootTeam->id)->where('type', $typ->value)->where('status', SignalStatus::Offen->value)->count();

    app(SignalDetektorService::class)->laufen($this->rootTeam);
    expect($offen(SignalTyp::TrendradarQuelleFehler))->toBe(0)
        ->and($offen(SignalTyp::TrendradarMcpQuelleUeberfaellig))->toBe(1)
        ->and($offen(SignalTyp::TrendradarThemaAbgeklungen))->toBe(1)
        ->and($offen(SignalTyp::TrendradarPinnwandPasst))->toBe(1)
        ->and(FoodAlchemistTrendInspiration::find($karte->inspiration_id)->trend_id)->toBeNull();   // Vorschlag, nie selbst umgehängt

    $q->update(['fehler_in_folge' => 3]);
    app(SignalDetektorService::class)->laufen($this->rootTeam);
    expect($offen(SignalTyp::TrendradarQuelleFehler))->toBe(1);

    // Zustand weg → Signale schließen sich
    $q->update(['fehler_in_folge' => 0, 'letzter_fehler' => null]);
    $mcp->update(['letzter_abruf_at' => now()]);
    FoodAlchemistTrendBeleg::where('inspiration_id', $alt->id)->update(['beobachtet_am' => now()->toDateString()]);
    $this->trends->inspirationZuordnen($this->rootTeam, (int) $karte->inspiration_id, (int) FoodAlchemistTrend::firstOrFail()->id);
    app(SignalDetektorService::class)->laufen($this->rootTeam);
    foreach ([SignalTyp::TrendradarQuelleFehler, SignalTyp::TrendradarMcpQuelleUeberfaellig, SignalTyp::TrendradarThemaAbgeklungen, SignalTyp::TrendradarPinnwandPasst] as $typ) {
        expect($offen($typ))->toBe(0, $typ->value);
    }
    expect(\Platform\FoodAlchemist\Livewire\ReviewQueue::bereichFuer(SignalTyp::TrendradarNeuerTrend))->toBe('konzepte');
});

// ── Budget, Team-Sicht, Tools, Oberfläche ───────────────────────────────

it('Pinnwand „Gehört zu“ (Desktop und Handy) bietet nur manuelle Inspirationen an; ein Fundstück kommt nie in ein automatisches Thema', function () {
    $thema = ($this->thema)('TEST-93 Automatisches Thema', [['tage' => 1]]);
    $karte = $this->trends->fundstueckAblegen($this->rootTeam, ['titel' => 'TEST-93 Handkarte', 'url' => 'https://insta.test/hand']);

    Livewire::withCookie(\Platform\FoodAlchemist\Support\HandyLesemodus::COOKIE, '0')->test(\Platform\FoodAlchemist\Livewire\Trendradar\Index::class)
        ->call('ansichtSetzen', 'inspiration')
        ->assertViewHas('eigeneInspirationen', fn ($l) => array_key_exists((int) $karte->inspiration_id, $l) && ! array_key_exists((int) $thema->id, $l));
    Livewire::withCookie(\Platform\FoodAlchemist\Support\HandyLesemodus::COOKIE, '1')->test(\Platform\FoodAlchemist\Livewire\Trendradar\Index::class)
        ->set('handyReiter', 'pinnwand')->call('$set', 'handyAblegenOffen', true)
        ->assertViewHas('eigeneInspirationen', fn ($l) => array_key_exists((int) $karte->inspiration_id, $l) && ! array_key_exists((int) $thema->id, $l))
        ->assertDontSee('TEST-93 Automatisches Thema');
    expect(fn () => $this->trends->fundstueckAblegen($this->rootTeam, ['titel' => 'TEST-93 falsch', 'inspiration_id' => $thema->id]))
        ->toThrow(\RuntimeException::class, 'automatisches Thema');
    expect(FoodAlchemistTrendBeleg::where('inspiration_id', $thema->id)->count())->toBe(1);
});

it('Trend-KI-Budget: erreicht → Status fehler, Provider nicht gerufen; null = kein eigener Deckel; MCP-Weg zählt nicht', function () {
    $stub = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;

            return ['content' => json_encode(['werte' => ['relevant' => true, 'thema' => 'Koji'], 'confidence' => 0.9, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }
    };
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => []]);
    app()->instance(LLMProviderContract::class, $stub);
    $mk = function (string $p) {
        $url = 'https://b.test/'.$p;

        return FoodAlchemistTrendEingang::create(['team_id' => $this->rootTeam->id, 'url' => $url, 'url_hash' => hash('sha256', $url), 'titel' => 'TEST-93 '.$p,
            'status' => 'neu', 'eingereicht_von' => 'abruf']);
    };
    $lauf = fn ($e, bool $extern = false) => (new TrendEingangErschliessenJob($e->id, $this->rootTeam->id, $extern))->handle(app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class));

    expect(app(TeamSettingsService::class)->trendKiBudget($this->rootTeam))->toBeNull();
    $lauf($a = $mk('a'));
    expect($a->refresh()->status)->toBe('gebuendelt')->and($stub->calls)->toBe(1);

    app(TeamSettingsService::class)->update($this->rootTeam, ['trend_ki_budget_eur' => 0]);
    $lauf($b = $mk('b'));
    expect($b->refresh()->status)->toBe('fehler')->and($b->begruendung)->toBe('Trend-KI-Budget erreicht')->and($stub->calls)->toBe(1);

    $lauf($c = $mk('c'), true);   // MCP-Weg: kein Budget-Stopp, sondern Auftrag
    expect($c->refresh()->begruendung)->not->toBe('Trend-KI-Budget erreicht')->and($stub->calls)->toBe(1);
});

it('Team-Sicht: die Automatik von Kunde A legt Trends in A an; Kunde B sieht sie nicht', function () {
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    app(TeamSettingsService::class)->update($this->childA, ['trend_automatik' => 'bis_geprueft', 'trend_automatik_schatten' => false]);
    ($this->thema)('Koji', $this->trendArtikel, $this->childA);
    $this->svc->lauf($this->childA);
    $t = FoodAlchemistTrend::firstOrFail();

    expect((int) $t->team_id)->toBe((int) $this->childA->id)
        ->and($this->trends->liste($this->childB)->pluck('id')->all())->not->toContain($t->id)
        ->and($this->svc->themen($this->childB))->toHaveCount(0)
        ->and($this->svc->lauf($this->childB)['liste'])->toBe([]);
});

it('Tools: GET mit Plan, PUT nur mit geprüften Werten (FA-Admin), LAUF; Command schreibt nur scharf', function () {
    ($this->thema)('Koji', $this->trendArtikel);
    $get = $this->reg->get('foodalchemist.trend_automatik.GET')->execute(['plan' => true], $this->ctx);
    expect($get->success)->toBeTrue()->and($get->data['stufe'])->toBe('aus')->and($get->data['schatten'])->toBeTrue()
        ->and($get->data['plan']['liste'][0]['aktion'])->toBe('anlegen');

    $put = $this->reg->get('foodalchemist.trend_automatik.PUT');
    expect($put->execute(['regeln' => ['kandidat_sichtungen' => 0]], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and($put->execute(['regeln' => ['gibtsnicht' => 3]], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');
    $ok = $put->execute(['stufe' => 'bis_geprueft', 'schatten' => false, 'regeln' => ['kandidat_sichtungen' => 4]], $this->ctx);
    expect($ok->success)->toBeTrue()->and($ok->data['regeln']['kandidat_sichtungen'])->toBe(4)->and($ok->data['regeln']['kandidat_quellen'])->toBe(2);
    $mitglied = $this->makeUser($this->rootTeam, 'Mitglied TEST-93', 'member');
    $this->actingAs($mitglied);
    expect($put->execute(['stufe' => 'radar'], new ToolContext($mitglied, $this->rootTeam))->success)->toBeFalse();
    $this->actingAs($this->inhaber);

    // 3 Sichtungen < neue Schwelle 4 → kein Trend; zurücksetzen → Trend
    expect($this->reg->get('foodalchemist.trend_automatik.LAUF')->execute([], $this->ctx)->data['neue_trends'])->toBe(0);
    $put->execute(['regeln_zuruecksetzen' => true], $this->ctx);
    Artisan::call('foodalchemist:trend-automatik', ['--team' => $this->rootTeam->id]);
    expect(FoodAlchemistTrend::count())->toBe(1);
});

it('Deploy-sicher: Stufe aus, Schatten an, Scheduler-Schalter aus; Reiter zeigt die Liste und „Lauf jetzt“ schreibt im Schatten nichts', function () {
    expect(config('foodalchemist.scheduler.trend_automatik_enabled'))->toBeFalse();
    $befehle = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");
    expect($befehle)->not->toContain('trend-automatik');

    ($this->thema)('Koji', $this->trendArtikel);
    Livewire::test(AutomatikReiter::class)->assertSee('Koji')->assertSeeHtml('data-automatik-aktion="anlegen"')->assertSee('Schatten-Modus an')
        ->call('laufen')->assertSee('nichts geschrieben');
    expect(FoodAlchemistTrend::count())->toBe(0);
});
