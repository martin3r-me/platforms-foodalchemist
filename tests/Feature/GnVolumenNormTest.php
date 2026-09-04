<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GN-Nennvolumen auf EINE Quelle (EuroNorm 631-1) statt eines Mix aus Händlerseiten.
 *
 * ★ Der Grund ist nicht Ordnungsliebe: ein zu hohes Nennvolumen schlägt systematisch ZU WENIGE
 * Behälter vor, und das merkt niemand, bis die Ware nicht hineinpasst.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->gn = function (string $format, int $tiefe, ?float $liter) {
        $name = "GN {$format} {$tiefe}mm";

        return DB::table('foodalchemist_vocab_containers')->insertGetId([
            'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id,
            'slug' => Str::slug($name, '_'), 'name' => $name, 'sort_order' => 10,
            'familie' => 'GN', 'format_code' => $format, 'tiefe_mm' => $tiefe, 'volumen_l' => $liter,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->migrieren = fn () => (require dirname(__DIR__, 2)
        .'/database/migrations/2026_09_05_000001_gn_volumen_nach_euronorm_631.php')->up();
});

it('korrigiert zu hohe Nennvolumen und füllt die Lücken der Norm', function () {
    $zuHoch = ($this->gn)('1/1', 40, 5.5);       // Mix-Wert aus dem ersten Seed
    $ohneWert = ($this->gn)('1/2', 20, null);    // stand auf NULL → war „nicht bemessbar"

    ($this->migrieren)();

    $liter = fn (int $id) => (float) DB::table('foodalchemist_vocab_containers')->where('id', $id)->value('volumen_l');

    expect($liter($zuHoch))->toBe(5.0)
        ->and($liter($ohneWert))->toBe(1.25);

    // Fehlende Normgroessen werden angelegt — mit Maßen, sonst waeren sie nicht skalierbar.
    $neu = DB::table('foodalchemist_vocab_containers')->where('team_id', $this->rootTeam->id)
        ->where('format_code', '1/3')->where('tiefe_mm', 20)->first();

    expect($neu)->not->toBeNull()
        ->and((float) $neu->volumen_l)->toBe(0.75)
        ->and((int) $neu->laenge_mm)->toBe(325)
        ->and((int) $neu->breite_mm)->toBe(176)
        ->and($neu->group_name)->toBe('GN');
});

it('legt keinem Team einen GN-Katalog an, das gar keinen führt', function () {
    // childA hat keine GN-Zeile — die Migration darf ihm keine unterschieben.
    ($this->gn)('1/1', 65, 9.0);   // nur rootTeam

    ($this->migrieren)();

    expect(DB::table('foodalchemist_vocab_containers')->where('team_id', $this->childA->id)->count())->toBe(0);
});

it('ist idempotent — ein zweiter Lauf legt nichts doppelt an', function () {
    ($this->gn)('1/1', 65, 8.8);

    ($this->migrieren)();
    $nachEins = DB::table('foodalchemist_vocab_containers')->where('team_id', $this->rootTeam->id)->count();

    ($this->migrieren)();
    $nachZwei = DB::table('foodalchemist_vocab_containers')->where('team_id', $this->rootTeam->id)->count();

    expect($nachZwei)->toBe($nachEins);
});
