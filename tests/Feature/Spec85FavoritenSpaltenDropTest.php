<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamFavorit;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 FAV-3: `is_favorite`/`favorite_rank` am GP sind weg (Favoriten nur noch im Team-Overlay). Der Weg alt → neu als Ganzes:
 * Spalten zurückholen (down), Spalten-Favorit setzen, Drop ohne Übernahme bricht ab; nach der Übernahme (200100) fällt die Spalte,
 * der Favorit steht beim Master.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->drop = require __DIR__ . '/../../database/migrations/2026_11_12_300000_drop_favoriten_spalten_von_gps.php';
    $this->uebernahme = require __DIR__ . '/../../database/migrations/2026_11_12_200100_favoriten_aus_gp_spalten_ins_team_overlay.php';
});

it('nach dem Migrieren hat der GP keine Favoriten-Spalten mehr', function () {
    expect(Schema::hasColumn('foodalchemist_gps', 'is_favorite'))->toBeFalse()
        ->and(Schema::hasColumn('foodalchemist_gps', 'favorite_rank'))->toBeFalse();
});

it('Riegel: ein Spalten-Favorit ohne Overlay-Zeile stoppt den Drop; nach der Übernahme fällt die Spalte, der Favorit bleibt', function () {
    $this->drop->down();
    $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'f85|butter', 'name' => 'Butter', 'status' => 'approved']);
    DB::table('foodalchemist_gps')->where('id', $gp->id)->update(['is_favorite' => true, 'favorite_rank' => 2]);

    expect(fn () => $this->drop->up())->toThrow(RuntimeException::class, '1 Favoriten stehen nur in der GP-Spalte');
    expect(Schema::hasColumn('foodalchemist_gps', 'is_favorite'))->toBeTrue();

    $this->uebernahme->up();
    $this->drop->up();
    $this->drop->up();   // idempotent

    expect(Schema::hasColumn('foodalchemist_gps', 'is_favorite'))->toBeFalse()
        ->and(Schema::hasColumn('foodalchemist_gps', 'favorite_rank'))->toBeFalse()
        ->and((int) FoodAlchemistTeamFavorit::where('gp_id', $gp->id)->value('team_id'))->toBe((int) $this->master->id)
        ->and((int) FoodAlchemistTeamFavorit::where('gp_id', $gp->id)->value('rang'))->toBe(2);
    $this->uebernahme->up();   // ohne Spalten: No-op, kein Fehler
    expect(FoodAlchemistTeamFavorit::count())->toBe(1);
});
