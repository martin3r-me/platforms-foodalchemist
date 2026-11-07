<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Observatorium\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistObservatoriumLagebild as Lagebild;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\LagebildService;
use Platform\FoodAlchemist\Services\ObservatoriumService;
use Platform\FoodAlchemist\Support\FaBereiche;
use Platform\FoodAlchemist\Support\HandyNavigation;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\ObservatoriumInhalt;
use Platform\FoodAlchemist\Support\ObservatoriumSicht;
use Platform\FoodAlchemist\Support\TrendUrl;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Observatorium zentral (Dominique 07.11.2026): BHG.DIGITAL (Master) kuratiert das Gastro-Observatorium für alle Mandanten.
 * Mandanten lesen beim Master, Trend Research ist nur im Master an, Wettbewerber sind weggelassen, Lagebild mit Belegen.
 * Testdaten „TEST-OZ“, alle Werte erfunden.
 */
function ozEintrag(int $teamId, array $daten = []): FoodAlchemistTrendEingang
{
    $url = $daten['url'] ?? 'https://news.beispiel.test/oz-'.uniqid();

    return FoodAlchemistTrendEingang::create(array_merge(['team_id' => $teamId, 'url' => $url, 'url_hash' => TrendUrl::hash($url),
        'titel' => 'TEST-OZ Kantinen setzen auf Linsen', 'teaser' => 'Umfrage unter 240 Küchen: 38 % planen mehr Hülsenfrüchte.',
        'status' => 'gebuendelt', 'eingereicht_von' => 'mcp', 'herausgeber' => 'TEST-Fachblatt',
        'observatorium' => ['verwendungen' => ['news', 'idee'], 'branchen' => ['betrieb'], 'herkunft' => 'ki']], $daten));
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL TEST-OZ', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->kurator = $this->makeUser($this->master, 'Kurator TEST-OZ');
    $this->mandant = $this->rootTeam;   // eigenständiger Mandant, hängt NICHT unter dem Master
    $this->leser = $this->makeUser($this->mandant, 'Mandant TEST-OZ');
    $this->reg = app(ToolRegistry::class);
});

it('Sicht: Mandant liest beim Master, Master und autarke Installation lesen bei sich', function () {
    expect(ObservatoriumSicht::inhaltsTeam($this->mandant)->id)->toBe($this->master->id)
        ->and(ObservatoriumSicht::inhaltsTeam($this->childA)->id)->toBe($this->master->id)
        ->and(ObservatoriumSicht::inhaltsTeam($this->master)->id)->toBe($this->master->id)
        ->and(ObservatoriumSicht::kuratiertHier($this->master))->toBeTrue()
        ->and(ObservatoriumSicht::kuratiertHier($this->mandant))->toBeFalse();
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    expect(ObservatoriumSicht::inhaltsTeam($this->mandant)->id)->toBe($this->mandant->id)
        ->and(ObservatoriumSicht::kuratiertHier($this->mandant))->toBeTrue()
        ->and(app(FaRechte::class)->bereichAktiv($this->mandant, 'trend_research'))->toBeTrue();
});

it('Mandant sieht die Meldungen des Masters, nicht die eigenen alten; Merker bleiben am eigenen Team', function () {
    $m = ozEintrag($this->master->id);
    $alt = ozEintrag($this->mandant->id, ['titel' => 'TEST-OZ alter eigener Eintrag']);
    $this->actingAs($this->leser);
    $svc = app(ObservatoriumService::class);
    $ids = $svc->gruppiert($this->mandant, ['ansicht' => 'entdecken', 'tage' => 'alle'])->pluck('id')->all();
    expect($ids)->toContain($m->id)->not->toContain($alt->id);
    expect($svc->detail($this->mandant, $m->id)->id)->toBe($m->id);
    $merker = $svc->merken($this->mandant, $m->id);
    expect((int) $merker->team_id)->toBe((int) $this->mandant->id)
        ->and($svc->gruppiert($this->mandant, ['ansicht' => 'merkliste', 'tage' => 'alle'])->pluck('id')->all())->toBe([$m->id]);
});

it('Oberfläche Mandant: liest Master-Inhalte, keine Links ins Trend Research, kein Kuratieren', function () {
    $m = ozEintrag($this->master->id);
    $this->actingAs($this->leser);
    Livewire::test(Index::class)
        ->assertSee('TEST-OZ Kantinen setzen auf Linsen')
        ->assertSee('Kuratiert von BHG.DIGITAL')
        ->assertDontSee('Die Recherche dahinter')
        ->assertDontSee(route('foodalchemist.trendradar.index', ['ansicht' => 'beobachten']), false)
        ->call('oeffnen', $m->id)->assertDontSee('data-observatorium-vertiefen', false)
        ->call('vertiefen', $m->id)->assertForbidden();
});

it('Oberfläche Master: Recherche-Links und Kuratieren bleiben', function () {
    $m = ozEintrag($this->master->id);
    $this->actingAs($this->kurator);
    Livewire::test(Index::class)->assertSee('Die Recherche dahinter')->call('oeffnen', $m->id)->assertSee('data-observatorium-vertiefen', false);
});

it('Trend Research nur im Master: Bereich, Navigation, Seite und MCP-Tools', function () {
    $rechte = app(FaRechte::class);
    expect(FaBereiche::fuerRoute('foodalchemist.trendradar.index'))->toBe('trend_research')
        ->and(FaBereiche::fuerRoute('foodalchemist.observatorium.index'))->toBe('wissen')
        ->and(FaBereiche::fuerTool('foodalchemist.trend_eingang.GET'))->toBe('trend_research')
        ->and(FaBereiche::fuerTool('foodalchemist.trend_lagebild.POST'))->toBe('trend_research')
        ->and(FaBereiche::fuerTool('foodalchemist.marktdaten.ABRUF'))->toBe('trend_research')
        ->and($rechte->darfBereich($this->leser, $this->mandant, 'trend_research'))->toBeFalse()
        ->and($rechte->darfBereich($this->kurator, $this->master, 'trend_research'))->toBeTrue()
        ->and($rechte->teamBereiche($this->mandant)['trend_research'])->toBeFalse()
        ->and($rechte->teamBereiche($this->mandant)['wissen'])->toBeTrue();
    $routen = fn ($u) => collect(FaBereiche::sichtbareNavigation(config('foodalchemist.sidebar', []), $u))->flatMap(fn ($g) => array_column($g['items'] ?? [], 'route'))->all();
    expect($routen($this->leser))->toContain('foodalchemist.observatorium.index')->not->toContain('foodalchemist.trendradar.index')
        ->and($routen($this->kurator))->toContain('foodalchemist.trendradar.index');
    expect(collect(HandyNavigation::fuer($this->leser)['mehr'])->pluck('route')->all())->not->toContain('foodalchemist.trendradar.index');
    $this->actingAs($this->leser)->get(route('foodalchemist.trendradar.index'))->assertForbidden();
    $r = $this->reg->get('foodalchemist.trend_eingang.GET')->execute([], new ToolContext($this->leser, $this->mandant, ['team_source' => 'user_current']));
    expect($r->success)->toBeFalse();
});

it('Wettbewerber weggelassen: keine Ansicht, kein Reiter; Tools nur noch im Master (Bereich trend_research)', function () {
    expect(ObservatoriumSicht::WETTBEWERB_AKTIV)->toBeFalse()
        ->and(ObservatoriumInhalt::ANSICHTEN)->not->toHaveKey('wettbewerb')->toHaveKey('lagebild')
        ->and(FaBereiche::fuerTool('foodalchemist.wettbewerber.POST'))->toBe('trend_research');
    expect($this->reg->get('foodalchemist.wettbewerber.GET')->execute([], new ToolContext($this->leser, $this->mandant, ['team_source' => 'user_current']))->errorCode)->toBe('FORBIDDEN');
    $this->actingAs($this->kurator);
    Livewire::test(\Platform\FoodAlchemist\Livewire\Trendradar\Index::class)->assertDontSee('Unternehmen beobachten und Firmenbezug prüfen');
});

it('Lagebild: Master schreibt, Riegel verwerfen unbelegte Zahl, fremden Beleg, Prognose; Mandant liest mit Quellen', function () {
    $e = ozEintrag($this->master->id);
    $fremd = ozEintrag($this->mandant->id, ['titel' => 'TEST-OZ fremd']);
    $this->actingAs($this->kurator);
    $mcp = new ToolContext($this->kurator, $this->master, ['team_source' => 'user_current']);
    $r = $this->reg->get('foodalchemist.trend_lagebild.POST')->execute(['zeitraum_von' => '2026-11-02', 'zeitraum_bis' => '2026-11-08',
        'titel' => 'TEST-OZ KW 45', 'kurzfazit' => 'Hülsenfrüchte gewinnen: 38 % der Küchen planen mehr.',
        'aussagen' => [
            ['text' => 'In einer Umfrage unter 240 Küchen planen 38 % mehr Hülsenfrüchte.', 'belege' => [$e->id]],
            ['text' => 'Schon 55 % kochen täglich vegan.', 'belege' => [$e->id]],
            ['text' => 'Linsen gibt es überall.', 'belege' => [$fremd->id]],
            ['text' => 'Hülsenfrüchte werden sich durchsetzen.', 'belege' => [$e->id]],
            ['text' => 'Ohne Beleg.'],
        ],
        'impulse' => [['text' => 'Eine Linsen-Komponente für die Mittagslinie testen.', 'belege' => [$e->id]]],
        'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5'], $mcp);
    expect($r->success)->toBeTrue()->and($r->data['aussagen'])->toBe(1)->and($r->data['impulse'])->toBe(1)->and($r->data['verworfen'])->toBe(4)
        ->and(implode(' ', $r->data['gruende']))->toContain('Zahl steht in keinem Beleg')->toContain('nicht im Observatorium')->toContain('ohne Beleg')->toContain('Prognose');
    $l = Lagebild::first();
    expect((int) $l->team_id)->toBe((int) $this->master->id)->and($l->kurzfazit)->toContain('38 %');

    // gleiches zeitraum_bis ersetzt, kein zweites Lagebild
    $this->reg->get('foodalchemist.trend_lagebild.POST')->execute(['zeitraum_von' => '2026-11-02', 'zeitraum_bis' => '2026-11-08', 'titel' => 'TEST-OZ KW 45 neu',
        'aussagen' => [['text' => 'Eine Umfrage unter 240 Küchen zeigt Interesse an Hülsenfrüchten.', 'belege' => [$e->id]]]], $mcp);
    expect(Lagebild::count())->toBe(1)->and(Lagebild::first()->titel)->toBe('TEST-OZ KW 45 neu');

    // Mandant liest (Oberfläche), darf aber nicht schreiben
    $this->actingAs($this->leser);
    $liste = app(LagebildService::class)->liste($this->mandant);
    expect($liste)->toHaveCount(1)->and($liste[0]['aussagen'][0]['belege'][0]['herausgeber'])->toBe('TEST-Fachblatt');
    Livewire::test(Index::class)->assertSee('TEST-OZ KW 45 neu')->call('ansichtSetzen', 'lagebild')->assertSee('TEST-Fachblatt');
    expect(fn () => app(LagebildService::class)->schreiben($this->mandant, ['zeitraum_von' => '2026-11-02', 'zeitraum_bis' => '2026-11-08', 'titel' => 'x',
        'aussagen' => [['text' => 'y', 'belege' => [$e->id]]]]))->toThrow(\RuntimeException::class);
});

it('Lagebild: keine Aussage besteht → nichts gespeichert', function () {
    $e = ozEintrag($this->master->id);
    $this->actingAs($this->kurator);
    $r = $this->reg->get('foodalchemist.trend_lagebild.POST')->execute(['zeitraum_von' => '2026-11-02', 'zeitraum_bis' => '2026-11-08', 'titel' => 'TEST-OZ leer',
        'aussagen' => [['text' => '99 % essen Linsen.', 'belege' => [$e->id]]]], new ToolContext($this->kurator, $this->master, ['team_source' => 'user_current']));
    expect($r->success)->toBeFalse()->and(Lagebild::count())->toBe(0);
});

it('Leeres Lagebild: Ansicht zeigt ehrlichen Hinweis', function () {
    $this->actingAs($this->leser);
    Livewire::test(Index::class)->call('ansichtSetzen', 'lagebild')->assertSee('Noch kein Lagebild.');
});

it('Team-Pinnwand: Mandant heftet Master-Meldung und eigene Idee an, gemeinsam fürs Team, nie beim Master oder anderen Teams', function () {
    $m = ozEintrag($this->master->id);
    $kollegin = $this->makeUser($this->mandant, 'Kollegin TEST-OZ');
    $nurLeser = $this->makeUser($this->mandant, 'Leser TEST-OZ', 'viewer');
    $this->actingAs($this->leser);
    Livewire::test(Index::class)->call('oeffnen', $m->id)->assertSee('data-observatorium-anpinnen', false)
        ->call('anpinnen', $m->id)->assertSet('meldung', 'An die Team-Pinnwand geheftet. Alle im Team sehen es.')
        ->call('anpinnen', $m->id)
        ->set('ideeTitel', 'TEST-OZ Linsen-Bowl')->set('ideeNotiz', 'Für die Mittagslinie')->set('ideeLink', 'https://idee.beispiel.test/bowl')
        ->call('ideeAnpinnen')->assertSet('ideeTitel', '')
        ->set('ideeTitel', 'TEST-OZ kaputt')->set('ideeLink', 'javascript:alert(1)')->call('ideeAnpinnen')->assertSet('meldung', 'Der Link muss mit http:// oder https:// beginnen (höchstens 500 Zeichen).');
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistObservatoriumPin::where('team_id', $this->mandant->id)->count())->toBe(2)
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistObservatoriumPin::where('team_id', $this->master->id)->count())->toBe(0);

    // Kollegin im selben Team sieht beides; Master und Kind-Team sehen nichts davon
    $this->actingAs($kollegin);
    Livewire::test(Index::class)->call('ansichtSetzen', 'pinnwand')->assertSee('TEST-OZ Kantinen setzen auf Linsen')->assertSee('TEST-OZ Linsen-Bowl')->assertSee('Für die Mittagslinie');
    $this->actingAs($this->kurator);
    Livewire::test(Index::class)->call('ansichtSetzen', 'pinnwand')->assertDontSee('TEST-OZ Linsen-Bowl')->assertSee('Eure Pinnwand ist noch leer.');

    // Leserolle: sieht, heftet nicht an
    $this->actingAs($nurLeser);
    Livewire::test(Index::class)->call('ansichtSetzen', 'pinnwand')->assertSee('TEST-OZ Linsen-Bowl')->assertDontSee('data-pinnwand-idee', false);
    expect(fn () => app(\Platform\FoodAlchemist\Services\ObservatoriumPinnwandService::class)->ideeAnpinnen($this->mandant, 'x', null, null, $nurLeser->id))
        ->toThrow(\Platform\FoodAlchemist\Exceptions\FaRechtFehltException::class);
});

it('Team-Pinnwand per MCP: anheften, Notiz, lesen, abnehmen — Bereich Wissen, also auch beim Mandanten', function () {
    $m = ozEintrag($this->master->id);
    $this->actingAs($this->leser);
    $mcp = new ToolContext($this->leser, $this->mandant, ['team_source' => 'user_current']);
    expect(FaBereiche::fuerTool('foodalchemist.observatorium_pinnwand.POST'))->toBe('wissen');
    $a = $this->reg->get('foodalchemist.observatorium_pinnwand.POST')->execute(['eingang_id' => $m->id, 'notiz' => 'Spannend'], $mcp);
    $b = $this->reg->get('foodalchemist.observatorium_pinnwand.POST')->execute(['titel' => 'TEST-OZ eigene Idee'], $mcp);
    expect($a->success)->toBeTrue()->and($b->success)->toBeTrue();
    expect($this->reg->get('foodalchemist.observatorium_pinnwand.POST')->execute(['pin_id' => $b->data['pin_id'], 'notiz' => 'Notiz neu'], $mcp)->success)->toBeTrue();
    expect($this->reg->get('foodalchemist.observatorium_pinnwand.POST')->execute(['eingang_id' => 999999], $mcp)->success)->toBeFalse();
    $pins = $this->reg->get('foodalchemist.observatorium_pinnwand.GET')->execute([], $mcp)->data['pins'];
    expect(collect($pins)->pluck('art')->sort()->values()->all())->toBe(['idee', 'meldung'])
        ->and(collect($pins)->firstWhere('art', 'idee')['notiz'])->toBe('Notiz neu');
    expect($this->reg->get('foodalchemist.observatorium_pinnwand.DELETE')->execute(['pin_id' => $a->data['pin_id']], $mcp)->success)->toBeTrue()
        ->and($this->reg->get('foodalchemist.observatorium_pinnwand.GET')->execute([], $mcp)->data['pins'])->toHaveCount(1);
});

it('Einstellungen: Trendradar-Seite nur im Master — Mandant (auch als Plattform-Admin) sieht sie nicht', function () {
    $html = fn () => Livewire::test(\Platform\FoodAlchemist\Livewire\Settings\Index::class)->html();
    $this->actingAs($this->makeUser($this->mandant, 'Admin TEST-OZ'));
    expect($html())->not->toContain('data-settings-link="google-trends"')->not->toContain('data-settings-tab="trendradar"');

    $padmin = $this->makeUser($this->mandant, 'Plattform TEST-OZ');
    config(['platform-shell.admins' => [strtolower((string) $padmin->email)]]);
    $this->actingAs($padmin);
    expect($html())->not->toContain('data-settings-link="google-trends"');

    $this->actingAs($this->kurator);
    expect($html())->toContain('data-settings-link="google-trends"')->toContain('data-settings-tab="trendradar"');
});
