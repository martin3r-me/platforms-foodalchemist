<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamSetting;
use Platform\FoodAlchemist\Services\PaketGrundeinstellungenService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket "Grundeinstellungen" + Spec 83 B2 (Erweiterung): bringt den Posten "Pass" mit und setzt ihn als Team-Vorgabe "Posten für Gerichte".
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
    $this->posten = fn (Team $t, string $slug = 'pass') => FoodAlchemistProductionStation::where('team_id', $t->id)->where('slug', $slug)->first();
});

it('★ Buchen legt den Posten „Pass" an und setzt ihn als Team-Vorgabe für Gerichte', function () {
    $r = $this->svc->buchen($this->rootTeam, $this->admin);

    $pass = ($this->posten)($this->rootTeam);
    expect($r['pass'])->toBe(['posten' => 'angelegt', 'vorgabe' => 'gesetzt'])
        ->and($pass)->not->toBeNull()->and($pass->name)->toBe('Pass')->and($pass->ist_sammelposten)->toBeTrue()   // Sammelposten ohne eigenes Personal
        ->and(app(TeamSettingsService::class)->gerichtePostenId($this->rootTeam))->toBe((int) $pass->id)
        ->and(app(TeamSettingsService::class)->gerichtePostenId($this->childA))->toBe((int) $pass->id);   // Unter-Team erbt
});

it('★ zweites Buchen ist idempotent: kein zweiter Pass, eine vorhandene Vorgabe wird nie überschrieben', function () {
    $this->svc->buchen($this->rootTeam, $this->admin);
    $eigener = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'slug' => 'ausgabe', 'name' => 'Ausgabe']);
    FoodAlchemistTeamSetting::where('team_id', $this->rootTeam->id)->update(['gerichte_station_id' => $eigener->id]);   // Team hat eine eigene Vorgabe

    $r = $this->svc->buchen($this->rootTeam, $this->admin);

    expect($r['pass'])->toBe(['posten' => 'vorhanden', 'vorgabe' => 'bereits gesetzt'])
        ->and(FoodAlchemistProductionStation::where('team_id', $this->rootTeam->id)->where('slug', 'pass')->count())->toBe(1)
        ->and((int) DB::table('foodalchemist_team_settings')->where('team_id', $this->rootTeam->id)->value('gerichte_station_id'))->toBe((int) $eigener->id);
});

it('kommt der Pass schon aus den Master-Vorlagen, gilt er als vorhanden (nicht doppelt) und wird zur Vorgabe', function () {
    DB::table('foodalchemist_production_stations')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->master->id, 'slug' => 'pass', 'name' => 'Pass (BHG)', 'sort_order' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $r = $this->svc->buchen($this->rootTeam, $this->admin);

    expect(FoodAlchemistProductionStation::where('team_id', $this->rootTeam->id)->where('slug', 'pass')->count())->toBe(1)
        ->and(($this->posten)($this->rootTeam)->name)->toBe('Pass (BHG)')                    // die kopierte Vorlage
        ->and($r['pass']['posten'])->toBe('vorhanden')->and($r['pass']['vorgabe'])->toBe('gesetzt');
});
