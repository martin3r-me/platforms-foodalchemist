<?php

use Illuminate\Support\Facades\File;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * foodalchemist:gp-bereinigen --global (Kurator 23.10.2026): Namens-Phase auch auf GLOBALEN Stamm-GPs (team_id NULL, stamm_class stamm_paket),
 * nur als Master-Team. Anlass: Weckglas-Umbenennung auf io wurde mit „Team/Status passt nicht (Team , approved)“ abgewiesen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    MasterTeam::flush();
    $this->dir = sys_get_temp_dir() . '/gpg_' . uniqid();
    File::ensureDirectoryExists($this->dir);
    $this->backup = $this->dir . '/b.sql';
    file_put_contents($this->backup, str_repeat('x', 2048));

    $this->gp = function (string $name, ?int $team, string $klasse): FoodAlchemistGp {
        $svc = app(GpNamingService::class);
        $gp = FoodAlchemistGp::create(['team_id' => $team, 'name' => $name, 'gp_key' => $svc->slugify($name), 'status' => 'approved', 'commodity_group_code' => '13',
            'condition' => 'frisch', 'main_ingredient_slug' => 'cheesecake', 'main_ingredient_display' => 'Cheesecake']);
        \DB::table('foodalchemist_gps')->where('id', $gp->id)->update(['stamm_class' => $klasse]);

        return $gp->fresh();
    };
    $this->plan = function (FoodAlchemistGp $gp, string $neu): string {
        $pfad = $this->dir . '/plan.csv';
        file_put_contents($pfad, "gp_id;name;rule;proposal;key_neu;slug_neu\n{$gp->id};{$gp->name};Test;{$neu};" . app(GpNamingService::class)->slugify($neu) . ";\n");

        return $pfad;
    };
    $this->lauf = fn (array $opt) => $this->artisan('foodalchemist:gp-bereinigen', $opt + ['--bericht' => $this->dir, '--phase' => 'namen']);
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('ohne --global bleiben globale GPs ausser Reichweite (Teamfilter wie bisher)', function () {
    $gp = ($this->gp)('Cheesecake: frisch, Dessert', null, StammPaket::PAKET);

    ($this->lauf)(['--plan-namen' => [($this->plan)($gp, 'Cheesecake: frisch, Dessert, Weckglas, 50 g pro Stück')], '--apply' => true, '--backup-nachweis' => $this->backup])->assertSuccessful();

    expect($gp->fresh()->name)->toBe('Cheesecake: frisch, Dessert');
});

it('--global als Master benennt globale Stamm-GPs um, Name und Key an derselben id, Protokoll + Rückweg', function () {
    $gp = ($this->gp)('Cheesecake: frisch, Dessert', null, StammPaket::PAKET);
    $neu = 'Cheesecake: frisch, Dessert, Weckglas, 50 g pro Stück';

    ($this->lauf)(['--global' => true, '--plan-namen' => [($this->plan)($gp, $neu)], '--apply' => true, '--backup-nachweis' => $this->backup])->assertSuccessful();

    $g = $gp->fresh();
    expect($g->name)->toBe($neu)->and($g->gp_key)->toBe(app(GpNamingService::class)->slugify($neu))->and($g->team_id)->toBeNull();

    $protokoll = collect(File::files($this->dir))->first(fn ($f) => str_ends_with($f->getFilename(), '_protokoll.json'));
    $this->artisan('foodalchemist:gp-bereinigen', ['--zurueck' => $protokoll->getPathname()])->assertSuccessful();
    expect($gp->fresh()->name)->toBe('Cheesecake: frisch, Dessert');
});

it('--global fasst team-eigene GPs und globale GPs anderer Klasse nicht an', function () {
    $eigen = ($this->gp)('Cheesecake: frisch, Dessert', $this->rootTeam->id, 'basis');
    $andere = ($this->gp)('Cheesecake mini: frisch', null, 'basis');

    ($this->lauf)(['--global' => true, '--plan-namen' => [($this->plan)($eigen, 'Cheesecake: frisch, Dessert, 50 g pro Stück')], '--apply' => true, '--backup-nachweis' => $this->backup])->assertSuccessful();
    ($this->lauf)(['--global' => true, '--plan-namen' => [($this->plan)($andere, 'Cheesecake mini: frisch, 20 g pro Stück')], '--apply' => true, '--backup-nachweis' => $this->backup])->assertSuccessful();

    expect($eigen->fresh()->name)->toBe('Cheesecake: frisch, Dessert')->and($andere->fresh()->name)->toBe('Cheesecake mini: frisch');
});

it('Gegenprobe: Nicht-Master darf globale GPs NICHT umbenennen (--global mit fremdem --team bricht ab)', function () {
    $gp = ($this->gp)('Cheesecake: frisch, Dessert', null, StammPaket::PAKET);
    $fremd = $this->rootTeam->id + 999;

    ($this->lauf)(['--global' => true, '--team' => $fremd, '--plan-namen' => [($this->plan)($gp, 'Cheesecake: frisch, Dessert, 50 g pro Stück')], '--apply' => true, '--backup-nachweis' => $this->backup])
        ->assertFailed()->expectsOutputToContain('--global ist nur als Master-Team erlaubt');

    expect($gp->fresh()->name)->toBe('Cheesecake: frisch, Dessert');
});

it('--global ohne konfigurierten Master bricht ab; --global mit Phase dubletten ebenfalls', function () {
    $gp = ($this->gp)('Cheesecake: frisch, Dessert', null, StammPaket::PAKET);
    $plan = ($this->plan)($gp, 'Cheesecake: frisch, Dessert, 50 g pro Stück');

    ($this->lauf)(['--global' => true, '--team' => $this->rootTeam->id, '--phase' => 'dubletten'])->assertFailed()->expectsOutputToContain('nur für --phase=namen');

    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    ($this->lauf)(['--global' => true, '--team' => $this->rootTeam->id, '--plan-namen' => [$plan], '--apply' => true, '--backup-nachweis' => $this->backup])->assertFailed();
    expect($gp->fresh()->name)->toBe('Cheesecake: frisch, Dessert');
});
