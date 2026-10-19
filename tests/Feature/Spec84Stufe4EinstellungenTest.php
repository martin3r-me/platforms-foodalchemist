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
 * Kappung: TeamSicht::kette (Modelle) / ketteFuerTabelle (rohe Abfragen), dazu TeamSettingsService::ahnenZeilen und
 * BriefTemplateService::sichtbar. Erst ab Schalter 4 — bei Stufe 0 bleibt die volle Kette (Gegenstück unten).
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
    FoodAlchemistTeamSetting::create(['team_id' => $this->master->id, 'iodized_hint_active' => true]);
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
});

it('Kappung auch in den Ketten-Diensten: geerbte Team-Einstellung (Jod-Hinweis) und sichtbare Aufschlagsklassen per visibleToTeam', function () {
    config(['foodalchemist.team_sicht.stufe' => 4]);
    $svc = app(\Platform\FoodAlchemist\Services\TeamSettingsService::class);

    expect($svc->jodHinweisAktiv($this->mandant))->toBeFalse()
        ->and($svc->jodHinweisAktiv($this->master))->toBeTrue()
        ->and(FoodAlchemistMarkupClass::visibleToTeam($this->bereich)->pluck('code')->all())->toBe(['EIGEN'])
        ->and(\Platform\FoodAlchemist\Support\TeamScope::applyVisible(\Illuminate\Support\Facades\DB::table('foodalchemist_markup_classes'), 'team_id', $this->mandant)
            ->pluck('code')->all())->toBe(['EIGEN']);
});

it('Gegenstück Stufe 0: ohne Schalter bleibt die volle Kette (heutiges Verhalten unverändert)', function () {
    config(['foodalchemist.team_sicht.stufe' => 0]);

    expect(FoodAlchemistMarkupClass::visibleToTeam($this->mandant)->pluck('code')->sort()->values()->all())->toBe(['BHG', 'EIGEN'])
        ->and(app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->jodHinweisAktiv($this->mandant))->toBeTrue();
});
