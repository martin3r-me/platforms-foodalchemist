<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAbo;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 C4: Abo vergeben und Fassung veröffentlichen landen WIRKLICH im activity-log.
 *
 * Bewusst OHNE Mock (Vorlage PlattformProtokollTest, #308): das echte Trait (`logActivity`) gegen die echte Tabelle
 * `activity_log_activities`. In einer Sandbox mit dem Stub-Paket (ohne logActivity) wird übersprungen.
 */
beforeEach(function () {
    if (! method_exists(FoodAlchemistAbo::class, 'logActivity')) {
        $this->markTestSkipped('Echtes platform-activity-log nicht installiert (Sandbox-Stub ohne logActivity).');
    }
    $this->seedTeamHierarchy();
    if (! Schema::hasTable('activity_log_activities')) {
        $this->artisan('migrate', ['--realpath' => true, '--path' => [base_path('vendor/martin3r/platform-activity-log/database/migrations')]]);
    }
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($this->broich);
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');

    $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'prot|knochen', 'name' => 'Kalbsknochen']);
    $this->jus = $this->makeRecipe($this->master, 'Kalbsjus');
    $this->makeIngredient($this->jus, 'Kalbsknochen', $gp, '1000');
    $inhalte = app(InhaltsFreigabeService::class);
    $this->sammlung = $inhalte->sammlungAnlegen($this->master, 'Basis', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $this->sammlung, 'recipe', [$this->jus->id], $this->kurator);
    $this->zeilen = fn (string $modell) => DB::table('activity_log_activities')->where('activityable_type', $modell)->orderBy('id')->get();
});

it('Abo vergeben schreibt einen Klartext-Eintrag mit Akteur am Abo', function () {
    $abo = app(AboService::class)->abonnieren($this->master, $this->broich, $this->sammlung, $this->kurator);

    $eintrag = ($this->zeilen)(FoodAlchemistAbo::class)->firstWhere('message', 'Abo vergeben');
    expect($eintrag)->not->toBeNull()->and((int) $eintrag->activityable_id)->toBe($abo->id);
    expect(json_decode($eintrag->metadata, true))->toMatchArray([
        'aktion' => 'abo_vergeben', 'mandant_team_id' => $this->broich->id, 'sammlung_id' => $this->sammlung, 'akteur_id' => $this->kurator->id,
    ]);
});

it('Veröffentlichen schreibt einen Eintrag an der neuen Fassung; ohne Änderung kein zweiter', function () {
    $svc = app(FassungService::class);
    $r = $svc->veroeffentlichen($this->master, $this->jus->id, 'Erst', $this->kurator);

    $eintraege = ($this->zeilen)(FoodAlchemistRecipe::class)->where('message', 'Fassung veröffentlicht')->values();
    expect($eintraege)->toHaveCount(1)->and((int) $eintraege[0]->activityable_id)->toBe($r['fassung_id']);
    expect(json_decode($eintraege[0]->metadata, true))->toMatchArray(['aktion' => 'fassung_veroeffentlicht', 'notiz' => 'Erst', 'akteur_id' => $this->kurator->id]);

    $svc->veroeffentlichen($this->master, $this->jus->id, 'unverändert', $this->kurator);
    expect(($this->zeilen)(FoodAlchemistRecipe::class)->where('message', 'Fassung veröffentlicht'))->toHaveCount(1);
});
