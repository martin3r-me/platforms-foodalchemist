<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Kalkulator\Index as Kalkulator;
use Platform\FoodAlchemist\Models\FoodAlchemistKalkulation;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M-K10b / Doc 16 §11: Kalkulator-Composer-UI — anlegen, Positionen hinzufügen/
 * entfernen, HK1/HK2/VK-Wasserfall.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    app(TeamSettingsService::class)->update($this->rootTeam, ['hk2_surcharge_pct' => 20, 'stundensatz_eur' => 30, 'marge_pct' => 15]);
});

it('legt eine Kalkulation an', function () {
    $comp = Livewire::test(Kalkulator::class)->assertOk()->call('neueKalkulation');

    expect($comp->get('selectedId'))->not->toBeNull()
        ->and(FoodAlchemistKalkulation::where('team_id', $this->rootTeam->id)->count())->toBe(1);
});

it('Deep-Link ?k=ID rendert den Editor mit HK1/HK2-Wasserfall', function () {
    $k = app(\Platform\FoodAlchemist\Services\KalkulationDokService::class)->create($this->rootTeam, 'Menü A');

    Livewire::withQueryParams(['k' => $k->id])
        ->test(Kalkulator::class)
        ->assertSet('selectedId', $k->id)
        ->assertSee('Menü A')
        ->assertSee('HK1')
        ->assertSee('Selbstkosten')
        ->assertSee('VK-Vorschlag');
});

it('Gericht-Position hinzufügen zeigt Wareneinsatz + HK2/VK-Vorschlag', function () {
    $g = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g', 'name' => 'HG: Filet', 'status' => 'approved',
        'is_sales_recipe' => true, 'ek_total_eur' => 20.00, 'sales_unit_count' => 4, 'work_time_min' => 40,
    ]);

    $comp = Livewire::test(Kalkulator::class)->call('neueKalkulation');
    $kId = $comp->get('selectedId');
    $comp->set('addTyp', 'gericht')->call('addPosition', $g->id)
        ->assertSee('HG: Filet')
        ->assertSee('HK2')
        ->assertSee('VK-Vorschlag');

    $k = FoodAlchemistKalkulation::find($kId);
    expect($k->positionen()->count())->toBe(1)
        ->and((float) $k->positionen()->first()->einzel_ek)->toBe(5.0);   // 20 € / 4 Portionen
});

it('freie Zeile hinzufügen + Position entfernen', function () {
    $comp = Livewire::test(Kalkulator::class)->call('neueKalkulation');
    $kId = $comp->get('selectedId');
    $comp->set('addTyp', 'frei')->call('addPosition');
    expect(FoodAlchemistKalkulation::find($kId)->positionen()->count())->toBe(1);

    $posId = FoodAlchemistKalkulation::find($kId)->positionen()->first()->id;
    $comp->call('entfernePos', $posId);
    expect(FoodAlchemistKalkulation::find($kId)->positionen()->count())->toBe(0);
});

it('Marge-Override speichern wirkt auf den VK-Vorschlag', function () {
    $comp = Livewire::test(Kalkulator::class)->call('neueKalkulation');
    $kId = $comp->get('selectedId');
    $comp->set('margeOverride', '25')->call('speichereKopf');

    expect((float) FoodAlchemistKalkulation::find($kId)->marge_override_pct)->toBe(25.0);
});
