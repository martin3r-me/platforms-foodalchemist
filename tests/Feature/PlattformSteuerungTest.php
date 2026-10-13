<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Verwaltung\MasterTeam as MasterTeamKomponente;
use Platform\FoodAlchemist\Livewire\Verwaltung\StammPaket as StammPaketKomponente;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\AboAuskunft;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\MasterTeamService;
use Platform\FoodAlchemist\Services\PlattformProtokoll;
use Platform\FoodAlchemist\Services\StammPaketService;
use Platform\FoodAlchemist\Support\FaBereiche;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\MasterTeamGetTool;
use Platform\FoodAlchemist\Tools\MasterTeamPutTool;
use Platform\FoodAlchemist\Tools\StammPaketGetTool;
use Platform\FoodAlchemist\Tools\StammPaketPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 B1 (Stamm-Paket buchen/abbestellen), B2 (Bereich „Unter-Teams & Freigaben“), B4 (Master-Team festlegen).
 * Nur Plattform-Admin; Rechteprüfung im Modul (FaRechte), Protokoll über PlattformProtokoll.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();

    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->inhaber = $this->makeUser($this->rootTeam, 'Inhaber Root', 'owner');
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);

    $this->protokoll = Mockery::mock(PlattformProtokoll::class)->shouldIgnoreMissing();
    app()->instance(PlattformProtokoll::class, $this->protokoll);
    $this->paket = fn () => app(StammPaketService::class);
    $this->masterSvc = fn () => app(MasterTeamService::class);
    // Abos per Container ersetzbar: Standard = keine (heute gibt es noch keine Abo-Tabelle).
    $this->mitAbos = function (array $amPaket = [], array $amMaster = []) {
        app()->instance(AboAuskunft::class, new class($amPaket, $amMaster) extends AboAuskunft {
            public function __construct(private array $a, private array $m) {}

            public function abosAmStammPaket(Team $mandant): array
            {
                return $this->a;
            }

            public function abosAmMaster(?int $masterTeamId): array
            {
                return $this->m;
            }
        });
    };
});

// ── B4 Resolver ──────────────────────────────────────────────────────────────

it('MasterTeam: Einstellung vor ENV, ohne Einstellung gilt die ENV, ohne beides kein Master; Wechsel der ENV greift sofort', function () {
    expect(MasterTeam::id())->toBeNull()->and(MasterTeam::quelle())->toBe('keiner');

    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    expect(MasterTeam::id())->toBe($this->rootTeam->id)->and(MasterTeam::quelle())->toBe('env');

    ($this->masterSvc)()->setze($this->master, $this->admin);
    expect(MasterTeam::id())->toBe($this->master->id)->and(MasterTeam::quelle())->toBe('einstellung')
        ->and(MasterTeam::istMaster($this->master))->toBeTrue()->and(MasterTeam::istMaster($this->rootTeam))->toBeFalse();

    ($this->masterSvc)()->setze(null, $this->admin);   // Eintrag entfernen → ENV
    expect(MasterTeam::id())->toBe($this->rootTeam->id)->and(MasterTeam::quelle())->toBe('env');
});

it('MasterTeam: fehlt die Tabelle, fällt die Auflösung auf die ENV zurück statt zu scheitern', function () {
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    \Illuminate\Support\Facades\Schema::drop('foodalchemist_plattform_einstellungen');
    MasterTeam::flush();

    expect(MasterTeam::id())->toBe($this->rootTeam->id);
});

it('Alle Stellen lesen den Master über den Resolver: Kundengrenze, istMasterTeam, Paket und TeamScope folgen der Einstellung', function () {
    $kind = Team::create(['name' => 'Mandant Kind', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->master->id]);
    $rechte = app(FaRechte::class);

    expect($rechte->kundenHauptTeam($kind)->id)->toBe($this->master->id);          // ohne Master: oberstes Team der Kette
    ($this->masterSvc)()->setze($this->master, $this->admin);
    expect($rechte->kundenHauptTeam($kind)->id)->toBe($kind->id)                   // Kette endet am Team direkt unter dem Master
        ->and($rechte->istMasterTeam($this->master))->toBeTrue()
        ->and(StammPaket::istFreigeschaltet($this->master))->toBeTrue()            // Master hat das Paket immer
        ->and(\Platform\FoodAlchemist\Support\TeamScope::isMaster($this->master))->toBeTrue();
});

// ── Rechte ───────────────────────────────────────────────────────────────────

it('nur Plattform-Admin steuert: Inhaber, Betrachter, kein Benutzer und KI-Benutzer bekommen FaRechtFehltException', function () {
    $ki = $this->makeUser($this->rootTeam, 'KI');
    config(['platform-shell.admins' => [strtolower($this->admin->email), strtolower($ki->email)]]);
    $ki->forceFill(['type' => 'ai_user'])->save();

    foreach ([$this->inhaber, null, $ki] as $user) {
        expect(fn () => ($this->paket)()->buchen($this->soul, $user))->toThrow(FaRechtFehltException::class)
            ->and(fn () => ($this->masterSvc)()->setze($this->master, $user))->toThrow(FaRechtFehltException::class);
    }
    expect(DB::table('foodalchemist_team_stamm_optins')->count())->toBe(0)->and(DB::table('foodalchemist_plattform_einstellungen')->count())->toBe(0);
})->skip(fn () => ! method_exists(\Platform\Core\Models\User::class, 'isAiUser'), 'User::isAiUser fehlt');

// ── B1 ───────────────────────────────────────────────────────────────────────

it('B1: Stamm-Paket buchen und abbestellen, wirkt auf die Sichtbarkeit und wird protokolliert', function () {
    $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'sp|b1', 'name' => 'Mehl: Paket', 'stamm_class' => 'stamm_paket']);
    $sichtbar = fn () => FoodAlchemistGp::query()->visibleToTeam($this->soul)->whereKey($gp->id)->exists();
    $this->protokoll->shouldReceive('schreibe')->once()->with('stamm_paket_gebucht', Mockery::on(fn ($d) => $d['team_id'] === $this->soul->id), $this->admin, Mockery::type(\Illuminate\Database\Eloquent\Model::class));
    $this->protokoll->shouldReceive('schreibe')->once()->with('stamm_paket_abbestellt', Mockery::on(fn ($d) => $d['abos_betroffen'] === 0), $this->admin, Mockery::type(\Illuminate\Database\Eloquent\Model::class));

    expect($sichtbar())->toBeFalse();
    expect(($this->paket)()->buchen($this->soul, $this->admin)['gebucht'])->toBeTrue()->and($sichtbar())->toBeTrue();
    ($this->paket)()->buchen($this->soul, $this->admin);                           // idempotent, kein zweites Protokoll
    expect(($this->paket)()->abbestellen($this->soul, $this->admin)['gebucht'])->toBeFalse()->and($sichtbar())->toBeFalse();
});

it('B1: Mandant ist das Haupt-Team — Unter-Teams und das Master-Team lassen sich nicht buchen; die Übersicht listet nur Mandanten', function () {
    ($this->masterSvc)()->setze($this->master, $this->admin);

    expect(fn () => ($this->paket)()->buchen($this->childA, $this->admin))->toThrow(RuntimeException::class, 'Haupt-Team');
    expect(fn () => ($this->paket)()->buchen($this->master, $this->admin))->toThrow(RuntimeException::class, 'Master-Team');

    $namen = collect(($this->paket)()->uebersicht())->pluck('name')->all();
    expect($namen)->toContain('Soulkitchen')->not->toContain('BHG.DIGITAL')->not->toContain('Kind A');
});

it('B1: Abbestell-Vorschau ist heute leer; mit Abos braucht das Abbestellen eine ausdrückliche Bestätigung', function () {
    ($this->paket)()->buchen($this->soul, $this->admin);
    expect(($this->paket)()->vorschauAbbestellen($this->soul))->toMatchArray(['abos' => [], 'bestaetigung_noetig' => false, 'gebucht' => true]);

    ($this->mitAbos)([['id' => 7, 'sammlung' => 'Saucen', 'master_team_id' => $this->master->id]]);
    expect(($this->paket)()->vorschauAbbestellen($this->soul)['bestaetigung_noetig'])->toBeTrue();
    expect(fn () => ($this->paket)()->abbestellen($this->soul, $this->admin))->toThrow(RuntimeException::class, '1 Abo');
    expect(($this->paket)()->status($this->soul)['gebucht'])->toBeTrue();

    expect(($this->paket)()->abbestellen($this->soul, $this->admin, true)['gebucht'])->toBeFalse();
});

// ── B4 ───────────────────────────────────────────────────────────────────────

it('B4: ohne Abos wechselt das Master-Team direkt; mit Abos nur nach Vorschau und Bestätigung; alles wird protokolliert', function () {
    $this->protokoll->shouldReceive('schreibe')->times(2)->with('master_team_gesetzt', Mockery::type('array'), $this->admin, Mockery::type(\Illuminate\Database\Eloquent\Model::class));

    ($this->masterSvc)()->setze($this->master, $this->admin);
    expect(MasterTeam::id())->toBe($this->master->id);

    ($this->mitAbos)([], [['id' => 1, 'mandant_team_id' => $this->soul->id, 'sammlung' => 'Saucen']]);
    $v = ($this->masterSvc)()->vorschau($this->rootTeam->id);
    expect($v)->toMatchArray(['von' => $this->master->id, 'nach' => $this->rootTeam->id, 'bestaetigung_noetig' => true, 'aendert_sich' => true]);
    expect(fn () => ($this->masterSvc)()->setze($this->rootTeam, $this->admin))->toThrow(RuntimeException::class, 'bestätigen');
    expect(MasterTeam::id())->toBe($this->master->id);

    ($this->masterSvc)()->setze($this->rootTeam, $this->admin, true);
    expect(MasterTeam::id())->toBe($this->rootTeam->id);
});

// ── B2 ───────────────────────────────────────────────────────────────────────

it('B2: „Unter-Teams & Freigaben“ ist ein Bereich im Katalog und schaltbar (nur Plattform-Admin)', function () {
    expect(FaBereiche::KATALOG)->toHaveKey('unterteams')->and(FaBereiche::KATALOG['unterteams'])->toBe('Unter-Teams & Freigaben');
    $rechte = app(FaRechte::class);

    expect($rechte->bereichAktiv($this->rootTeam, 'unterteams'))->toBeTrue();              // Default: an
    expect(fn () => $rechte->setzeTeamBereich($this->rootTeam, $this->inhaber, 'unterteams', false))->toThrow(FaRechtFehltException::class);
    $rechte->setzeTeamBereich($this->rootTeam, $this->admin, 'unterteams', false);
    expect($rechte->bereichAktiv($this->rootTeam, 'unterteams'))->toBeFalse()->and($rechte->bereichAktiv($this->childA, 'unterteams'))->toBeFalse();   // Unter-Team erbt
});

// ── Oberfläche (eingebettet) ─────────────────────────────────────────────────

it('Komponente Stamm-Paket: Plattform-Admin bucht und bestellt mit Vorschau ab, andere sehen nur den Hinweis', function () {
    $this->actingAs($this->inhaber);
    Livewire::test(StammPaketKomponente::class)->assertSee('nur Plattform-Admins')->assertDontSeeHtml('data-stamm-paket-zeile');

    $this->actingAs($this->admin);
    $c = Livewire::test(StammPaketKomponente::class)->assertSeeHtml('data-stamm-paket-zeile="' . $this->soul->id . '"')
        ->call('buchen', $this->soul->id)->assertSet('fehler', null)->assertSee('gebucht');
    expect(($this->paket)()->status($this->soul)['gebucht'])->toBeTrue();

    $c->call('vorschau', $this->soul->id)->assertSeeHtml('data-stamm-paket-vorschau')->assertSee('Keine Abos')
        ->call('abbestellen', $this->soul->id)->assertSet('fehler', null);
    expect(($this->paket)()->status($this->soul)['gebucht'])->toBeFalse();
});

it('Komponente Stamm-Paket: die Tabelle scrollt waagerecht und die Aktionsspalte bricht nicht um (Knopf „Abbestellen …“ wird nicht abgeschnitten)', function () {
    ($this->paket)()->buchen($this->soul, $this->admin);
    $this->actingAs($this->admin);

    $html = Livewire::test(StammPaketKomponente::class)->html();

    expect($html)->toContain('overflow-x-auto')->toContain('data-stamm-paket-tabelle')
        ->and($html)->toMatch('/<td[^>]*whitespace-nowrap[^>]*data-stamm-paket-aktion[^>]*>.*?data-stamm-paket-abbestellen/s');
});

it('Komponente Master-Team: Vorschau, Bestätigung und Rechte', function () {
    $this->actingAs($this->inhaber);
    Livewire::test(MasterTeamKomponente::class)->assertSee('nur Plattform-Admins');

    $this->actingAs($this->admin);
    Livewire::test(MasterTeamKomponente::class)->assertSee('Kein Master-Team festgelegt')
        ->set('zielTeamId', (string) $this->master->id)->call('vorschau')->assertSeeHtml('data-master-team-vorschau-box')->assertSee('Keine Abos betroffen')
        ->call('festlegen')->assertSet('fehler', null)->assertSee('Master-Team gesetzt');
    expect(MasterTeam::id())->toBe($this->master->id);
});

// ── MCP ──────────────────────────────────────────────────────────────────────

it('MCP: stamm_paket.GET/PUT und master_team.GET/PUT — Plattform-Admin ja, Inhaber FORBIDDEN, Bestätigung bei Abos', function () {
    $ctx = fn ($user) => new ToolContext($user, $this->rootTeam);

    $r = (new StammPaketPutTool)->execute(['team_id' => $this->soul->id, 'gebucht' => true], $ctx($this->admin));
    expect($r->success)->toBeTrue(json_encode([$r->errorCode, $r->error ?? null]))->and($r->data['status']['gebucht'])->toBeTrue();
    $g = (new StammPaketGetTool)->execute(['team_id' => $this->soul->id], $ctx($this->admin));
    expect($g->data['status']['gebucht'])->toBeTrue()->and($g->data['abbestellen_vorschau']['abos'])->toBe([]);
    expect(collect((new StammPaketGetTool)->execute([], $ctx($this->admin))->data['mandanten'])->pluck('name')->all())->toContain('Soulkitchen');

    foreach ([new StammPaketGetTool, new StammPaketPutTool, new MasterTeamGetTool, new MasterTeamPutTool] as $tool) {
        expect($tool->execute(['team_id' => $this->soul->id, 'gebucht' => false], $ctx($this->inhaber))->errorCode)->toBe('FORBIDDEN');
    }

    $m = (new MasterTeamPutTool)->execute(['team_id' => $this->master->id], $ctx($this->admin));   // noch keine Abos: direkt
    expect($m->success)->toBeTrue()->and($m->data['master']['team_id'])->toBe($this->master->id)->and($m->data['master']['quelle'])->toBe('einstellung');

    ($this->mitAbos)([['id' => 1, 'sammlung' => 'Saucen', 'master_team_id' => $this->master->id]], [['id' => 1, 'mandant_team_id' => $this->soul->id, 'sammlung' => 'Saucen']]);
    expect((new StammPaketPutTool)->execute(['team_id' => $this->soul->id, 'gebucht' => false], $ctx($this->admin))->errorCode)->toBe('VALIDATION_ERROR');
    expect((new StammPaketPutTool)->execute(['team_id' => $this->soul->id, 'gebucht' => false, 'bestaetigt' => true], $ctx($this->admin))->success)->toBeTrue();

    expect((new MasterTeamPutTool)->execute(['team_id' => $this->rootTeam->id], $ctx($this->admin))->errorCode)->toBe('VALIDATION_ERROR');   // Abos am Master, keine Bestätigung
    expect((new MasterTeamGetTool)->execute(['neues_team_id' => $this->rootTeam->id], $ctx($this->admin))->data['vorschau']['bestaetigung_noetig'])->toBeTrue();
    expect((new MasterTeamPutTool)->execute(['team_id' => 0, 'bestaetigt' => true], $ctx($this->admin))->data['master']['quelle'])->toBe('keiner');
});
