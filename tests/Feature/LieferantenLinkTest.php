<?php

use Illuminate\Http\Request;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Http\Controllers\LieferantenPortalController;
use Platform\FoodAlchemist\Livewire\Suppliers\LieferantenLink as LinkKomponente;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLink as Link;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLinkEreignis as Ereignis;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\LieferantenLinkService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\LieferantenLinkDeleteTool;
use Platform\FoodAlchemist\Tools\LieferantenLinkGetTool;
use Platform\FoodAlchemist\Tools\LieferantenLinkPostTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Lieferanten-Link, Stufe 2a (Kurator 29.10.2026): Modell, Erzeugen/Widerruf, Portal-Seite, Protokoll, UI, MCP. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->team = $this->rootTeam;
    $this->admin = $this->makeUser($this->team, 'Chef', 'owner');
    $this->actingAs($this->admin);
    $this->svc = app(LieferantenLinkService::class);
    $this->hanos = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Hanos']);
    $this->andere = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Chefs Culinar']);
    $this->portal = fn (string $token) => app(LieferantenPortalController::class)->show($token, Request::create('/l/' . $token, 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.7']), $this->svc);
});

it('Erzeugen: der Token-Klartext steht nur in der Rückgabe, gespeichert ist der Hash; Standard 30 Tage, Bereiche, Protokoll', function () {
    $r = $this->svc->erzeugen($this->team, $this->admin, $this->hanos, ['kontakt_name' => 'Frau Beispiel']);

    expect($r['token'])->toHaveLength(48)->and($r['url'])->toContain('/l/' . $r['token'])
        ->and($r['link']->token_hash)->toBe(hash('sha256', $r['token']))->and($r['link']->token_hinweis)->toBe(substr($r['token'], 0, 6))
        ->and(Link::query()->get()->toJson())->not->toContain($r['token'])
        ->and($r['link']->expires_at->isSameDay(now()->addDays(30)))->toBeTrue()
        ->and($r['link']->bereiche)->toBe(['preisliste', 'stammdaten', 'dokumente', 'formular'])
        ->and(Ereignis::where('link_id', $r['link']->id)->pluck('art')->all())->toBe(['erzeugt']);
});

it('Gültigkeit wird auf das Maximum (90 Tage) gekappt, Bereiche gefiltert; leere Bereiche und falsche E-Mail werden abgelehnt', function () {
    $l = $this->svc->erzeugen($this->team, $this->admin, $this->hanos, ['tage' => 9999, 'bereiche' => ['preisliste', 'quatsch']])['link'];
    expect($l->expires_at->isSameDay(now()->addDays(90)))->toBeTrue()->and($l->bereiche)->toBe(['preisliste']);

    expect(fn () => $this->svc->erzeugen($this->team, $this->admin, $this->hanos, ['bereiche' => ['quatsch']]))->toThrow(\RuntimeException::class, 'Bereich');
    expect(fn () => $this->svc->erzeugen($this->team, $this->admin, $this->hanos, ['kontakt_email' => 'kein-mail']))->toThrow(\RuntimeException::class, 'E-Mail');
});

it('Portal: gültiger Link zeigt Lieferant, Gültigkeit und Bereiche; erste Öffnung wird protokolliert (nur einmal)', function () {
    $r = $this->svc->erzeugen($this->team, $this->admin, $this->hanos, ['bereiche' => ['preisliste']]);
    $antwort = ($this->portal)($r['token']);

    expect($antwort->getStatusCode())->toBe(200)->and($antwort->getContent())->toContain('Hanos')->toContain('Preisliste hochladen')->not->toContain('Datenblätter')
        ->and($antwort->headers->get('X-Robots-Tag'))->toContain('noindex');
    ($this->portal)($r['token']);
    expect(Ereignis::where('link_id', $r['link']->id)->where('art', 'geoeffnet')->count())->toBe(1)
        ->and($r['link']->fresh()->first_used_at)->not->toBeNull()
        ->and(Ereignis::where('art', 'geoeffnet')->value('ip_hash'))->toBe(hash('sha256', '203.0.113.7|' . config('app.key')));   // nie die IP selbst
});

it('Portal: unbekannt, abgelaufen, widerrufen, Format falsch ⇒ immer dieselbe 410-Seite', function () {
    $ok = $this->svc->erzeugen($this->team, $this->admin, $this->hanos);
    $abgelaufen = $this->svc->erzeugen($this->team, $this->admin, $this->hanos);
    $abgelaufen['link']->update(['expires_at' => now()->subMinute()]);
    $widerrufen = $this->svc->erzeugen($this->team, $this->admin, $this->hanos);
    $this->svc->widerrufen($this->team, $this->admin, $widerrufen['link']);

    $inhalte = collect([str_repeat('a', 48), $abgelaufen['token'], $widerrufen['token'], 'zu-kurz!'])->map(fn ($t) => ($this->portal)($t));

    expect($inhalte->map(fn ($r) => $r->getStatusCode())->unique()->values()->all())->toBe([410])->and($inhalte->map(fn ($r) => $r->getContent())->unique()->count())->toBe(1);
    expect(($this->portal)($ok['token'])->getStatusCode())->toBe(200);
});

it('Widerruf: sofort wirksam, idempotent, protokolliert; fremdes Team kommt nicht heran', function () {
    $r = $this->svc->erzeugen($this->team, $this->admin, $this->hanos);
    $this->svc->widerrufen($this->team, $this->admin, $r['link']);
    $this->svc->widerrufen($this->team, $this->admin, $r['link']->fresh());

    expect($this->svc->aufloesen($r['token']))->toBeNull()->and(Ereignis::where('link_id', $r['link']->id)->where('art', 'widerrufen')->count())->toBe(1);

    $kind = $this->makeUser($this->childA, 'Kind', 'owner');
    expect(fn () => $this->svc->widerrufen($this->childA, $kind, $r['link']))->toThrow(FaRechtFehltException::class);
});

it('Rechte: Viewer und Unter-Team dürfen nicht erzeugen/auflisten; geerbter Lieferant nie; globaler nur das Master-Team', function () {
    $viewer = $this->makeUser($this->team, 'Gast', 'viewer');
    $kind = $this->makeUser($this->childA, 'Kind', 'owner');
    expect(fn () => $this->svc->erzeugen($this->team, $viewer, $this->hanos))->toThrow(FaRechtFehltException::class);
    expect(fn () => $this->svc->erzeugen($this->childA, $kind, $this->hanos))->toThrow(FaRechtFehltException::class);
    expect(fn () => $this->svc->liste($this->team, $viewer))->toThrow(FaRechtFehltException::class);

    $global = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Global-Stamm']);
    expect(fn () => $this->svc->erzeugen($this->team, $this->admin, $global))->toThrow(FaRechtFehltException::class);   // Root ist hier nicht das Master-Team
    expect(Link::count())->toBe(0);
});

it('Isolation: ein Link löst nur seinen Lieferanten und sein Team auf; Team gesperrt ⇒ Portal zu', function () {
    $r = $this->svc->erzeugen($this->team, $this->admin, $this->hanos);
    $link = $this->svc->aufloesen($r['token']);

    expect((int) $link->supplier_id)->toBe($this->hanos->id)->and((int) $link->team_id)->toBe($this->team->id)->and($link->supplier_id)->not->toBe($this->andere->id);

    $cache = new ReflectionProperty(\Platform\FoodAlchemist\Support\TeamSperre::class, 'cache');
    $cache->setAccessible(true);
    $cache->setValue(null, [(int) $this->team->id => true]);           // Team gilt als gesperrt
    expect($this->svc->aufloesen($r['token']))->toBeNull();
    \Platform\FoodAlchemist\Support\TeamSperre::flush();
    expect($this->svc->aufloesen($r['token']))->not->toBeNull();       // Gegenprobe: wieder frei
});

it('UI: Link erzeugen (einmalig sichtbar), Liste ohne Token, Protokoll, Widerruf; Viewer sieht nichts', function () {
    $c = Livewire::test(LinkKomponente::class, ['supplierId' => $this->hanos->id])
        ->assertSeeHtml('data-lieferanten-link-erzeugen')
        ->set('kontaktName', 'Frau Beispiel')->call('erzeugen')
        ->assertSet('fehler', null)->assertSeeHtml('data-lieferanten-link-neu')->assertSeeHtml('data-lieferanten-link-kopieren');
    $url = $c->get('neueUrl');
    expect($url)->toContain('/l/')->and(Link::count())->toBe(1);

    $c->call('protokoll', Link::first()->id)->assertSeeHtml('data-lieferanten-link-ereignisse')->assertSee('Link erzeugt');
    $c->call('widerrufen', Link::first()->id)->assertSee('widerrufen');
    expect(Link::first()->widerrufen())->toBeTrue();
    expect(Livewire::test(LinkKomponente::class, ['supplierId' => $this->hanos->id])->html())->not->toContain(substr($url, strrpos($url, '/') + 1));   // Token nie wieder in der Oberfläche

    $this->actingAs($this->makeUser($this->team, 'Gast', 'viewer'));
    Livewire::test(LinkKomponente::class, ['supplierId' => $this->hanos->id])->assertDontSeeHtml('data-lieferanten-link-erzeugen');
});

it('Lieferanten-Modal bettet die Komponente mit einer Zeile ein', function () {
    $html = file_get_contents(__DIR__ . '/../../resources/views/livewire/suppliers/supplier-detail.blade.php');
    expect(substr_count($html, 'foodalchemist.suppliers.lieferanten-link'))->toBe(1);
});

it('MCP: POST erzeugt (URL nur dort), GET listet ohne Token + Protokoll, DELETE widerruft; Unter-Team verboten', function () {
    $ctx = new ToolContext($this->admin, $this->team);
    $post = (new LieferantenLinkPostTool)->execute(['supplier_id' => $this->hanos->id, 'tage' => 14, 'bereiche' => ['preisliste']], $ctx);
    expect($post->success)->toBeTrue()->and($post->data['url'])->toContain('/l/')->and($post->data['link']['bereiche'])->toBe(['preisliste']);
    $id = $post->data['link']['id'];

    $get = (new LieferantenLinkGetTool)->execute(['supplier_id' => $this->hanos->id, 'link_id' => $id], $ctx);
    $token = substr($post->data['url'], strrpos($post->data['url'], '/') + 1);
    expect($get->success)->toBeTrue()->and(json_encode($get->data))->not->toContain($token)->and($get->data['protokoll'][0]['art'])->toBe('erzeugt');

    $kind = $this->makeUser($this->childA, 'Kind', 'owner');
    foreach ([new LieferantenLinkGetTool, new LieferantenLinkPostTool, new LieferantenLinkDeleteTool] as $t) {
        expect($t->execute(['supplier_id' => $this->hanos->id, 'link_id' => $id], new ToolContext($kind, $this->childA))->success)->toBeFalse();
    }
    $del = (new LieferantenLinkDeleteTool)->execute(['link_id' => $id], $ctx);
    expect($del->success)->toBeTrue()->and($del->data['link']['zustand'])->toBe('widerrufen')->and($this->svc->aufloesen($token))->toBeNull();
});
