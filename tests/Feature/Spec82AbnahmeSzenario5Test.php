<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\KontextResolver;
use Platform\FoodAlchemist\Services\MachbarkeitService;
use Platform\FoodAlchemist\Services\ProjektService;
use Platform\FoodAlchemist\Services\ProjektUebernahmeService;
use Platform\FoodAlchemist\Services\TeamProfilService;
use Platform\FoodAlchemist\Support\MachbarkeitKontext;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\Profiltraeger;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §11 Szenario 5 (Abnahme, neu gefasst nach Variante B): Soulkitchen-Projekt vor und nach der Lizenz — die ganze Kette in einem Lauf,
 * dazu Szenario 7 (KI plant für LAGO: Soulkitchen-Vorgaben, LAGO-Profil, Machbarkeits-Warnung) und Szenario 6 (Kundeninhalte bleiben im Mandanten).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
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
    $this->soulkitchen = Team::create(['name' => 'Soulkitchen', 'user_id' => $this->admin->id, 'personal_team' => false]);
    $this->firma = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->master->id, 'name' => 'Soulkitchen GmbH', 'is_active' => true]);
    $this->kontakt = \Platform\Crm\Models\CrmContact::create(['team_id' => $this->master->id, 'first_name' => 'Anna', 'last_name' => 'Soul', 'is_active' => true]);
    $this->kombi = FoodAlchemistVocabKochequipment::create(['team_id' => null, 'slug' => 'kombi', 'name' => 'Kombidämpfer', 'sort_order' => 10]);
    $this->projekte = app(ProjektService::class);
    $this->canvas = app(CanvasService::class);
    $this->profil = app(TeamProfilService::class);
});

it('Szenario 5: vor der Lizenz alles in der Akte beim Master, ohne Mandant; nach der Lizenz Unter-Team LAGO mit Teamprofil, Kunden-DNA beim Oberteam, CRM-Firma verknüpft', function () {
    // ── vor der Lizenz: Akte im Master ──────────────────────────────────────────
    $p = $this->projekte->anlegen($this->master, $this->kurator, $this->firma->id, 'Soulkitchen LAGO', 'Ein Haus am See');
    $lago = $this->projekte->standortAnlegen($this->master, $this->kurator, $p->id, 'LAGO');
    $traeger = Profiltraeger::standort($this->master, $lago->id);
    $this->profil->setzeProfil($traeger, $this->kurator, ['max_pax_parallel' => 80]);
    $this->profil->setzeEquipment($traeger, $this->kurator, $this->kombi->id, 'nicht_vorhanden');
    $kunde = $this->canvas->canvasFor($this->master, 'kunde_dna', 'crm_company', $this->firma->id);
    $this->canvas->setSkalar($kunde, 'marke_positionierung', 'Haus am See');
    $this->canvas->setSkalar($kunde, 'erwartungen_nogos', 'Kein Fisch aus Zucht', true);
    $akte = $this->canvas->canvasFor($this->master, 'projekt', 'projekt', $p->id);
    $this->canvas->setSkalar($akte, 'zielbild_wortlaut', 'Wie ein Sommerabend am See.');
    $this->canvas->addEntry($akte, 'paket_stufen', 'Premium', ['preis' => '95']);
    $this->canvas->addEntry($akte, 'paket_anlaesse', 'Hochzeit');
    $this->canvas->setSkalar($akte, 'musterseite', 'Eine Seite je Paket.');

    expect(FoodAlchemistMandant::where('team_id', $this->soulkitchen->id)->exists())->toBeFalse()   // noch kein Mandant
        ->and(Team::where('parent_team_id', $this->soulkitchen->id)->count())->toBe(0);
    expect(fn () => $this->projekte->foodbookAnlegen($this->master, $this->kurator, $p->id))->toThrow(\RuntimeException::class, 'Gate');   // hartes Gate

    $this->projekte->strukturFreigeben($this->master, $this->kurator, $p->id, $this->kontakt->id);
    $fb = $this->projekte->foodbookAnlegen($this->master, $this->kurator, $p->id);
    expect($fb->team_id)->toBe((int) $this->master->id);

    // ── Lizenz erteilt ──────────────────────────────────────────────────────────
    app(ProjektUebernahmeService::class)->uebernehmen($this->master, $this->admin, $p->id, $this->soulkitchen);

    $lagoTeam = Team::where('parent_team_id', $this->soulkitchen->id)->where('name', 'LAGO')->first();
    expect($lagoTeam)->not->toBeNull()
        ->and($this->profil->lies($lagoTeam, $lagoTeam)['profil']['max_pax_parallel'])->toBe(80)
        ->and($this->canvas->find('food_dna', 'team', $this->soulkitchen->id)->entries->firstWhere('field_key', 'no_gos')->is_binding)->toBeTrue();
    $m = FoodAlchemistMandant::where('team_id', $this->soulkitchen->id)->first();
    expect($m->status)->toBe('aktiv')->and($m->modus)->toBe('kuratiert')->and((int) $m->crm_company_id)->toBe((int) $this->firma->id);
    expect($p->fresh()->mandant_team_id)->toBe((int) $this->soulkitchen->id)->and((int) $p->fresh()->crm_company_id)->toBe((int) $this->firma->id);

    // Akte und Foodbook bleiben im Master (Szenario 6: nichts davon liegt im Mandanten)
    expect(FoodAlchemistFoodbook::find($fb->id)->team_id)->toBe((int) $this->master->id)
        ->and($this->canvas->find('projekt', 'projekt', $p->id)->team_id)->toBe((int) $this->master->id);
});

it('Szenario 7: KI plant für LAGO — Soulkitchen-Vorgaben, LAGO-Profil im Kontext, Machbarkeits-Check warnt bei fehlendem Equipment', function () {
    $p = $this->projekte->anlegen($this->master, $this->kurator, $this->firma->id, 'Soulkitchen LAGO');
    $lago = $this->projekte->standortAnlegen($this->master, $this->kurator, $p->id, 'LAGO');
    $this->profil->setzeProfil(Profiltraeger::standort($this->master, $lago->id), $this->kurator, ['max_pax_parallel' => 80]);
    $this->profil->setzeEquipment(Profiltraeger::standort($this->master, $lago->id), $this->kurator, $this->kombi->id, 'nicht_vorhanden');
    $this->canvas->setSkalar($this->canvas->canvasFor($this->master, 'kunde_dna', 'crm_company', $this->firma->id), 'erwartungen_nogos', 'Kein Fisch aus Zucht', true);
    app(ProjektUebernahmeService::class)->uebernehmen($this->master, $this->admin, $p->id, $this->soulkitchen);
    $lagoTeam = Team::where('parent_team_id', $this->soulkitchen->id)->where('name', 'LAGO')->first();

    // ein Rezept im Oberteam (alle Unter-Teams sehen es), das einen Kombidämpfer braucht
    $rooftop = $this->makeRecipe($this->soulkitchen, 'Rooftop-Buffet Lamm');
    DB::table('foodalchemist_recipe_equipment')->insert(['recipe_id' => $rooftop->id, 'equipment_id' => $this->kombi->id, 'created_at' => now(), 'updated_at' => now()]);

    $kontext = new MachbarkeitKontext($lagoTeam, $this->makeUser($lagoTeam, 'Koch', 'member'), 200);
    $check = app(MachbarkeitService::class)->pruefe($kontext, [$rooftop->id])[0];
    expect($check['status'])->toBe('warnung')->and($check['warnungen'][0]['equipment'])->toBe('Kombidämpfer');

    $r = app(KontextResolver::class)->fuer($lagoTeam, [], $kontext);
    expect($r['marken_kontext'])->toContain('Verbindliche Vorgaben des Oberteams')->and($r['marken_kontext'])->toContain('Kein Fisch aus Zucht')   // Soulkitchen-Vorgabe
        ->and($r['marken_kontext'])->toContain('Nicht vorhanden: Kombidämpfer')->and($r['marken_kontext'])->toContain('Max. Gäste parallel zum Tagesgeschäft: 80')   // LAGO-Profil
        ->and($r['marken_kontext'])->toContain('übersteigt die ausdrücklich gepflegte Kapazität');   // 200 Gäste > 80
});
