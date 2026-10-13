<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Verwaltung\Grundeinstellungen;
use Platform\FoodAlchemist\Services\PaketGrundeinstellungenService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\TeamScope;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 I — Paket „Grundeinstellungen": buchen per Kopie ins Oberteam, Unter-Teams erben, idempotent, nur Plattform-Admin, protokolliert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();

    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->actingAs($this->admin);
    $this->svc = app(PaketGrundeinstellungenService::class);

    $zeit = ['created_at' => now(), 'updated_at' => now()];
    $ins = fn (string $t, array $z) => DB::table('foodalchemist_'.$t)->insertGetId($z + ['uuid' => (string) UuidV7::generate(), 'team_id' => $this->master->id] + $zeit);
    $this->ins = $ins;
    $ins('writing_styles', ['slug' => 'sachlich', 'name' => 'Sachlich', 'sprach_duktus' => 'nüchtern']);
    $ins('writing_styles', ['slug' => 'poetisch', 'name' => 'Poetisch', 'sprach_duktus' => 'bildhaft']);
    $this->kat = $ins('concept_categories', ['name' => 'Bankett', 'position' => 1]);
    $this->unterKat = $ins('concept_categories', ['name' => 'Gala', 'position' => 2, 'parent_id' => $this->kat]);
    $ins('target_groups', ['name' => 'Tagungsgast']);
    $ins('kitchen_roles', ['slug' => 'koch', 'name' => 'Koch', 'sort_order' => 1, 'stundensatz_eur' => 55.5]);
    $ins('production_stations', ['slug' => 'kalt', 'name' => 'Kalte Küche', 'sort_order' => 1]);
    $ins('menu_plan_chips', ['label' => 'Suppe', 'sort_order' => 1]);
    $ins('presentation_designs', ['name' => 'Klassisch']);

    $this->zeilen = fn (string $t, Team $team) => DB::table('foodalchemist_'.$t)->where('team_id', $team->id)->whereNull('deleted_at')->count();
});

it('★ buchen kopiert die Vorlagen ins Oberteam; zweites Buchen kopiert nichts doppelt', function () {
    $r = $this->svc->buchen($this->rootTeam, $this->admin);

    expect($r['gebucht'])->toBeTrue()
        ->and(($this->zeilen)('writing_styles', $this->rootTeam))->toBe(2)
        ->and(($this->zeilen)('concept_categories', $this->rootTeam))->toBe(2)
        ->and(($this->zeilen)('target_groups', $this->rootTeam))->toBe(1)
        ->and(($this->zeilen)('kitchen_roles', $this->rootTeam))->toBe(1)
        ->and(($this->zeilen)('production_stations', $this->rootTeam))->toBe(1)
        ->and(($this->zeilen)('menu_plan_chips', $this->rootTeam))->toBe(1)
        ->and(($this->zeilen)('presentation_designs', $this->rootTeam))->toBe(1);

    $nochmal = $this->svc->buchen($this->rootTeam, $this->admin);

    expect(array_sum(array_column($nochmal['ergebnis'], 'neu')))->toBe(0)
        ->and(($this->zeilen)('writing_styles', $this->rootTeam))->toBe(2)
        ->and(($this->zeilen)('concept_categories', $this->rootTeam))->toBe(2)
        ->and(DB::table('foodalchemist_team_pakete')->where('team_id', $this->rootTeam->id)->count())->toBe(1);
});

it('★ Unter-Teams erben die Kopie über die Kette, ohne eigene Kopie', function () {
    $this->svc->buchen($this->rootTeam, $this->admin);

    foreach ([$this->childA, $this->childB] as $kind) {
        $slugs = TeamScope::applyVisible(DB::table('foodalchemist_writing_styles')->whereNull('deleted_at'), 'team_id', $kind)->pluck('slug')->all();
        expect($slugs)->toContain('sachlich', 'poetisch');
        expect(($this->zeilen)('writing_styles', $kind))->toBe(0);
    }
});

it('Konzept-Kategorien: parent_id zeigt auf die KOPIE des Elternteils, nicht auf die Master-Zeile', function () {
    $this->svc->buchen($this->rootTeam, $this->admin);

    $eltern = DB::table('foodalchemist_concept_categories')->where('team_id', $this->rootTeam->id)->where('name', 'Bankett')->first();
    $kind = DB::table('foodalchemist_concept_categories')->where('team_id', $this->rootTeam->id)->where('name', 'Gala')->first();

    expect((int) $kind->parent_id)->toBe((int) $eltern->id)->and((int) $eltern->id)->not->toBe((int) $this->kat);
});

it('Rollen kommen ohne Stundensätze (BHG-intern)', function () {
    $this->svc->buchen($this->rootTeam, $this->admin);

    expect(DB::table('foodalchemist_kitchen_roles')->where('team_id', $this->rootTeam->id)->value('stundensatz_eur'))->toBeNull();
});

it('eine gleichnamige Team-Zeile wird nicht überschrieben (gilt als vorhanden)', function () {
    ($this->ins)('writing_styles', ['team_id' => $this->rootTeam->id, 'slug' => 'sachlich', 'name' => 'Unser Stil', 'sprach_duktus' => 'eigen']);

    $r = $this->svc->buchen($this->rootTeam, $this->admin);

    expect(DB::table('foodalchemist_writing_styles')->where('team_id', $this->rootTeam->id)->where('slug', 'sachlich')->value('name'))->toBe('Unser Stil')
        ->and(($this->zeilen)('writing_styles', $this->rootTeam))->toBe(2);   // 1 eigene + 1 kopierte (poetisch)
    $ws = collect($r['ergebnis'])->firstWhere('tabelle', 'writing_styles');
    expect($ws['vorhanden'])->toBe(1)->and($ws['neu'])->toBe(1);
});

it('eine später hinzugekommene Vorlage wird beim erneuten Buchen nachgezogen', function () {
    $this->svc->buchen($this->rootTeam, $this->admin);
    ($this->ins)('target_groups', ['name' => 'VIP']);

    $r = $this->svc->buchen($this->rootTeam, $this->admin);

    expect(collect($r['ergebnis'])->firstWhere('tabelle', 'target_groups')['neu'])->toBe(1)
        ->and(($this->zeilen)('target_groups', $this->rootTeam))->toBe(2);
});

it('nur Plattform-Admin, nur Haupt-Team, nie das Master-Team, nur mit Master-Konfiguration', function () {
    $keinAdmin = $this->makeUser($this->rootTeam, 'Normal');
    expect(fn () => $this->svc->buchen($this->rootTeam, $keinAdmin))->toThrow(FaRechtFehltException::class);
    expect(fn () => $this->svc->buchen($this->childA, $this->admin))->toThrow(RuntimeException::class);
    expect(fn () => $this->svc->buchen($this->master, $this->admin))->toThrow(RuntimeException::class);

    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    expect(fn () => $this->svc->buchen($this->rootTeam, $this->admin))->toThrow(RuntimeException::class, 'Master');
});

it('Vorschau zählt Quelle, bereits zugeordnet und neu, ohne etwas zu schreiben', function () {
    $v = $this->svc->vorschau($this->rootTeam);
    $ws = collect($v['tabellen'])->firstWhere('tabelle', 'writing_styles');

    expect($ws['quelle'])->toBe(2)->and($ws['neu'])->toBe(2)->and(($this->zeilen)('writing_styles', $this->rootTeam))->toBe(0);
    $this->svc->buchen($this->rootTeam, $this->admin);
    $nach = collect($this->svc->vorschau($this->rootTeam)['tabellen'])->firstWhere('tabelle', 'writing_styles');
    expect($nach['bereits'])->toBe(2)->and($nach['neu'])->toBe(0);
});

it('Buchen wird protokolliert (Plattform-Protokoll am Paket-Modell)', function () {
    if (! method_exists(\Platform\FoodAlchemist\Models\FoodAlchemistTeamPaket::class, 'logActivity') || ! \Illuminate\Support\Facades\Schema::hasTable('activity_log_activities')) {
        $this->markTestSkipped('Echtes activity-log nicht verfügbar.');
    }
    $this->svc->buchen($this->rootTeam, $this->admin);

    $n = DB::table('activity_log_activities')->where('activityable_type', \Platform\FoodAlchemist\Models\FoodAlchemistTeamPaket::class)
        ->where('message', 'Paket „Grundeinstellungen“ gebucht')->count();
    expect($n)->toBe(1);
});

it('MCP: paket_grundeinstellungen.GET/PUT nur für Plattform-Admin; Buchen per Tool', function () {
    $reg = app(ToolRegistry::class);
    $kontext = new ToolContext($this->admin, $this->rootTeam);

    $put = $reg->get('foodalchemist.paket_grundeinstellungen.PUT')->execute(['team_id' => $this->rootTeam->id], $kontext);
    expect($put->success)->toBeTrue('PUT: ' . ($put->error ?? ''));
    $get = $reg->get('foodalchemist.paket_grundeinstellungen.GET')->execute(['team_id' => $this->rootTeam->id], $kontext);
    expect($get->data['status']['gebucht'])->toBeTrue();

    $normal = $this->makeUser($this->rootTeam, 'Normal');
    $verboten = $reg->get('foodalchemist.paket_grundeinstellungen.PUT')->execute(['team_id' => $this->rootTeam->id], new ToolContext($normal, $this->rootTeam));
    expect($verboten->success)->toBeFalse()->and($verboten->errorCode)->toBe('FORBIDDEN');
});

it('Oberfläche: Admin bucht über die Komponente, Nicht-Admin sieht den Hinweis', function () {
    Livewire::test(Grundeinstellungen::class)
        ->call('vorschau', $this->rootTeam->id)
        ->call('buchen', $this->rootTeam->id)
        ->assertSet('fehler', null)
        ->assertSee('gebucht');
    expect(DB::table('foodalchemist_team_pakete')->where('team_id', $this->rootTeam->id)->count())->toBe(1);

    $this->actingAs($this->makeUser($this->rootTeam, 'Normal'));
    Livewire::test(Grundeinstellungen::class)->assertSee('nur Plattform-Admins');
});
