<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GP-Regelwerk 830 Teil 2 (Georg/Dominique 14.11.2026): ganze WG 11 ohne Zustandswort (auch Schmalz, Bratfett, Frittierfett), „Würzessig“ (WG 10)
 * behält den Zustand; verwaltete Untergruppe „11.4 Schmalz & feste Fette“ (Migration idempotent).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->n = app(GpNamingService::class);
});

it('WG 11: Schmalz, Bratfett, Frittierfett ohne Zustandswort im Namen', function () {
    foreach (['Schweineschmalz', 'Bratfett', 'Frittierfett', 'Rapsöl'] as $hz) {
        expect($this->n->renderGpName(['hauptzutat' => $hz, 'condition' => 'frisch', 'commodity_group_code' => '11']))->toBe($hz);
    }
    expect($this->n->ohneZustandswort(['hauptzutat' => 'Gänseschmalz']))->toBeTrue()                       // ohne WG: Rückfall über die Hauptzutat
        ->and($this->n->ohneZustandswort(['hauptzutat' => 'Griebenschmalz', 'commodity_group_code' => '04']))->toBeFalse();   // andere WG: nur WG 11
});

it('Würzessig (WG 10) behält den Zustand, Essig ohne Warengruppe weiter ohne', function () {
    expect($this->n->renderGpName(['hauptzutat' => 'Würzessig', 'condition' => 'konserviert', 'commodity_group_code' => '10']))->toBe('Würzessig: konserviert')
        ->and($this->n->ohneZustandswort(['hauptzutat' => 'Wuerzessig']))->toBeFalse()
        ->and($this->n->ohneZustandswort(['hauptzutat' => 'Balsamicoessig', 'commodity_group_code' => '10']))->toBeTrue();
});

it('Migration legt „11.4 Schmalz & feste Fette“ global an, reaktiviert einen gelöschten Eintrag, 2× up gleich', function () {
    $t = 'foodalchemist_commodity_group_subcategories';
    $m = require dirname(__DIR__, 2) . '/database/migrations/2026_12_29_100000_subkategorie_schmalz_feste_fette.php';
    DB::table($t)->where('name', '11.4 Schmalz & feste Fette')->delete();

    $m->up();
    $m->up();
    $zeilen = DB::table($t)->where('commodity_group_code', '11')->where('name', '11.4 Schmalz & feste Fette')->get();
    expect($zeilen)->toHaveCount(1)->and($zeilen[0]->team_id)->toBeNull()->and($zeilen[0]->deleted_at)->toBeNull();

    DB::table($t)->where('id', $zeilen[0]->id)->update(['deleted_at' => now()]);
    $m->up();
    expect(DB::table($t)->where('id', $zeilen[0]->id)->value('deleted_at'))->toBeNull()
        ->and(DB::table($t)->where('name', '11.4 Schmalz & feste Fette')->count())->toBe(1);
});
