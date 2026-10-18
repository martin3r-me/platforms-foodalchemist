<?php

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamSetting;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 B3 Stufe 4 · Vokabular und Einstellungen.
 * Vorbedingung Einschalten (Kurator 18.10.): die Kette endet an der Kundengrenze (kundenHauptTeam). Ein Mandant direkt unter dem
 * Master (laut FaRechte/AboSicht erlaubt; auf io heute nicht vorhanden) sieht nie die Einstellungen/Aufschlagsklassen des Masters.
 * Solange die Kappung fehlt, steht der Riegel als todo — er muss mit der Kappung grün werden.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    $this->mandant = Team::create(['name' => 'Mandant unter Master', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->master->id]);
    $this->bereich = Team::create(['name' => 'Bereich des Mandanten', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->mandant->id]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    TeamAncestryRegistry::flushAll();
    $ak = fn (Team $t, string $code) => FoodAlchemistMarkupClass::create(['team_id' => $t->id, 'code' => $code, 'label' => $code,
        'raw_markup_pct' => 300, 'vat_rate' => 19, 'formula_type' => 'aufschlag']);
    $this->akMaster = $ak($this->master, 'BHG');
    $this->akMandant = $ak($this->mandant, 'EIGEN');
    FoodAlchemistTeamSetting::create(['team_id' => $this->master->id]);
});

it('Kappung: Mandant unter dem Master sieht bei Stufe 4 weder Aufschlagsklassen noch Team-Einstellungen des Masters — der eigene Bereich erbt vom Mandanten', function () {
    config(['foodalchemist.team_sicht.stufe' => 4]);
    $sicht = fn (Team $t) => FaTeam::imTeam($t, fn () => [
        FoodAlchemistMarkupClass::query()->pluck('code')->sort()->values()->all(),
        FoodAlchemistTeamSetting::query()->pluck('team_id')->map(fn ($i) => (int) $i)->all(),
    ]);

    expect($sicht($this->mandant))->toBe([['EIGEN'], []])
        ->and($sicht($this->bereich))->toBe([['EIGEN'], []])
        ->and($sicht($this->master)[0])->toBe(['BHG']);
})->todo(note: 'Kappung vor Stufe 4 (Kette bis kundenHauptTeam) — Vorbedingung Einschalten, Spec 84 §4.2');
