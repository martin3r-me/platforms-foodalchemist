<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Models\TeamBillableUsage;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Verwaltung\PlanPreise as PlanPreiseKomponente;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanPreis;
use Platform\FoodAlchemist\Services\AboAuskunft;
use Platform\FoodAlchemist\Services\GebuehrenZaehler;
use Platform\FoodAlchemist\Services\MandantenService;
use Platform\FoodAlchemist\Services\MasterTeamService;
use Platform\FoodAlchemist\Services\PlanNutzungService;
use Platform\FoodAlchemist\Services\PlanPreisService;
use Platform\FoodAlchemist\Services\PlattformProtokoll;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\PlanNutzungGetTool;
use Platform\FoodAlchemist\Tools\PlanPreiseGetTool;
use Platform\FoodAlchemist\Tools\PlanPreisePostTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 D (Variante B): Plan-Preise (append-only), täglicher Zähl-Lauf → team_billable_usages, „Plan und Nutzung“.
 * Startpreise sind Platzhalter: Basis 79 (2 User inkl.), User 19, Unter-Team 50, Paket 149, Abo 199.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    \Carbon\Carbon::setTestNow('2026-10-14 09:00:00');

    // Core-Tabelle team_billable_usages (die Allowlist des Harness kennt sie nicht): Spaltenbild wie die Core-Migration
    if (! \Illuminate\Support\Facades\Schema::hasTable('team_billable_usages')) {
        \Illuminate\Support\Facades\Schema::create('team_billable_usages', function ($t) {
            $t->id();
            $t->unsignedBigInteger('team_id');
            $t->string('billable_model');
            $t->string('billable_type');
            $t->string('label');
            $t->date('usage_date');
            $t->unsignedInteger('count')->default(0);
            $t->decimal('cost_per_unit', 8, 4);
            $t->decimal('total_cost', 10, 2);
            $t->json('pricing_snapshot')->nullable();
            $t->timestamps();
            $t->unique(['team_id', 'billable_model', 'usage_date']);
        });
    }

    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    $this->soulInhaber = $this->makeUser($this->soul, 'Soul Inhaber', 'owner');
    $this->soulMember = $this->makeUser($this->soul, 'Soul Mitglied', 'member');
    $this->masterAdmin = $this->makeUser($this->master, 'Master Admin', 'admin');
    app(MasterTeamService::class)->setze($this->master, $this->admin);
    app(MandantenService::class)->setze($this->rootTeam, ['status' => 'beendet'], $this->admin);   // das Seed-Rootteam rechnet nicht mit: nur Soulkitchen zählt

    $this->protokoll = Mockery::mock(PlattformProtokoll::class)->shouldIgnoreMissing();
    app()->instance(PlattformProtokoll::class, $this->protokoll);
    $this->preise = fn () => app(PlanPreisService::class);
    $this->zaehler = fn () => app(GebuehrenZaehler::class);
    ($this->preise)()->startwerteAnlegen($this->admin);
    FoodAlchemistPlanPreis::query()->update(['gueltig_ab' => '2026-01-01']);   // Startwerte gelten für den ganzen Testmonat
});

afterEach(fn () => \Carbon\Carbon::setTestNow());

// ── Preise ───────────────────────────────────────────────────────────────────

it('Startwerte: Platzhalter-Preise je Baustein, idempotent, fasst vorhandene nie an', function () {
    expect(collect(($this->preise)()->aktuell())->pluck('betrag_monat', 'baustein')->all())
        ->toBe(['basis' => '79.00', 'user' => '19.00', 'unterteam' => '50.00', 'stamm_paket' => '149.00', 'master_abo' => '199.00']);
    expect(($this->preise)()->startwerteAnlegen($this->admin))->toBe([]);
    expect(FoodAlchemistPlanPreis::count())->toBe(5);
});

it('Append-only: neuer Preis = neue Zeile, die Vorgängerzeile endet am Vortag, der alte Preis bleibt für alte Tage', function () {
    $this->protokoll->shouldReceive('schreibe')->once()->with('plan_preis_gesetzt', Mockery::on(fn ($d) => $d['baustein'] === 'user' && $d['betrag_monat'] === '21.00' && $d['gueltig_ab'] === '2026-11-01'), $this->admin);

    ($this->preise)()->setze('user', '21', '2026-11-01', 'Preisrunde', $this->admin);

    expect(FoodAlchemistPlanPreis::where('baustein', 'user')->count())->toBe(2);
    expect((string) ($this->preise)()->preisAm('user', '2026-10-31')->betrag_monat)->toBe('19.00')
        ->and((string) ($this->preise)()->preisAm('user', '2026-11-01')->betrag_monat)->toBe('21.00')
        ->and(FoodAlchemistPlanPreis::where('baustein', 'user')->orderBy('gueltig_ab')->first()->gueltig_bis->toDateString())->toBe('2026-10-31');
});

it('Preise: heute darf direkt korrigieren; Vergangenheit, Rückdatierung hinter den Beginn, Müll und Fremde werden abgelehnt', function () {
    ($this->preise)()->setze('basis', '85', null, null, $this->admin);   // heute
    expect((string) ($this->preise)()->preisAm('basis', now())->betrag_monat)->toBe('85.00');

    expect(fn () => ($this->preise)()->setze('basis', '90', '2026-10-01', null, $this->admin))->toThrow(RuntimeException::class, 'Vergangenheit')
        ->and(fn () => ($this->preise)()->setze('basis', '90', '2026-10-14', null, $this->admin))->toThrow(RuntimeException::class, 'schon einen Preis')
        ->and(fn () => ($this->preise)()->setze('kaffee', '90', null, null, $this->admin))->toThrow(RuntimeException::class, 'Baustein')
        ->and(fn () => ($this->preise)()->setze('basis', '-5', null, null, $this->admin))->toThrow(RuntimeException::class, 'Zahl')
        ->and(fn () => ($this->preise)()->setze('basis', '90', 'kein Datum', null, $this->admin))->toThrow(RuntimeException::class, 'Datum')
        ->and(fn () => ($this->preise)()->setze('basis', '90', null, null, $this->soulInhaber))->toThrow(FaRechtFehltException::class)
        ->and(fn () => ($this->preise)()->setze('basis', '90', null, null, $this->masterAdmin))->toThrow(FaRechtFehltException::class)
        ->and(fn () => ($this->preise)()->setze('basis', '90', null, null, null))->toThrow(FaRechtFehltException::class);
    expect(FoodAlchemistPlanPreis::where('baustein', 'basis')->count())->toBe(2);
});

// ── Zählen ───────────────────────────────────────────────────────────────────

it('Mengen: Basis 1, User über Teamzugehörigkeit (2 inklusive, KI zählt nicht, Unter-Teams zählen mit, kein Doppel), Unter-Teams, Paket, Abo', function () {
    $lago = Team::create(['name' => 'LAGO', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->soul->id]);
    $this->makeUser($this->soul, 'Dritter', 'member');
    $vierter = $this->makeUser($lago, 'Vierter', 'member');
    DB::table('team_user')->insert(['team_id' => $this->soul->id, 'user_id' => $vierter->id, 'role' => 'member']);   // in beiden Teams: zählt einmal
    $ki = $this->makeUser($lago, 'KI Helfer', 'member');
    $ki->forceFill(['type' => 'ai_user'])->save();

    $m = ($this->zaehler)()->mengen($this->soul);
    // Inhaber, Mitglied, Dritter, Vierter = 4 Menschen; KI zählt nicht → 2 weitere
    expect($m['basis'])->toBe(1)->and($m['user_gesamt'])->toBe(4)->and($m['user'])->toBe(2)->and($m['unterteam'])->toBe(1)
        ->and($m['stamm_paket'])->toBe(0)->and($m['master_abo'])->toBe(0);

    \Platform\FoodAlchemist\Support\StammPaket::freischalten($this->soul, $this->admin->id);
    app()->instance(AboAuskunft::class, new class extends AboAuskunft {
        public function abosAmStammPaket(Team $mandant): array
        {
            return [['id' => 1, 'sammlung' => 'Saucen', 'master_team_id' => 1]];
        }
    });
    $m = app(GebuehrenZaehler::class)->mengen($this->soul);
    expect($m['stamm_paket'])->toBe(1)->and($m['master_abo'])->toBe(1);
})->skip(fn () => ! method_exists(\Platform\Core\Models\User::class, 'isAiUser'), 'User::isAiUser fehlt');

it('Zählen: Tageswert = Monatspreis × Menge ÷ Tage, in team_billable_usages mit eigenem billable_model je Baustein und Preis-Snapshot', function () {
    // Soulkitchen: 2 User (inklusive), kein Unter-Team, kein Paket → nur Basis 79 €
    $r = ($this->zaehler)()->zaehle($this->soul, '2026-10-14');

    expect($r['ohne_preis'])->toBe([])->and(collect($r['zeilen'])->pluck('baustein')->all())->toBe(['basis']);
    $z = TeamBillableUsage::where('team_id', $this->soul->id)->first();
    expect($z->billable_model)->toBe('foodalchemist.basis')->and($z->billable_type)->toBe('flat_fee')->and($z->label)->toBe('Food Alchemist · Basis je Mandant (2 User inklusive)')
        ->and($z->count)->toBe(1)->and($z->total_cost)->toBe(round(79 / 31, 2))->and($z->pricing_snapshot['quelle'])->toBe('foodalchemist_plan_preise');
});

it('Zählen: Lauf ist idempotent, Bausteine ohne Menge schreiben nichts, ohne Preis wird übersprungen und gemeldet', function () {
    ($this->zaehler)()->zaehle($this->soul, '2026-10-14');
    ($this->zaehler)()->zaehle($this->soul, '2026-10-14');
    expect(TeamBillableUsage::where('team_id', $this->soul->id)->count())->toBe(1);

    FoodAlchemistPlanPreis::where('baustein', 'basis')->delete();
    $r = ($this->zaehler)()->zaehle($this->soul, '2026-10-15');
    expect($r['ohne_preis'])->toBe(['basis'])->and(TeamBillableUsage::where('team_id', $this->soul->id)->whereDate('usage_date', '2026-10-15')->count())->toBe(0);
});

it('Zählen: der letzte Tag des Monats gleicht die Rundung aus — die Monatssumme ist exakt der Monatspreis', function () {
    foreach (range(1, 31) as $tag) {
        ($this->zaehler)()->zaehle($this->soul, sprintf('2026-10-%02d', $tag));
    }

    $summe = TeamBillableUsage::where('team_id', $this->soul->id)->where('billable_model', 'foodalchemist.basis')->sum('total_cost');
    expect(round((float) $summe, 2))->toBe(79.0)
        ->and((float) TeamBillableUsage::where('team_id', $this->soul->id)->whereDate('usage_date', '2026-10-31')->value('total_cost'))->not->toBe(round(79 / 31, 2));
});

it('Zählen: Unter-Teams und weitere User kosten, Paket und Abo erst wenn gebucht; Summe passt zum Spec-Beispiel (98 € bei 3 Usern)', function () {
    $this->makeUser($this->soul, 'Dritter', 'member');   // 3 User → 1 weiterer
    $monat = fn () => round((float) TeamBillableUsage::where('team_id', $this->soul->id)->sum('total_cost'), 2);
    foreach (range(1, 31) as $tag) {
        ($this->zaehler)()->zaehle($this->soul, sprintf('2026-10-%02d', $tag));
    }
    expect($monat())->toBe(98.0);   // 79 + 19
});

it('Abrechenbar sind nur aktive Mandanten: Master-Team, Unter-Teams, Projekt und beendet werden nicht gezählt', function () {
    $kind = Team::create(['name' => 'LAGO', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->soul->id]);
    $namen = fn () => ($this->zaehler)()->abrechenbareMandanten()->pluck('name')->all();

    expect($namen())->toContain('Soulkitchen')->not->toContain('BHG.DIGITAL')->not->toContain('LAGO');
    app(MandantenService::class)->setze($this->soul, ['status' => 'projekt'], $this->admin);
    expect($namen())->not->toContain('Soulkitchen');
    app(MandantenService::class)->setze($this->soul, ['status' => 'beendet'], $this->admin);
    expect($namen())->not->toContain('Soulkitchen');
    app(MandantenService::class)->setze($this->soul, ['status' => 'aktiv'], $this->admin);
    expect($namen())->toContain('Soulkitchen');
});

it('Command: --dry-run schreibt nichts, der echte Lauf schreibt, --von/--bis holt Tage nach', function () {
    $this->artisan('foodalchemist:plan-nutzung-zaehlen', ['--datum' => '2026-10-14', '--dry-run' => true, '--team' => $this->soul->id])->assertSuccessful();
    expect(TeamBillableUsage::count())->toBe(0);

    $this->artisan('foodalchemist:plan-nutzung-zaehlen', ['--von' => '2026-10-12', '--bis' => '2026-10-14', '--team' => $this->soul->id])->assertSuccessful();
    expect(TeamBillableUsage::where('team_id', $this->soul->id)->count())->toBe(3);

    $this->artisan('foodalchemist:plan-nutzung-zaehlen', ['--von' => '2026-10-14', '--bis' => '2026-10-12'])->assertFailed();
});

// ── Plan und Nutzung ─────────────────────────────────────────────────────────

it('Plan und Nutzung: Positionen mit Menge, Monatsvorschau und gebuchten Tageswerten', function () {
    ($this->zaehler)()->zaehle($this->soul, '2026-10-01');
    ($this->zaehler)()->zaehle($this->soul, '2026-10-02');

    $m = app(PlanNutzungService::class)->monat($this->soul, $this->admin, '2026-10');

    expect($m['name'])->toBe('Soulkitchen')->and($m['positionen'])->toHaveCount(1)->and($m['positionen'][0]['baustein'])->toBe('basis')
        ->and($m['positionen'][0]['monatsbetrag_vorschau'])->toBe(79.0)->and($m['positionen'][0]['tage_gebucht'])->toBe(2)
        ->and($m['summe_gebucht'])->toBe(round(2 * 79 / 31, 2))->and($m['summe_vorschau'])->toBe(79.0);
});

it('Plan und Nutzung: Plattform-Admin, Master-Admin und Inhaber/Admin des Mandanten dürfen; Mitglied, fremder Inhaber und KI nicht', function () {
    $fremd = $this->makeUser($this->rootTeam, 'Fremder Inhaber', 'owner');
    $svc = app(PlanNutzungService::class);

    foreach ([$this->admin, $this->masterAdmin, $this->soulInhaber] as $user) {
        expect($svc->monat($this->soul, $user)['team_id'])->toBe($this->soul->id);
    }
    foreach ([$this->soulMember, $fremd, null] as $user) {
        expect(fn () => $svc->monat($this->soul, $user))->toThrow(FaRechtFehltException::class);
    }
    expect(fn () => $svc->monat($this->master, $this->admin))->toThrow(RuntimeException::class, 'Master-Team');
});

it('Plan und Nutzung: ein Unter-Team zeigt die Gebühren seines Mandanten (Inhaber des Mandanten)', function () {
    $lago = Team::create(['name' => 'LAGO', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->soul->id]);

    expect(app(PlanNutzungService::class)->monat($lago, $this->soulInhaber)['name'])->toBe('Soulkitchen');
});

it('Auswirkung: Mehr-/Minderbetrag je aktivem Mandant, nur Zählung', function () {
    $r = app(PlanNutzungService::class)->auswirkung('basis', '89');

    expect($r['aktuell'])->toBe('79.00')->and($r['neu'])->toBe('89.00')->and($r['summe_differenz'])->toBe(10.0)->and($r['mandanten'][0]['name'])->toBe('Soulkitchen');
    expect(app(PlanNutzungService::class)->auswirkung('user', '25')['mandanten'])->toBe([]);   // keine weiteren User → trifft niemanden
});

// ── Oberfläche und MCP ───────────────────────────────────────────────────────

it('Komponente Plan-Preise: Auswirkung prüfen, dann anlegen; Nicht-Admin sieht nur den Hinweis', function () {
    $this->actingAs($this->soulInhaber);
    Livewire::test(PlanPreiseKomponente::class)->assertSee('nur Plattform-Admins')->assertDontSee('Soulkitchen');

    $this->actingAs($this->admin);
    Livewire::test(PlanPreiseKomponente::class)->assertSeeHtml('data-plan-preis-zeile="user"')->assertSeeHtml('overflow-x-auto')
        ->set('baustein', 'basis')->set('betrag', '89,50')->call('pruefen')->assertSeeHtml('data-plan-preise-auswirkung')
        ->call('anlegen')->assertSet('fehler', null)->assertSee('Preis angelegt');
    expect((string) ($this->preise)()->preisAm('basis', now())->betrag_monat)->toBe('89.50');
});

it('MCP: plan_preise.GET/POST und plan_nutzung.GET — Rechte, Auswirkung, Fehlercodes', function () {
    $ctx = fn ($user) => new ToolContext($user, $this->soul);

    $g = (new PlanPreiseGetTool)->execute(['baustein' => 'basis', 'neuer_betrag' => 89], $ctx($this->admin));
    expect($g->success)->toBeTrue(json_encode([$g->errorCode, $g->error ?? null]))->and($g->data['auswirkung']['summe_differenz'])->toBe(10.0)->and($g->data['aktuell'])->toHaveCount(5);
    $p = (new PlanPreisePostTool)->execute(['baustein' => 'user', 'betrag_monat' => 21, 'gueltig_ab' => '2026-11-01'], $ctx($this->admin));
    expect($p->success)->toBeTrue()->and($p->data['preis']['gueltig_ab'])->toBe('2026-11-01');
    expect((new PlanPreisePostTool)->execute(['baustein' => 'kaffee', 'betrag_monat' => 1], $ctx($this->admin))->errorCode)->toBe('VALIDATION_ERROR');

    foreach ([new PlanPreiseGetTool, new PlanPreisePostTool] as $tool) {
        expect($tool->execute(['baustein' => 'user', 'betrag_monat' => 1], $ctx($this->soulInhaber))->errorCode)->toBe('FORBIDDEN');
    }
    $n = (new PlanNutzungGetTool)->execute([], $ctx($this->soulInhaber));
    expect($n->success)->toBeTrue(json_encode([$n->errorCode, $n->error ?? null]))->and($n->data['name'])->toBe('Soulkitchen');
    expect((new PlanNutzungGetTool)->execute(['team_id' => $this->soul->id], $ctx($this->soulMember))->errorCode)->toBe('FORBIDDEN');
});
