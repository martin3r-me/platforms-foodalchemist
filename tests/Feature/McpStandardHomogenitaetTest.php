<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;
use Platform\FoodAlchemist\Services\EquipmentStandardService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\EquipmentStandardPutTool;
use Platform\FoodAlchemist\Tools\GpsHomogenitaetGetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82: MCP-Tools für die Standardausstattung (nur Plattform-Admin, Trockenlauf als Standard) und den Homogenitäts-Bericht (lesend, Kuratieren, nur Sichtbares).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->inhaber = $this->makeUser($this->rootTeam, 'Inhaber', 'owner');
    $this->viewer = $this->makeUser($this->rootTeam, 'Gast', 'viewer');
    $this->ctx = fn ($user, $team = null) => new ToolContext($user, $team ?? $this->rootTeam);
});

// ── Standardausstattung ──────────────────────────────────────────────────────

it('equipment_standard.PUT: Trockenlauf ist Standard und schreibt nichts; dry_run=false schreibt und ist idempotent', function () {
    $topf = FoodAlchemistVocabKochequipment::create(['team_id' => null, 'slug' => 'topf', 'name' => 'Topf']);
    $kombi = FoodAlchemistVocabKochequipment::create(['team_id' => null, 'slug' => 'kombi', 'name' => 'Kombidämpfer', 'is_standard' => true]);   // Altlast

    $r = (new EquipmentStandardPutTool)->execute([], ($this->ctx)($this->admin));
    expect($r->success)->toBeTrue()->and($r->data['trockenlauf'])->toBeTrue()->and(array_column($r->data['neu'], 'name'))->toBe(['Topf'])
        ->and(array_column($r->data['zurueck'], 'name'))->toBe(['Kombidämpfer'])->and(count($r->data['nicht_gefunden']))->toBe(14);
    expect($topf->fresh()->is_standard)->toBeFalse()->and($kombi->fresh()->is_standard)->toBeTrue();     // nichts geschrieben

    $w = (new EquipmentStandardPutTool)->execute(['dry_run' => false], ($this->ctx)($this->admin));
    expect($w->success)->toBeTrue()->and($w->data['hinweis'])->toContain('protokolliert');
    expect($topf->fresh()->is_standard)->toBeTrue()->and($kombi->fresh()->is_standard)->toBeFalse();

    $nochmal = (new EquipmentStandardPutTool)->execute(['dry_run' => false], ($this->ctx)($this->admin));
    expect($nochmal->data['neu'])->toBe([])->and($nochmal->data['zurueck'])->toBe([])->and(array_column($nochmal->data['schon'], 'name'))->toBe(['Topf']);
});

it('equipment_standard.PUT: nur Plattform-Admin, auch der Trockenlauf; Inhaber, Betrachter und KI bekommen FORBIDDEN', function () {
    $topf = FoodAlchemistVocabKochequipment::create(['team_id' => null, 'slug' => 'topf', 'name' => 'Topf']);

    foreach ([$this->inhaber, $this->viewer] as $user) {
        expect((new EquipmentStandardPutTool)->execute([], ($this->ctx)($user))->errorCode)->toBe('FORBIDDEN');
        expect((new EquipmentStandardPutTool)->execute(['dry_run' => false], ($this->ctx)($user))->errorCode)->toBe('FORBIDDEN');
    }
    expect($topf->fresh()->is_standard)->toBeFalse();
});

it('Der Dienst schreibt über die Modelle (damit das activity-log die Änderung sieht) und einen Klartext-Eintrag', function () {
    if (! method_exists(FoodAlchemistVocabKochequipment::class, 'logActivity')) {
        $this->markTestSkipped('Echtes platform-activity-log nicht installiert (Sandbox-Stub ohne logActivity).');
    }
    if (! \Illuminate\Support\Facades\Schema::hasTable('activity_log_activities')) {
        $this->artisan('migrate', ['--realpath' => true, '--path' => [base_path('vendor/martin3r/platform-activity-log/database/migrations')]]);
    }
    $topf = FoodAlchemistVocabKochequipment::create(['team_id' => null, 'slug' => 'topf', 'name' => 'Topf']);

    app(EquipmentStandardService::class)->schreiben($this->admin);

    $zeile = DB::table('activity_log_activities')->where('activityable_type', FoodAlchemistVocabKochequipment::class)->where('message', 'equipment_standard_gesetzt')->first();
    expect($zeile)->not->toBeNull()->and(json_decode($zeile->metadata, true)['neu'])->toBe(['Topf']);
});

// ── Homogenitäts-Bericht ─────────────────────────────────────────────────────

/** Ein GP mit zwei Artikeln, die sich beim Allergen Milch widersprechen. */
function uneinheitlichesGp(object $test, ?Team $besitzer, string $name, string $klasse = 'basis'): FoodAlchemistGp
{
    $gp = FoodAlchemistGp::create(['team_id' => $besitzer?->id, 'gp_key' => 'hom|' . $name, 'name' => $name, 'stamm_class' => $klasse]);
    $sup = FoodAlchemistSupplier::create(['team_id' => $besitzer?->id, 'name' => 'Lief ' . $name, 'stamm_class' => $klasse]);
    foreach (['enthalten', 'nicht_enthalten'] as $i => $milch) {
        $la = FoodAlchemistSupplierItem::create(['team_id' => $besitzer?->id, 'supplier_id' => $sup->id, 'designation' => "{$name} {$i}", 'stamm_class' => $klasse]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $besitzer?->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistItemAllergen::create(['team_id' => $besitzer?->id, 'supplier_item_id' => $la->id, 'allergen_milk' => $milch]);
    }

    return $gp;
}

it('gps_homogenitaet.GET: Zusammenfassung und Liste; limit begrenzt; 0 = nur die Zusammenfassung', function () {
    uneinheitlichesGp($this, $this->rootTeam, 'Sahne');
    uneinheitlichesGp($this, $this->rootTeam, 'Butter');

    $r = (new GpsHomogenitaetGetTool)->execute([], ($this->ctx)($this->inhaber));
    expect($r->success)->toBeTrue()->and($r->data['zusammenfassung']['gps_uneinheitlich'])->toBe(2)->and($r->data['gesamt'])->toBe(2)->and($r->data['gps'])->toHaveCount(2)
        ->and($r->data['gps'][0]['abweichungen'])->toContain('Allergen „Milch“');

    $eins = (new GpsHomogenitaetGetTool)->execute(['limit' => 1], ($this->ctx)($this->inhaber));
    expect($eins->data['gps'])->toHaveCount(1)->and($eins->data['gesamt'])->toBe(2);
    expect((new GpsHomogenitaetGetTool)->execute(['limit' => 0], ($this->ctx)($this->inhaber))->data['gps'])->toBe([]);
});

it('gps_homogenitaet.GET: nur Kuratieren; der Betrachter bekommt FORBIDDEN', function () {
    expect((new GpsHomogenitaetGetTool)->execute([], ($this->ctx)($this->viewer))->errorCode)->toBe('FORBIDDEN');
});

it('gps_homogenitaet.GET zeigt NUR Sichtbares: kein fremdes Team, stamm_paket erst mit Opt-in; die Konsole (global) sieht alles', function () {
    $fremd = Team::create(['name' => 'Fremd GmbH', 'user_id' => 1, 'personal_team' => false]);
    uneinheitlichesGp($this, $this->rootTeam, 'Eigenes GP');
    uneinheitlichesGp($this, $fremd, 'Fremdes GP');
    uneinheitlichesGp($this, null, 'Paket GP', 'stamm_paket');
    uneinheitlichesGp($this, null, 'Basis GP');
    $namen = fn () => collect((new GpsHomogenitaetGetTool)->execute([], ($this->ctx)($this->inhaber))->data['gps'])->pluck('name')->sort()->values()->all();

    expect($namen())->toBe(['Basis GP', 'Eigenes GP']);                       // kein Fremdes GP, kein Paket ohne Opt-in

    StammPaket::freischalten($this->rootTeam);
    expect($namen())->toBe(['Basis GP', 'Eigenes GP', 'Paket GP']);

    $global = collect(app(\Platform\FoodAlchemist\Services\GpHomogenitaetService::class)->bericht()['gps'])->pluck('name')->sort()->values()->all();
    expect($global)->toBe(['Basis GP', 'Eigenes GP', 'Fremdes GP', 'Paket GP']);   // Befehl/Konsole bleibt global
});
