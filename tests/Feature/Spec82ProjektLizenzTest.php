<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Projekte\Detail;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamProfile;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamProfileEquipment;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\ProjektService;
use Platform\FoodAlchemist\Services\ProjektUebernahmeService;
use Platform\FoodAlchemist\Services\TeamProfilService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\Profiltraeger;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\ProjekteUebernahmeGetTool;
use Platform\FoodAlchemist\Tools\ProjekteUebernahmePostTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §9 · „Lizenz erteilt“: Übernahme der Agentur-Akte in den Mandanten (Unter-Teams, Profile, Kunden-DNA, Mandanten-Zeile).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    // Test-Stub hat keine modulables-Migration (wie TeamOnboardingTest) — minimal nachziehen
    if (! \Illuminate\Support\Facades\Schema::hasTable('modulables')) {
        \Illuminate\Support\Facades\Schema::create('modulables', function ($table) {
            $table->id();
            $table->unsignedBigInteger('module_id');
            $table->string('modulable_type');
            $table->unsignedBigInteger('modulable_id');
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('team_id')->nullable();
            $table->timestamps();
        });
    }
    DB::table('modules')->insertOrIgnore(['key' => 'foodalchemist', 'title' => 'FoodAlchemist', 'created_at' => now(), 'updated_at' => now()]);
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform-Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->kunde = Team::create(['name' => 'Soulkitchen', 'user_id' => $this->admin->id, 'personal_team' => false]);   // vorhandenes Host-Team = Oberteam
    $this->firma = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->master->id, 'name' => 'Soulkitchen GmbH', 'is_active' => true]);
    $this->svc = app(ProjektService::class);
    $this->projekt = $this->svc->anlegen($this->master, $this->kurator, $this->firma->id, 'Soulkitchen LAGO');
    $this->lago = $this->svc->standortAnlegen($this->master, $this->kurator, $this->projekt->id, 'LAGO');
    $this->see = $this->svc->standortAnlegen($this->master, $this->kurator, $this->projekt->id, 'Seeterrasse');
    $this->kombi = FoodAlchemistVocabKochequipment::create(['team_id' => null, 'slug' => 'kombi', 'name' => 'Kombidämpfer', 'sort_order' => 10]);
    $prof = app(TeamProfilService::class);
    $prof->setzeProfil(Profiltraeger::standort($this->master, $this->lago->id), $this->kurator, ['max_pax_parallel' => 80, 'notes' => 'Anlieferung über den Hof']);
    $prof->setzeEquipment(Profiltraeger::standort($this->master, $this->lago->id), $this->kurator, $this->kombi->id, 'nicht_vorhanden');
    $prof->setzeFlaeche(Profiltraeger::standort($this->master, $this->lago->id), $this->kurator, null, 'Saal', 120);
    $this->canvas = app(CanvasService::class);
    $k = $this->canvas->canvasFor($this->master, 'kunde_dna', 'crm_company', $this->firma->id);
    $this->canvas->setSkalar($k, 'marke_positionierung', 'Haus am See', null);
    $this->canvas->setSkalar($k, 'erwartungen_nogos', 'Kein Fisch aus Zucht', true);   // Bleibt
    $this->canvas->setSkalar($k, 'kommunikation_ton', 'Du, locker', false);
    $this->uebernahme = app(ProjektUebernahmeService::class);
});

it('Übernahme: Unter-Teams je Standort (Pilot zuerst), Profile als Teamprofile, Kunden-DNA als Team-DNA mit Bleibt = verbindlich, Mandant aktiv + kuratiert', function () {
    $b = $this->uebernahme->uebernehmen($this->master, $this->admin, $this->projekt->id, $this->kunde);

    expect(array_column($b['unter_teams'], 'name'))->toBe(['LAGO', 'Seeterrasse'])->and($b['unter_teams'][0]['pilot'])->toBeTrue();
    $lagoTeam = Team::where('parent_team_id', $this->kunde->id)->where('name', 'LAGO')->first();
    expect($lagoTeam)->not->toBeNull()->and(DB::table('modulables')->where('modulable_id', $lagoTeam->id)->where('enabled', 1)->exists())->toBeTrue();

    $p = app(TeamProfilService::class)->lies($lagoTeam, $lagoTeam);
    expect($p['standort_id'])->toBe(0)->and($p['profil']['max_pax_parallel'])->toBe(80)->and($p['profil']['notes'])->toBe('Anlieferung über den Hof')
        ->and($p['flaechen'])->toHaveCount(1)->and(collect($p['ausstattung'])->firstWhere('equipment_id', $this->kombi->id)['status'])->toBe('nicht_vorhanden');
    $see = Team::where('parent_team_id', $this->kunde->id)->where('name', 'Seeterrasse')->first();
    expect(app(TeamProfilService::class)->lies($see, $see)['profil']['max_pax_parallel'])->toBeNull();   // leeres Standortprofil bleibt leer

    $dna = $this->canvas->find('food_dna', 'team', $this->kunde->id)->entries->keyBy('field_key');
    expect($dna['leitbild']->value)->toBe('Haus am See')->and($dna['leitbild']->is_binding)->toBeFalse()
        ->and($dna['no_gos']->value)->toBe('Kein Fisch aus Zucht')->and($dna['no_gos']->is_binding)->toBeTrue()
        ->and($dna['signature_stil']->value)->toBe('Du, locker');

    $m = FoodAlchemistMandant::where('team_id', $this->kunde->id)->first();
    expect($m->status)->toBe('aktiv')->and($m->modus)->toBe('kuratiert')->and((int) $m->crm_company_id)->toBe((int) $this->firma->id);
    $pr = $this->projekt->fresh();
    expect($pr->mandant_team_id)->toBe((int) $this->kunde->id)->and($pr->phase)->toBe('uebergabe');
});

it('Kopie, nicht live: spätere Änderung am Standortprofil oder an der Kunden-DNA berührt den Mandanten nicht', function () {
    $this->uebernahme->uebernehmen($this->master, $this->admin, $this->projekt->id, $this->kunde);
    $lagoTeam = Team::where('parent_team_id', $this->kunde->id)->where('name', 'LAGO')->first();

    app(TeamProfilService::class)->setzeProfil(Profiltraeger::standort($this->master, $this->lago->id), $this->kurator, ['max_pax_parallel' => 5]);
    $this->canvas->setSkalar($this->canvas->canvasFor($this->master, 'kunde_dna', 'crm_company', $this->firma->id), 'marke_positionierung', 'GEÄNDERT');

    expect(app(TeamProfilService::class)->lies($lagoTeam, $lagoTeam)['profil']['max_pax_parallel'])->toBe(80)
        ->and($this->canvas->find('food_dna', 'team', $this->kunde->id)->entries->firstWhere('field_key', 'leitbild')->value)->toBe('Haus am See');
});

it('Bereits gefüllte Felder des Oberteams bleiben unberührt und werden gemeldet', function () {
    $this->canvas->setSkalar($this->canvas->canvasFor($this->kunde, 'food_dna', 'team', $this->kunde->id), 'leitbild', 'EIGENES LEITBILD');

    $v = $this->uebernahme->vorschau($this->master, $this->admin, $this->projekt->id, $this->kunde);
    expect($v['dna_uebersprungen'])->toBe(['leitbild'])->and($v['dna_felder'])->not->toContain('leitbild');

    $this->uebernahme->uebernehmen($this->master, $this->admin, $this->projekt->id, $this->kunde);
    expect($this->canvas->find('food_dna', 'team', $this->kunde->id)->entries->firstWhere('field_key', 'leitbild')->value)->toBe('EIGENES LEITBILD');
});

it('Rechte und Hindernisse: nur Plattform-Admin, einmalig, kein Master, kein Unter-Team, kein Namens-Konflikt; nichts halb geschrieben', function () {
    expect(fn () => $this->uebernahme->uebernehmen($this->master, $this->kurator, $this->projekt->id, $this->kunde))->toThrow(FaRechtFehltException::class);
    expect(fn () => $this->uebernahme->vorschau($this->master, $this->kurator, $this->projekt->id, $this->kunde))->toThrow(FaRechtFehltException::class);
    expect(fn () => $this->uebernahme->uebernehmen($this->master, $this->admin, $this->projekt->id, $this->master))->toThrow(\RuntimeException::class, 'Master');
    expect(fn () => $this->uebernahme->uebernehmen($this->master, $this->admin, $this->projekt->id, $this->childA))->toThrow(\RuntimeException::class, 'Haupt-Team');

    Team::create(['name' => 'LAGO', 'user_id' => $this->admin->id, 'personal_team' => false, 'parent_team_id' => $this->kunde->id]);   // Namens-Konflikt
    expect(fn () => $this->uebernahme->uebernehmen($this->master, $this->admin, $this->projekt->id, $this->kunde))->toThrow(\RuntimeException::class, 'schon ein Unter-Team');
    expect(Team::where('parent_team_id', $this->kunde->id)->count())->toBe(1)->and(FoodAlchemistMandant::where('team_id', $this->kunde->id)->exists())->toBeFalse()
        ->and($this->canvas->find('food_dna', 'team', $this->kunde->id))->toBeNull();

    Team::where('parent_team_id', $this->kunde->id)->delete();
    $this->uebernahme->uebernehmen($this->master, $this->admin, $this->projekt->id, $this->kunde);
    expect(fn () => $this->uebernahme->uebernehmen($this->master, $this->admin, $this->projekt->id, $this->kunde))->toThrow(\RuntimeException::class, 'schon');
});

it('Ein Oberteam gehört nur zu einem Projekt', function () {
    $this->uebernahme->uebernehmen($this->master, $this->admin, $this->projekt->id, $this->kunde);
    $zweit = $this->svc->anlegen($this->master, $this->kurator, $this->firma->id, 'Zweite Akte');

    expect(fn () => $this->uebernahme->uebernehmen($this->master, $this->admin, $zweit->id, $this->kunde))->toThrow(\RuntimeException::class, 'anderen Projekt');
});

it('Seite: nur Plattform-Admin sieht die Übernahme, Vorschau und Ausführung, danach „Übernommen“', function () {
    $this->actingAs($this->kurator);
    Livewire::test(Detail::class, ['projekt' => $this->projekt->id])->call('phaseSetzen', 'uebergabe')->assertSeeHtml('data-projekt-lizenz-nur-admin')->assertDontSeeHtml('data-projekt-lizenz-suche');

    $this->actingAs($this->makeUser($this->master, 'PA', 'owner'));
    config(['platform-shell.admins' => [strtolower($this->admin->email), strtolower(auth()->user()->email)]]);
    Livewire::test(Detail::class, ['projekt' => $this->projekt->id])->call('phaseSetzen', 'uebergabe')->set('oberteamSuche', 'Soul')
        ->assertSeeHtml('data-projekt-lizenz-team="'.$this->kunde->id.'"')->call('oberteamWaehlen', $this->kunde->id)
        ->assertSeeHtml('data-projekt-lizenz-vorschau')->assertSee('LAGO, Seeterrasse')->assertSeeHtml('data-projekt-lizenz-uebernehmen')
        ->call('lizenzUebernehmen')->assertSeeHtml('data-projekt-lizenz-fertig');
    expect($this->projekt->fresh()->mandant_team_id)->toBe((int) $this->kunde->id);
});

it('MCP: UEBERNAHME_GET schreibt nichts, UEBERNAHME_POST übernimmt; Nicht-Admin abgewiesen', function () {
    $args = ['projekt_id' => $this->projekt->id, 'oberteam_id' => $this->kunde->id];
    $nein = app(ProjekteUebernahmePostTool::class)->execute($args, new ToolContext($this->kurator, $this->master));
    expect($nein->success)->toBeFalse();

    $v = app(ProjekteUebernahmeGetTool::class)->execute($args, new ToolContext($this->admin, $this->master));
    expect($v->success)->toBeTrue()->and($v->data['vorschau']['unter_teams'])->toBe(['LAGO', 'Seeterrasse'])->and(Team::where('parent_team_id', $this->kunde->id)->count())->toBe(0);

    $ok = app(ProjekteUebernahmePostTool::class)->execute($args, new ToolContext($this->admin, $this->master));
    expect($ok->success)->toBeTrue()->and($ok->data['bericht']['unter_teams'])->toHaveCount(2)->and(Team::where('parent_team_id', $this->kunde->id)->count())->toBe(2);
});

it('Kontingent Standorte (Spec 77b): würde die Übernahme es überschreiten, ist das ein Hindernis, es wird nichts angelegt', function () {
    app(\Platform\FoodAlchemist\Services\FaRechte::class)->setzeKontingente($this->kunde, $this->admin, ['max_standorte' => 1]);   // Projekt hat 2 Standorte

    $v = $this->uebernahme->vorschau($this->master, $this->admin, $this->projekt->id, $this->kunde);
    expect($v['kontingent'])->toBe(['max_standorte' => 1, 'genutzt' => 0, 'neu' => 2])
        ->and($v['hindernisse'])->toHaveCount(1)->and($v['hindernisse'][0])->toContain('Kontingent Standorte')->toContain('überschritten');
    expect(fn () => $this->uebernahme->uebernehmen($this->master, $this->admin, $this->projekt->id, $this->kunde))->toThrow(\RuntimeException::class, 'Kontingent');
    expect(Team::where('parent_team_id', $this->kunde->id)->count())->toBe(0)->and(FoodAlchemistMandant::where('team_id', $this->kunde->id)->exists())->toBeFalse()
        ->and($this->projekt->fresh()->mandant_team_id)->toBeNull();
});

it('Kontingent: bereits vorhandene Unter-Teams zählen mit; genau passend und unbegrenzt gehen durch', function () {
    $rechte = app(\Platform\FoodAlchemist\Services\FaRechte::class);
    Team::create(['name' => 'Altbestand', 'user_id' => $this->admin->id, 'personal_team' => false, 'parent_team_id' => $this->kunde->id]);

    $rechte->setzeKontingente($this->kunde, $this->admin, ['max_standorte' => 2]);   // 1 vorhanden + 2 neue = 3 > 2
    expect($this->uebernahme->vorschau($this->master, $this->admin, $this->projekt->id, $this->kunde)['hindernisse'][0])->toContain('1 vorhanden');

    $rechte->setzeKontingente($this->kunde, $this->admin, ['max_standorte' => 3]);   // genau passend
    expect($this->uebernahme->vorschau($this->master, $this->admin, $this->projekt->id, $this->kunde)['hindernisse'])->toBe([]);

    $rechte->setzeKontingente($this->kunde, $this->admin, ['max_standorte' => null]); // unbegrenzt
    $b = $this->uebernahme->uebernehmen($this->master, $this->admin, $this->projekt->id, $this->kunde);
    expect($b['unter_teams'])->toHaveCount(2)->and(Team::where('parent_team_id', $this->kunde->id)->count())->toBe(3);
});
