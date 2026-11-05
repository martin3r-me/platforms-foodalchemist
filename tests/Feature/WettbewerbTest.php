<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Observatorium\Index as Observatorium;
use Platform\FoodAlchemist\Livewire\Trendradar\Index as Research;
use Platform\FoodAlchemist\Livewire\Trendradar\Wettbewerber;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang as Artikel;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle as Quelle;
use Platform\FoodAlchemist\Models\FoodAlchemistWettbewerber as Profil;
use Platform\FoodAlchemist\Models\FoodAlchemistWettbewerbFund as Fund;
use Platform\FoodAlchemist\Services\ObservatoriumService;
use Platform\FoodAlchemist\Services\TrendEingangService;
use Platform\FoodAlchemist\Services\TrendQuellenService;
use Platform\FoodAlchemist\Services\WettbewerbService;
use Platform\FoodAlchemist\Support\FaBereiche;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(TestCase::class, SeedsTeamHierarchy::class);
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->owner = $this->makeUser($this->rootTeam, 'Wettbewerb Owner TEST');
    $this->viewer = $this->makeUser($this->rootTeam, 'Wettbewerb Leser TEST', 'viewer');
    $this->actingAs($this->owner);
    Queue::fake();
    Http::preventStrayRequests();
    $this->svc = app(WettbewerbService::class);
});

function wettArtikel($team, string $titel, array $extra = []): Artikel
{
    return app(TrendEingangService::class)->einreichen($team, $extra + ['url' => 'https://fachquelle.test/'.sha1($titel), 'titel' => $titel,
        'teaser' => 'TEST Meldung zur Gastronomie und Catering.', 'veroeffentlicht_am' => now()->toIso8601String()], 'abruf')['eintrag'];
}

it('legt Profile und pausierte RSS-Suchen ohne externe Aufrufe oder neue KI-Aufträge an', function () {
    $p = $this->svc->speichern($this->rootTeam, ['name' => 'TEST Nordstern Catering', 'website' => 'https://nordstern.test', 'aliases' => ['Nordstern Genuss'],
        'suchbegriffe' => ['Gastronomie'], 'branchen' => ['catering'], 'region' => 'DE', 'presse_feed' => 'https://nordstern.test/feed']);
    expect($p->aktiv)->toBeTrue()->and($p->rss_aktiv)->toBeFalse()->and($p->google_quelle_id)->not->toBeNull();
    $q = Quelle::findOrFail($p->google_quelle_id);
    expect($q->aktiv)->toBeFalse()->and($q->url)->toContain('news.google.com/rss/search')->and($q->feed_url)->toBe($q->url)
        ->and(Quelle::findOrFail($p->presse_quelle_id)->aktiv)->toBeFalse();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    $this->svc->speichern($this->rootTeam, ['rss_aktiv' => true], $p->id);
    expect($q->refresh()->aktiv)->toBeTrue();
    $this->svc->speichern($this->rootTeam, ['aktiv' => false], $p->id);
    expect($q->refresh()->aktiv)->toBeFalse();
});

it('erkennt Namen Marken und eigene Domains aber keine Teilwort- oder Herausgeberdomain-Verwechslung', function () {
    $p = $this->svc->speichern($this->rootTeam, ['name' => 'TEST Nordstern', 'aliases' => ['Nordstern Genuss'], 'website' => 'https://nordstern.test']);
    $a = wettArtikel($this->rootTeam, 'Nordstern Genuss eröffnet ein Restaurant');
    $b = wettArtikel($this->rootTeam, 'TEST Neuigkeiten zur Gastronomie', ['url' => 'https://presse.nordstern.test/neu']);
    wettArtikel($this->rootTeam, 'TEST Nordsternchen eröffnet ein Restaurant');
    wettArtikel($this->rootTeam, 'TEST Gastronomie-News', ['url' => 'https://nordstern.test.evil.test/neu', 'herausgeber_url' => 'https://nordstern.test']);
    expect(Fund::pluck('eingang_id')->all())->toBe([$a->id, $b->id]);
    $this->svc->entscheiden($this->rootTeam, $p->id, $a->id, 'abgelehnt');
    $this->svc->bestandPruefen($this->rootTeam, $p->id);
    expect(Fund::where('eingang_id', $a->id)->first()->status)->toBe('abgelehnt')->and(Fund::count())->toBe(2);
});

it('veröffentlicht ausschließlich bestätigte Firmenzuordnungen und trennt diese von Trendfreigaben', function () {
    $p = $this->svc->speichern($this->rootTeam, ['name' => 'TEST Nordstern', 'aliases' => ['Nordstern'], 'branchen' => ['catering'], 'region' => 'DE']);
    $a = wettArtikel($this->rootTeam, 'Nordstern erhält einen Auftrag');
    $a->update(['status' => 'verworfen', 'observatorium' => ['verwendungen' => [], 'branchen' => []]]);
    $obs = app(ObservatoriumService::class);
    expect($obs->gruppiert($this->rootTeam, ['ansicht' => 'wettbewerb'])->count())->toBe(0);
    $this->svc->entscheiden($this->rootTeam, $p->id, $a->id, 'bestaetigt', 'auftrag', 'Eigene TEST Einschätzung');
    expect($obs->gruppiert($this->rootTeam, ['ansicht' => 'wettbewerb', 'branche' => 'catering', 'region' => 'DE', 'bewegung' => 'auftrag'])->pluck('id')->all())->toBe([$a->id])
        ->and($obs->gruppiert($this->rootTeam, ['ansicht' => 'wettbewerb', 'bewegung' => 'standort'])->count())->toBe(0)
        ->and($a->refresh()->status)->toBe('verworfen')->and($a->thema_inspiration_id)->toBeNull()->and($a->inspiration_id)->toBeNull();
    Livewire::test(Observatorium::class)->call('ansichtSetzen', 'wettbewerb')->assertSee($a->titel)->assertSee('Eigene Bewertung')->assertSee('TEST Einschätzung')
        ->call('oeffnen', $a->id)->assertSee('Firmenbezug bestätigt');
    $this->svc->entscheiden($this->rootTeam, $p->id, $a->id, 'abgelehnt');
    expect($obs->gruppiert($this->rootTeam, ['ansicht' => 'wettbewerb'])->count())->toBe(0);
});

it('bewahrt eigene manuelle Zuordnungen auch ohne automatischen Namenstreffer und ermöglicht mehrere Unternehmen je Artikel', function () {
    $a = wettArtikel($this->rootTeam, 'TEST Zwei Caterer schließen eine Partnerschaft');
    $p = $this->svc->speichern($this->rootTeam, ['name' => 'TEST Nordstern']);
    $q = $this->svc->speichern($this->rootTeam, ['name' => 'TEST Südlicht']);
    $this->svc->entscheiden($this->rootTeam, $p->id, $a->id, 'bestaetigt', 'partnerschaft');
    $this->svc->entscheiden($this->rootTeam, $q->id, $a->id, 'bestaetigt', 'partnerschaft');
    expect(Artikel::count())->toBe(1)->and(Fund::count())->toBe(2)
        ->and(app(ObservatoriumService::class)->gruppiert($this->rootTeam, ['ansicht' => 'wettbewerb'])->count())->toBe(1);
});

it('isoliert Profile Artikel Quellen und Entscheidungen gegenüber Geschwisterteams und Lesern', function () {
    $p = $this->svc->speichern($this->rootTeam, ['name' => 'TEST Nordstern']);
    $fremd = wettArtikel($this->childB, 'TEST Caterer anderer Standort');
    $this->actingAs($this->makeUser($this->childB, 'TEST Standort B'));
    $fremdesProfil = $this->svc->speichern($this->childB, ['name' => 'TEST Nur Standort B']);
    $this->svc->entscheiden($this->childB, $p->id, $fremd->id, 'bestaetigt');
    $user = $this->makeUser($this->childA, 'TEST Standort A');
    $this->actingAs($user);
    expect($this->svc->profile($this->childA)->pluck('id')->all())->toContain($p->id);
    expect(fn () => $this->svc->speichern($this->childA, ['name' => 'TEST Änderung'], $p->id))->toThrow(HttpException::class);
    expect(fn () => $this->svc->entscheiden($this->childA, $p->id, $fremd->id, 'bestaetigt'))->toThrow(HttpException::class);
    Livewire::test(Wettbewerber::class)->set('profilId', $fremdesProfil->id)->assertStatus(404);
    Livewire::test(Observatorium::class)->call('ansichtSetzen', 'wettbewerb')->assertDontSee($fremd->titel)->assertDontSee('TEST Nur Standort B');
    $this->actingAs($this->viewer);
    expect(fn () => $this->svc->speichern($this->rootTeam, ['name' => 'TEST Unerlaubt']))->toThrow(FaRechtFehltException::class);
    Livewire::test(Wettbewerber::class)->assertDontSee('Wettbewerber anlegen')->call('waehlen', $p->id)->assertSee('TEST Nordstern');
});

it('lässt Unternehmensfeeds ohne Food-Schlagwort zu ohne fremde Quellenfilter zu verändern', function () {
    $p = $this->svc->speichern($this->rootTeam, ['name' => 'TEST Nordstern']);
    $q = Quelle::findOrFail($p->google_quelle_id);
    $w = app(TrendQuellenService::class)->stichworte($q);
    expect($w['ein'])->toBe([])->and($w['ein_quelle'])->toBe('wettbewerber');
    $a = wettArtikel($this->rootTeam, 'TEST Nordstern benennt Geschäftsführung', ['quelle_id' => $q->id, 'teaser' => 'Personalmeldung']);
    expect($a)->toBeInstanceOf(Artikel::class)->and(Fund::where('eingang_id', $a->id)->count())->toBe(1);
    $q->update(['stichworte_ein' => ['Catering']]);
    expect(app(TrendQuellenService::class)->stichworte($q)['ein'])->toBe(['Catering']);
});

it('integriert Research Observatorium und mobile Lesesicht mit bestehender Schreibsperre', function () {
    $p = $this->svc->speichern($this->rootTeam, ['name' => 'TEST Nordstern']);
    $a = wettArtikel($this->rootTeam, 'TEST Nordstern eröffnet ein Restaurant');
    Livewire::test(Research::class)->call('ansichtSetzen', 'wettbewerber')->assertSeeHtml('data-wettbewerber-research');
    Livewire::test(Wettbewerber::class)->call('waehlen', $p->id)->assertSee($a->titel)->call('entscheiden', $a->id, 'bestaetigt')->assertSee('Firmenbezug bestätigt');
    Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(Wettbewerber::class)->call('waehlen', $p->id)->assertDontSee('Wettbewerber anlegen')
        ->call('entscheiden', $a->id, 'abgelehnt');
    expect(Fund::where('eingang_id', $a->id)->first()->status)->toBe('bestaetigt');
    Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(Observatorium::class)->call('ansichtSetzen', 'wettbewerb')->assertSee($a->titel)->assertSeeHtml('data-observatorium-handy');
    Livewire::withQueryParams(['ansicht' => 'wettbewerber'])->withCookie(HandyLesemodus::COOKIE, '1')->test(Research::class)
        ->assertSet('handyReiter', 'wettbewerber')->assertSeeHtml('data-wettbewerber-research');
    expect(FaBereiche::fuerKomponente(Wettbewerber::class))->toBe('wissen');
});

it('validiert URL Branchen und fremde Quellen vor jedem Schreiben und hält Migration reversibel', function () {
    expect(fn () => $this->svc->speichern($this->rootTeam, ['name' => 'TEST Unsicher', 'website' => 'javascript:alert(1)']))->toThrow(RuntimeException::class);
    expect(Profil::count())->toBe(0)->and(Quelle::count())->toBe(0);
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_12_14_100000_create_foodalchemist_wettbewerb.php';
    $migration->down();
    $migration->up();
    expect($this->svc->profile($this->rootTeam))->toBeEmpty();
});

it('stellt Profilpflege und Zuordnungen auch per MCP über denselben geprüften Service bereit', function () {
    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->owner, $this->rootTeam);
    $r = $reg->get('foodalchemist.wettbewerber.POST')->execute(['name' => 'TEST MCP Caterer', 'aliases' => ['MCP Caterer']], $ctx);
    expect($r->success)->toBeTrue();
    $id = $r->data['profil']['id'];
    $a = wettArtikel($this->rootTeam, 'TEST MCP Caterer eröffnet einen Standort');
    $r = $reg->get('foodalchemist.wettbewerber.ZUORDNEN')->execute(['wettbewerber_id' => $id, 'eingang_id' => $a->id, 'status' => 'bestaetigt', 'bewegung' => 'standort', 'einordnung' => 'TEST eigene Bewertung'], $ctx);
    expect($r->success)->toBeTrue();
    $r = $reg->get('foodalchemist.wettbewerber.GET')->execute(['id' => $id, 'status' => 'bestaetigt'], $ctx);
    expect($r->success)->toBeTrue()->and($r->data['gesamt'])->toBe(1);
    $r = $reg->get('foodalchemist.wettbewerber.PUT')->execute(['id' => $id, 'rss_aktiv' => true], $ctx);
    expect($r->success)->toBeTrue()->and(Quelle::find(Profil::find($id)->google_quelle_id)->aktiv)->toBeTrue();
    $this->actingAs($this->viewer);
    $r = $reg->get('foodalchemist.wettbewerber.POST')->execute(['name' => 'TEST Unzulässig'], new ToolContext($this->viewer, $this->rootTeam));
    expect($r->success)->toBeFalse();
});

it('hält die bestätigte Wettbewerbersicht im Briefing und schützt fremde Quell- und Profil-IDs', function () {
    $p = $this->svc->speichern($this->rootTeam, ['name' => 'TEST Nordstern']);
    $a = wettArtikel($this->rootTeam, 'TEST Nordstern eröffnet eine Gastronomie');
    wettArtikel($this->rootTeam, 'TEST Andere Gastronomie eröffnet ein Restaurant');
    $this->svc->entscheiden($this->rootTeam, $p->id, $a->id, 'bestaetigt');
    Livewire::test(Observatorium::class)->call('ansichtSetzen', 'wettbewerb')->call('ansichtSetzen', 'briefing')
        ->assertSee($a->titel)->assertDontSee('TEST Andere Gastronomie');
    $fremd = Quelle::create(['team_id' => $this->childB->id, 'name' => 'TEST Fremd', 'url' => 'https://fremd.test/feed', 'art' => 'presse', 'feed_typ' => 'rss', 'abruf_weg' => 'plattform', 'aktiv' => false]);
    expect(fn () => $this->svc->speichern($this->rootTeam, ['name' => 'TEST Angriff', 'quellen_ids' => [$fremd->id]]))->toThrow(HttpException::class);
    expect(fn () => $this->svc->speichern($this->rootTeam, ['name' => 'TEST Branche', 'branchen' => ['erfunden']]))->toThrow(ValidationException::class);
    expect(Profil::count())->toBe(1);
});

it('normalisiert offene Regionen und erhält vorhandene Bewertungen beim Prüfen und Bearbeiten', function () {
    $p = $this->svc->speichern($this->rootTeam, ['name' => str_repeat('Ä', 190), 'region' => '']);
    expect($p->region)->toBeNull()->and($p->name_schluessel)->toHaveLength(64);
    $a = wettArtikel($this->rootTeam, 'TEST Gastronomie eröffnet ein Betriebsrestaurant');
    $this->svc->entscheiden($this->rootTeam, $p->id, $a->id, 'bestaetigt', 'standort', 'TEST bestehende eigene Bewertung');
    Livewire::test(Wettbewerber::class)->call('waehlen', $p->id)->set('status', 'bestaetigt')
        ->assertSet('bewertung.'.$a->id.'.einordnung', 'TEST bestehende eigene Bewertung')
        ->assertSet('bewertung.'.$a->id.'.bewegung', 'standort');
    $this->svc->entscheiden($this->rootTeam, $p->id, $a->id, 'vorgeschlagen');
    $f = Fund::first();
    expect($f->einordnung)->toBe('TEST bestehende eigene Bewertung')->and($f->bewegung)->toBe('standort');
});
