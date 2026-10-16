<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Verwaltung\StammPaket as StammPaketKomponente;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\MasterTeamService;
use Platform\FoodAlchemist\Services\PlattformProtokoll;
use Platform\FoodAlchemist\Services\StammPaketService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\StammPaketLieferantenGetTool;
use Platform\FoodAlchemist\Tools\StammPaketLieferantenPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 — Lieferanten freischalten je Mandant: Standard „keine“, Liste frisch aus dem globalen Stamm,
 * Artikel/Preise folgen der Lieferanten-Freigabe, Grundprodukte bleiben sichtbar, Master sieht alles.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    StammPaket::flush();

    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->inhaber = $this->makeUser($this->rootTeam, 'Inhaber Root', 'owner');
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->kind = Team::create(['name' => 'Soul Unter', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->soul->id]);
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    $this->svc = fn () => app(StammPaketService::class);

    $this->hanos = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Hanos', 'stamm_class' => 'stamm_paket']);
    $this->kluth = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Kluth', 'stamm_class' => 'stamm_paket']);
    $this->la = fn (FoodAlchemistSupplier $s, string $nr) => FoodAlchemistSupplierItem::create([
        'team_id' => null, 'supplier_id' => $s->id, 'designation' => 'LA '.$nr, 'article_number' => $nr, 'qty' => 1, 'unit_code' => 'kg', 'stamm_class' => 'stamm_paket',
    ]);
    $this->laH = ($this->la)($this->hanos, 'H1');
    $this->laK = ($this->la)($this->kluth, 'K1');
    $this->gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'sp|lf', 'name' => 'Mehl: Paket', 'stamm_class' => 'stamm_paket']);

    $this->sup = fn (Team $t) => FoodAlchemistSupplier::query()->visibleToTeam($t)->pluck('name')->sort()->values()->all();
    $this->items = fn (Team $t) => FoodAlchemistSupplierItem::query()->visibleToTeam($t)->pluck('article_number')->sort()->values()->all();
});

it('★ Standard nach Buchung: keine Lieferanten, keine Artikel; Grundprodukte bleiben sichtbar', function () {
    ($this->svc)()->buchen($this->soul, $this->admin);

    expect(($this->sup)($this->soul))->toBe([])->and(($this->items)($this->soul))->toBe([]);
    expect(FoodAlchemistGp::query()->visibleToTeam($this->soul)->whereKey($this->gp->id)->exists())->toBeTrue();
    expect(($this->svc)()->lieferanten($this->soul)['freigegeben'])->toBe(0);
});

it('★ Freischaltung wirkt auf Lieferant und Artikel, Unter-Team erbt, anderer Lieferant bleibt zu', function () {
    ($this->svc)()->buchen($this->soul, $this->admin);
    $r = ($this->svc)()->setzeLieferanten($this->soul, [$this->hanos->id], $this->admin);

    expect($r['freigegeben'])->toBe(1)->and($r['hinzu'])->toBe(1);
    expect(($this->sup)($this->soul))->toBe(['Hanos'])->and(($this->items)($this->soul))->toBe(['H1']);
    expect(($this->sup)($this->kind))->toBe(['Hanos'])->and(($this->items)($this->kind))->toBe(['H1']);
    expect(($this->sup)($this->rootTeam))->toBe([]);
});

it('★ Neue Artikel eines bereits freigeschalteten Lieferanten sind sofort sichtbar, ohne Zusatzschritt', function () {
    ($this->svc)()->buchen($this->soul, $this->admin);
    ($this->svc)()->setzeLieferanten($this->soul, [$this->hanos->id], $this->admin);
    expect(($this->items)($this->soul))->toBe(['H1']);

    ($this->la)($this->hanos, 'H2');    // später global angelegt
    ($this->la)($this->kluth, 'K2');

    expect(($this->items)($this->soul))->toBe(['H1', 'H2']);
});

it('★ Neuer globaler Lieferant erscheint in der Liste ohne Zusatzschritt, unfreigeschaltet', function () {
    ($this->svc)()->buchen($this->soul, $this->admin);
    ($this->svc)()->setzeLieferanten($this->soul, 'alle', $this->admin);
    $neu = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Necta Neu', 'stamm_class' => 'stamm_paket']);
    ($this->la)($neu, 'N1');

    $liste = collect(($this->svc)()->lieferanten($this->soul)['lieferanten'])->keyBy('name');
    expect($liste['Necta Neu']['frei'])->toBeFalse()->and($liste['Necta Neu']['artikel'])->toBe(1)->and($liste['Hanos']['frei'])->toBeTrue();
    expect(($this->sup)($this->soul))->not->toContain('Necta Neu')->and(($this->items)($this->soul))->not->toContain('N1');
});

it('★ Master sieht den gesamten Stamm, Abbestellen räumt die Auswahl', function () {
    ($this->svc)()->buchen($this->soul, $this->admin);
    ($this->svc)()->setzeLieferanten($this->soul, 'alle', $this->admin);
    app(MasterTeamService::class)->setze($this->master, $this->admin);

    expect(($this->sup)($this->master))->toBe(['Hanos', 'Kluth'])->and(($this->items)($this->master))->toBe(['H1', 'K1']);

    ($this->svc)()->abbestellen($this->soul, $this->admin, true);
    expect(DB::table(StammPaket::LIEFERANTEN_TABELLE)->where('team_id', $this->soul->id)->count())->toBe(0);
});

it('setzeLieferanten: exakt die Menge, Fremd-IDs und fehlendes Paket werden abgewiesen, Nicht-Admin FORBIDDEN', function () {
    expect(fn () => ($this->svc)()->setzeLieferanten($this->soul, 'alle', $this->admin))->toThrow(RuntimeException::class, 'buchen');
    ($this->svc)()->buchen($this->soul, $this->admin);
    ($this->svc)()->setzeLieferanten($this->soul, 'alle', $this->admin);
    $r = ($this->svc)()->setzeLieferanten($this->soul, [$this->kluth->id], $this->admin);
    expect($r['entfernt'])->toBe(1)->and(($this->sup)($this->soul))->toBe(['Kluth']);
    expect(fn () => ($this->svc)()->setzeLieferanten($this->soul, [999999], $this->admin))->toThrow(RuntimeException::class, 'Kein Lieferant');
    expect(fn () => ($this->svc)()->setzeLieferanten($this->soul, 'keine', $this->inhaber))->toThrow(\Platform\FoodAlchemist\Exceptions\FaRechtFehltException::class);
});

it('Protokoll: Freischaltung schreibt Eintrag mit Zählung und Namen', function () {
    $p = Mockery::mock(PlattformProtokoll::class)->shouldIgnoreMissing();
    app()->instance(PlattformProtokoll::class, $p);
    $p->shouldReceive('schreibe')->once()->with('stamm_lieferanten_gesetzt', Mockery::on(fn ($d) => $d['hinzu'] === 1 && $d['hinzu_namen'] === ['Hanos']), $this->admin, Mockery::any());
    ($this->svc)()->buchen($this->soul, $this->admin);
    ($this->svc)()->setzeLieferanten($this->soul, [$this->hanos->id], $this->admin);
});

it('MCP: stamm_paket_lieferanten GET/PUT — Admin ja, Inhaber FORBIDDEN', function () {
    ($this->svc)()->buchen($this->soul, $this->admin);
    $ctx = fn ($u) => new ToolContext($u, $this->rootTeam);

    $r = (new StammPaketLieferantenPutTool)->execute(['team_id' => $this->soul->id, 'lieferant_ids' => [$this->hanos->id]], $ctx($this->admin));
    expect($r->success)->toBeTrue(json_encode([$r->errorCode, $r->error ?? null]))->and($r->data['freigegeben'])->toBe(1);
    $g = (new StammPaketLieferantenGetTool)->execute(['team_id' => $this->soul->id, 'suche' => 'han'], $ctx($this->admin));
    expect($g->data['lieferanten'])->toHaveCount(1)->and($g->data['lieferanten'][0]['frei'])->toBeTrue()->and($g->data['gesamt'])->toBe(2);
    expect((new StammPaketLieferantenPutTool)->execute(['team_id' => $this->soul->id, 'keine' => true], $ctx($this->admin))->data['freigegeben'])->toBe(0);
    expect((new StammPaketLieferantenPutTool)->execute(['team_id' => $this->soul->id], $ctx($this->admin))->success)->toBeFalse();
    foreach ([new StammPaketLieferantenGetTool, new StammPaketLieferantenPutTool] as $tool) {
        expect($tool->execute(['team_id' => $this->soul->id, 'keine' => true], $ctx($this->inhaber))->errorCode)->toBe('FORBIDDEN');
    }
});

it('UI: stabile Adresse ?lieferanten=ID öffnet das Panel mit der Auswahl, Speichern schaltet frei', function () {
    ($this->svc)()->buchen($this->soul, $this->admin);
    ($this->svc)()->setzeLieferanten($this->soul, [$this->hanos->id], $this->admin);

    $c = Livewire::actingAs($this->admin)->withQueryParams(['lieferanten' => $this->soul->id])->test(StammPaketKomponente::class);
    $c->assertSee('Lieferanten: Soulkitchen (1 von 2)')->assertSet('auswahl', [$this->hanos->id]);
    $c->call('alleWaehlen')->call('lieferantenSpeichern');
    expect(($this->sup)($this->soul))->toBe(['Hanos', 'Kluth']);
    $c->call('keineWaehlen')->call('lieferantenSpeichern');
    expect(($this->sup)($this->soul))->toBe([]);
});

it('UI: Inhaber sieht das Panel nicht', function () {
    ($this->svc)()->buchen($this->soul, $this->admin);
    Livewire::actingAs($this->inhaber)->withQueryParams(['lieferanten' => $this->soul->id])->test(StammPaketKomponente::class)
        ->assertDontSee('Lieferanten: Soulkitchen');
});

it('UI: Zähler, „Nur gewählte" und Sortierung nach Artikelzahl wirken auf die Anzeige, nicht auf die Freischaltung', function () {
    ($this->svc)()->buchen($this->soul, $this->admin);
    ($this->la)($this->hanos, 'H2');   // Hanos 2 Artikel, Kluth 1

    $c = Livewire::actingAs($this->admin)->withQueryParams(['lieferanten' => $this->soul->id])->test(StammPaketKomponente::class);
    $c->assertSee('0 von 2 gewählt')->assertSeeHtmlInOrder(['Hanos', 'Kluth']);

    $c->set('auswahl', [$this->kluth->id])->assertSee('1 von 2 gewählt');
    $c->set('nurGewaehlte', true)->assertSee('Kluth')->assertDontSee('Hanos');
    $c->set('nurGewaehlte', false)->set('nachArtikel', true)->assertSeeHtmlInOrder(['Hanos', 'Kluth']);
    expect(($this->sup)($this->soul))->toBe([]);   // nichts gespeichert, solange nicht „Speichern"
});
