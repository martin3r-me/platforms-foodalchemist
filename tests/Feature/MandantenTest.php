<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\MandantenService;
use Platform\FoodAlchemist\Services\MasterTeamService;
use Platform\FoodAlchemist\Services\PlattformProtokoll;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\MandantenGetTool;
use Platform\FoodAlchemist\Tools\MandantenPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §9/§13 B3 + G: Mandanten-Tabelle (Status, Modus kuratiert|autark, CRM-Firma, Notiz).
 * Modus nur Plattform-Admin + Protokoll; Status/CRM/Notiz auch Master-Admin; reine Verwaltungsdaten.
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
    $this->masterAdmin = $this->makeUser($this->master, 'Master Admin', 'admin');
    $this->masterMitglied = $this->makeUser($this->master, 'Master Mitglied', 'member');

    $this->protokoll = Mockery::mock(PlattformProtokoll::class)->shouldIgnoreMissing();
    app()->instance(PlattformProtokoll::class, $this->protokoll);
    app(MasterTeamService::class)->setze($this->master, $this->admin);
    $this->svc = fn () => app(MandantenService::class);
    $this->crmFirma = fn (int $teamId, bool $aktiv = true) => DB::table('crm_companies')->insertGetId(['name' => 'Soulkitchen GmbH', 'team_id' => $teamId, 'is_active' => $aktiv, 'created_at' => now(), 'updated_at' => now()]);
});

it('Ohne Zeile gilt aktiv + autark; die Übersicht listet nur Mandanten (kein Master, keine Unter-Teams)', function () {
    $liste = collect(($this->svc)()->uebersicht());

    expect($liste->pluck('name')->all())->toContain('Soulkitchen')->not->toContain('BHG.DIGITAL')->not->toContain('Kind A');
    $soul = $liste->firstWhere('name', 'Soulkitchen');
    expect($soul['status'])->toBe('aktiv')->and($soul['modus'])->toBe('autark')->and($soul['hat_zeile'])->toBeFalse()->and($soul['crm_company_id'])->toBeNull();
});

it('Plattform-Admin pflegt Status, Notiz und Modus; die Zeile entsteht beim ersten Schreiben', function () {
    $this->protokoll->shouldReceive('schreibe')->once()->with('mandant_modus_geaendert', Mockery::on(fn ($d) => $d['von'] === 'autark' && $d['nach'] === 'kuratiert' && $d['team_id'] === $this->soul->id), $this->admin, Mockery::type(\Illuminate\Database\Eloquent\Model::class));
    $this->protokoll->shouldReceive('schreibe')->once()->with('mandant_gepflegt', Mockery::on(fn ($d) => $d['felder'] === ['status'] && ! array_key_exists('notiz', $d)), $this->admin, Mockery::type(\Illuminate\Database\Eloquent\Model::class));

    $m = ($this->svc)()->setze($this->soul, ['status' => 'projekt', 'modus' => 'kuratiert', 'notiz' => ''], $this->admin);

    expect($m['status'])->toBe('projekt')->and($m['modus'])->toBe('kuratiert')->and($m['hat_zeile'])->toBeTrue()->and($m['notiz'])->toBeNull();
    $zeile = DB::table('foodalchemist_mandanten')->where('team_id', $this->soul->id)->first();
    expect($zeile->modus_geaendert_von)->toBe($this->admin->id)->and($zeile->geaendert_von)->toBe($this->admin->id);
});

it('Nicht übergebene Felder bleiben; unveränderte Werte erzeugen kein Protokoll', function () {
    ($this->svc)()->setze($this->soul, ['status' => 'projekt', 'notiz' => 'Start Q4'], $this->admin);
    $this->protokoll->shouldNotReceive('schreibe');

    $m = ($this->svc)()->setze($this->soul, ['status' => 'projekt'], $this->admin);

    expect($m['notiz'])->toBe('Start Q4')->and($m['modus'])->toBe('autark');
});

it('Modus umschalten darf NUR der Plattform-Admin: Master-Admin, Master-Mitglied, Inhaber, KI und kein Benutzer scheitern', function () {
    ($this->svc)()->setze($this->soul, ['modus' => 'kuratiert'], $this->admin);

    foreach ([$this->masterAdmin, $this->masterMitglied, $this->inhaber, null] as $user) {
        expect(fn () => ($this->svc)()->setze($this->soul, ['modus' => 'autark'], $user))->toThrow(FaRechtFehltException::class);
    }
    expect(DB::table('foodalchemist_mandanten')->where('team_id', $this->soul->id)->value('modus'))->toBe('kuratiert');
})->skip(fn () => ! method_exists(\Platform\Core\Models\User::class, 'isAiUser'), 'User::isAiUser fehlt');

it('Ein Master-Admin pflegt Status, CRM-Firma und Notiz, schaltet den Modus aber nicht um (er könnte sich sonst selbst Einblick geben)', function () {
    $firma = ($this->crmFirma)($this->master->id);

    $m = ($this->svc)()->setze($this->soul, ['status' => 'projekt', 'crm_company_id' => $firma, 'notiz' => 'LAGO folgt'], $this->masterAdmin);

    expect($m['status'])->toBe('projekt')->and($m['crm_company_id'])->toBe($firma)->and($m['crm_firma'])->toBe('Soulkitchen GmbH')->and($m['modus'])->toBe('autark');
    expect(fn () => ($this->svc)()->setze($this->soul, ['status' => 'aktiv', 'modus' => 'kuratiert'], $this->masterAdmin))->toThrow(FaRechtFehltException::class);
    expect(DB::table('foodalchemist_mandanten')->where('team_id', $this->soul->id)->value('status'))->toBe('projekt');   // nichts halb geschrieben
});

it('Master-Mitglied ohne Admin-Rolle und Inhaber eines Mandanten dürfen weder lesen noch pflegen', function () {
    foreach ([$this->masterMitglied, $this->inhaber] as $user) {
        expect(fn () => ($this->svc)()->pruefeVerwaltung($user, 'Test'))->toThrow(FaRechtFehltException::class)
            ->and(fn () => ($this->svc)()->setze($this->soul, ['status' => 'aktiv'], $user))->toThrow(FaRechtFehltException::class);
    }
});

it('CRM-Firma muss aus dem CRM des Master-Teams stammen und aktiv sein; null löst die Verknüpfung', function () {
    $fremd = ($this->crmFirma)($this->soul->id);
    $inaktiv = ($this->crmFirma)($this->master->id, false);
    $gut = ($this->crmFirma)($this->master->id);

    expect(fn () => ($this->svc)()->setze($this->soul, ['crm_company_id' => $fremd], $this->admin))->toThrow(RuntimeException::class, 'Master-Teams')
        ->and(fn () => ($this->svc)()->setze($this->soul, ['crm_company_id' => $inaktiv], $this->admin))->toThrow(RuntimeException::class, 'inaktiv')
        ->and(fn () => ($this->svc)()->setze($this->soul, ['crm_company_id' => 99999], $this->admin))->toThrow(RuntimeException::class);
    expect(($this->svc)()->setze($this->soul, ['crm_company_id' => $gut], $this->admin)['crm_company_id'])->toBe($gut);
    expect(($this->svc)()->setze($this->soul, ['crm_company_id' => null], $this->admin)['crm_company_id'])->toBeNull();

    app(MasterTeamService::class)->setze(null, $this->admin);   // kein Master mehr → keine Firma verknüpfbar
    expect(fn () => ($this->svc)()->setze($this->soul, ['crm_company_id' => $gut], $this->admin))->toThrow(RuntimeException::class, 'kein Master-Team');
});

it('Ungültiger Status oder Modus, Unter-Team und Master-Team als Mandant werden abgelehnt', function () {
    expect(fn () => ($this->svc)()->setze($this->soul, ['status' => 'gesperrt'], $this->admin))->toThrow(RuntimeException::class, 'Status')
        ->and(fn () => ($this->svc)()->setze($this->soul, ['modus' => 'offen'], $this->admin))->toThrow(RuntimeException::class, 'Modus')
        ->and(fn () => ($this->svc)()->setze($this->childA, ['status' => 'aktiv'], $this->admin))->toThrow(RuntimeException::class, 'Haupt-Team')
        ->and(fn () => ($this->svc)()->setze($this->master, ['status' => 'aktiv'], $this->admin))->toThrow(RuntimeException::class, 'Master-Team');
    expect(DB::table('foodalchemist_mandanten')->count())->toBe(0);
});

it('modusVon: Unter-Teams erben den Modus ihres Mandanten, ohne Zeile autark, das Master-Team gilt als kuratiert', function () {
    $kind = Team::create(['name' => 'LAGO', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->soul->id]);

    expect(($this->svc)()->modusVon($kind))->toBe('autark')->and(($this->svc)()->modusVon($this->master))->toBe('kuratiert');
    ($this->svc)()->setze($this->soul, ['modus' => 'kuratiert'], $this->admin);
    expect(($this->svc)()->modusVon($kind))->toBe('kuratiert')->and(($this->svc)()->modusVon($this->soul))->toBe('kuratiert');
});

it('MCP: mandanten.GET/PUT — Plattform-Admin und Master-Admin ja, Inhaber FORBIDDEN, Modus nur Plattform-Admin', function () {
    $ctx = fn ($user) => new ToolContext($user, $this->rootTeam);

    $r = (new MandantenPutTool)->execute(['team_id' => $this->soul->id, 'status' => 'projekt', 'modus' => 'kuratiert'], $ctx($this->admin));
    expect($r->success)->toBeTrue(json_encode([$r->errorCode, $r->error ?? null]))->and($r->data['mandant']['modus'])->toBe('kuratiert');

    expect((new MandantenGetTool)->execute(['team_id' => $this->soul->id], $ctx($this->masterAdmin))->data['mandant']['status'])->toBe('projekt');
    expect(collect((new MandantenGetTool)->execute([], $ctx($this->admin))->data['mandanten'])->pluck('name')->all())->toContain('Soulkitchen');

    expect((new MandantenPutTool)->execute(['team_id' => $this->soul->id, 'modus' => 'autark'], $ctx($this->masterAdmin))->errorCode)->toBe('FORBIDDEN');
    expect((new MandantenPutTool)->execute(['team_id' => $this->soul->id, 'notiz' => 'ok'], $ctx($this->masterAdmin))->success)->toBeTrue();
    foreach ([new MandantenGetTool, new MandantenPutTool] as $tool) {
        expect($tool->execute(['team_id' => $this->soul->id, 'status' => 'aktiv'], $ctx($this->inhaber))->errorCode)->toBe('FORBIDDEN');
    }
    expect((new MandantenPutTool)->execute(['team_id' => $this->soul->id, 'status' => 'kaputt'], $ctx($this->admin))->errorCode)->toBe('VALIDATION_ERROR');
});

it('Die Verwaltungsdaten enthalten keine Inhalte: nur die erwarteten Schlüssel', function () {
    $m = ($this->svc)()->zeige($this->soul);

    expect(array_keys($m))->toBe(['team_id', 'name', 'status', 'modus', 'crm_company_id', 'crm_firma', 'notiz', 'modus_geaendert_von', 'modus_geaendert_am', 'hat_zeile']);
});
