<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Editor;
use Platform\FoodAlchemist\Livewire\Kalkulation\Index as KalkulationBrowser;
use Platform\FoodAlchemist\Livewire\Settings\Herstellkosten as HerstellkostenSettings;
use Platform\FoodAlchemist\Livewire\Settings\Kalkulation as KalkulationSettings;
use Platform\FoodAlchemist\Models\FoodAlchemistFixkosten;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\KalkulationService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M-K1/M-K2 / Doc 16: Kalkulations-Block-Schema (Wareneinsatz + Lohn + Schwund +
 * Gemeinkosten → HK2; VK-Vorschlag = HK2 × Marge) + Lohn aus Arbeitszeit×Stundensatz.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->kalk = app(KalkulationService::class);
    $this->settings = app(TeamSettingsService::class);
    // Gemeinkosten 20 % (= Default-Block-Wert), Stundensatz 30 €/h, Marge 15 %.
    $this->settings->update($this->rootTeam, ['hk2_zuschlag_pct' => 20, 'stundensatz_eur' => 30, 'marge_pct' => 15]);
});

function block(array $r, string $key): float
{
    return (float) (collect($r['bloecke'])->firstWhere('key', $key)['betrag'] ?? 0);
}

it('Default-Schema: Lohn/Schwund/Gemeinkosten aktiv, Verpackung/Lager inaktiv', function () {
    $schema = collect($this->settings->kalkulationSchema($this->rootTeam))->keyBy('key');

    expect($schema['lohn']['aktiv'])->toBeTrue()
        ->and($schema['schwund']['aktiv'])->toBeTrue()
        ->and($schema['gemeinkosten']['aktiv'])->toBeTrue()
        ->and($schema['gemeinkosten']['wert'])->toBe(20.0)            // erbt hk2_zuschlag_pct
        ->and($schema['gemeinkosten']['typ'])->toBe('pct_mek')        // Material-GK auf Wareneinsatz
        ->and($schema['verpackung']['aktiv'])->toBeFalse();
});

it('berechne: mehrstufig — Material-GK auf MEK, Lohn = FEK, HK2 = Selbstkosten', function () {
    // MEK 10 €; Lohn 10 min @ 30 €/h = 5 € (FEK); Material-GK 20 % × MEK(10) = 2 €.
    // HK2 = 10 + 5 + 2 = 17 (Fertigungs-/Verwaltungs-/Logistik-GK default 0).
    $r = $this->kalk->berechne($this->rootTeam, 10.0, 10.0, 0.0);

    expect(block($r, 'we'))->toBe(10.0)
        ->and(block($r, 'lohn'))->toBe(5.0)
        ->and(block($r, 'gemeinkosten'))->toBe(2.0)                   // 20 % auf MEK, NICHT auf MEK+Lohn
        ->and($r['hk2'])->toBe(17.0)
        ->and($r['vk_vorschlag'])->toBe(19.55);                       // 17 × 1,15
});

it('berechne ohne Arbeitszeit + 0 Gemeinkosten = HK2 ist WE (childA)', function () {
    $this->settings->update($this->childA, ['hk2_zuschlag_pct' => 0]);
    expect($this->kalk->hk2($this->childA, 5.0))->toBe(5.0);
});

it('recipeHk: Lohn aus arbeitszeit_min/Portion fließt in HK2', function () {
    $r = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g', 'name' => 'HG', 'status' => 'approved',
        'ist_verkaufsrezept' => true, 'ek_total_eur' => 10.00, 'vk_anzahl_einheiten' => 1,
        'arbeitszeit_min' => 10, 'vk_netto' => 25.00,
    ]);

    $hk = $this->kalk->recipeHk($this->rootTeam, $r);
    expect($hk['hk2_pro_portion'])->toBe(17.0)                        // MEK 10 + FEK 5 + Material-GK 2
        ->and(block($hk, 'lohn'))->toBe(5.0)
        ->and($hk['db_eur'])->toBe(8.0);                              // 25 − 17
});

it('conceptHk: Lohn aus dem Arbeitszeit-Rollup pro Person (M-K2)', function () {
    $g = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'd', 'name' => 'Dish', 'status' => 'approved',
        'ist_verkaufsrezept' => true, 'vk_netto' => 6.00, 'ek_total_eur' => 2.00,
        'arbeitszeit_min' => 10, 'vk_anzahl_einheiten' => 1,
    ]);
    $paket = app(PaketService::class)->create($this->rootTeam, ['name' => 'P', 'rolle' => 'Vorspeise', 'preis_modus' => 'auto']);
    app(PaketService::class)->syncGerichte($this->rootTeam, $paket->id, [['vk_recipe_id' => $g->id]]);
    $concept = app(ConceptService::class)->create($this->rootTeam, ['name' => 'C']);
    $slot = app(ConceptService::class)->addSlot($this->rootTeam, $concept->id, ['rolle' => 'Vorspeise']);
    app(ConceptService::class)->fillSlot($this->rootTeam, $slot->id, ['paket_id' => $paket->id]);

    $hk = $this->kalk->conceptHk($this->rootTeam, $concept->refresh());
    // WE/Person = 2,00 (auto-Paket = Σ ek); Lohn = 10 min @ 30 = 5,00 (FEK);
    // Material-GK 20 % × MEK(2) = 0,40. HK2 = 2 + 5 + 0,40 = 7,40.
    expect(block($hk, 'lohn'))->toBe(5.0)
        ->and($hk['hk2_pro_person'])->toBe(7.4);
});

it('Settings/Herstellkosten: Block-Schema + Marge über die UI speichern', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    $comp = Livewire::test(HerstellkostenSettings::class)->assertOk();
    // Lohn-Block (erster editierbarer) auf €/h setzen + aktiv, Marge ändern.
    $comp->set('schema.0.aktiv', true)->set('schema.0.wert', '40')
        ->set('marge', '20')
        ->call('speichern');

    $schema = collect(app(TeamSettingsService::class)->kalkulationSchema($this->rootTeam))->keyBy('key');
    expect((float) $schema['lohn']['wert'])->toBe(40.0)
        ->and(app(TeamSettingsService::class)->margePct($this->rootTeam))->toBe(20.0)
        ->and($schema->has('gemeinkosten'))->toBeTrue();              // Gemeinkosten bleibt im Schema
});

it('Settings/Kalkulation: Gar-/Putzverlust-Defaults speichern (HK ist ausgelagert)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(KalkulationSettings::class)->assertOk()
        ->set('garverlust.*', '12')->set('putzverlust.*', '4')
        ->call('speichern');

    $svc = app(TeamSettingsService::class);
    expect($svc->garverlustDefault($this->rootTeam))->toBe(12.0)
        ->and($svc->putzverlustDefault($this->rootTeam))->toBe(4.0);
});

it('recipeHk: kompletter Wasserfall (DB, Food-Cost-Quote, VK-Vorschlag)', function () {
    // Der frühere Kalkulation-Browser (waehle/Detail) ist mit dem Werkstatt-Umbau (#379+)
    // entfallen — der Screen zeigt Regeln; die per-Gericht-Rechnung liefert recipeHk.
    $r = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'hk', 'name' => 'HG: Filet', 'status' => 'approved',
        'ist_verkaufsrezept' => true, 'ek_total_eur' => 8.00, 'vk_anzahl_einheiten' => 1, 'vk_netto' => 25.00,
    ]);

    $hk = $this->kalk->recipeHk($this->rootTeam, $r);

    expect((float) $hk['hk1_pro_portion'])->toBe(8.0)
        ->and((float) $hk['hk2_pro_portion'])->toBe(9.6)              // + Material-GK 20 %
        ->and((float) $hk['db_eur'])->toBe(15.4)                      // 25 − 9,60
        ->and((float) $hk['wareneinsatz_pct'])->toBe(32.0)            // 8 ÷ 25
        ->and((float) $hk['vk_vorschlag'])->toBe(11.04)               // 9,60 × 1,15
        ->and($hk['bloecke'])->not->toBeEmpty();
});

it('Settings/Herstellkosten: Fixkosten anlegen/löschen + Bezugsbasen speichern (UI)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    $comp = Livewire::test(HerstellkostenSettings::class)->assertOk()
        ->set('neuFix.bezeichnung', 'LKW Logistik')
        ->set('neuFix.betrag', '1500')
        ->set('neuFix.block_key', 'logistik')
        ->call('fixHinzu');

    expect(FoodAlchemistFixkosten::where('bezeichnung', 'LKW Logistik')->exists())->toBeTrue();

    $comp->set('bezugsbasen.hk', '30000')->set('bezugsbasen.mek', '20000')->call('speichern');
    $basen = app(TeamSettingsService::class)->bezugsbasen($this->rootTeam);
    expect($basen['hk'])->toBe(30000.0)->and($basen['mek'])->toBe(20000.0);

    $id = FoodAlchemistFixkosten::where('bezeichnung', 'LKW Logistik')->value('id');
    $comp->call('fixLoeschen', $id);
    expect(FoodAlchemistFixkosten::where('bezeichnung', 'LKW Logistik')->exists())->toBeFalse();
});

it('M-K8-Mathe: direkte Einzelkosten (nebenkosten_eur) fließen in HK2 — Pflege-UI seit Werkstatt-Umbau offen', function () {
    // Der M-K8-Editor (waehle → editArbeitszeit/editNebenkosten) fiel mit dem Werkstatt-
    // Umbau weg; die RECHNUNG muss trotzdem stimmen. UI-Lücke ist in #379 vermerkt.
    $ohne = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'e0', 'name' => 'HG: Ohne', 'status' => 'approved',
        'ist_verkaufsrezept' => true, 'ek_total_eur' => 8.00, 'vk_anzahl_einheiten' => 1, 'vk_netto' => 25.00,
    ]);
    $mit = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'e1', 'name' => 'HG: Mit', 'status' => 'approved',
        'ist_verkaufsrezept' => true, 'ek_total_eur' => 8.00, 'vk_anzahl_einheiten' => 1, 'vk_netto' => 25.00,
        'nebenkosten_eur' => 1.50,
    ]);

    $hkOhne = $this->kalk->recipeHk($this->rootTeam, $ohne);
    $hkMit = $this->kalk->recipeHk($this->rootTeam, $mit);

    expect((float) $hkMit['nebenkosten'])->toBe(1.5)
        ->and(round($hkMit['hk2_pro_portion'] - $hkOhne['hk2_pro_portion'], 2))->toBe(1.5);
});

it('Concepter-Editor: Kalkulation-Tab zeigt den HK2-Wasserfall', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $concept = app(ConceptService::class)->create($this->rootTeam, ['name' => 'C']);

    Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $concept->id)
        ->call('setTab', 'kalkulation')
        ->assertSee('HK2')
        ->assertSee('VK-Vorschlag');
});

it('Schema lässt sich speichern und wird normalisiert (sortiert, nur valide Typen)', function () {
    $this->settings->update($this->rootTeam, ['kalkulation_schema' => [
        ['key' => 'b', 'label' => 'B', 'typ' => 'pct_we', 'wert' => 5, 'aktiv' => true, 'sort' => 20],
        ['key' => 'a', 'label' => 'A', 'typ' => 'eur_pro_portion', 'wert' => 1, 'aktiv' => true, 'sort' => 10],
        ['key' => 'x', 'label' => 'X', 'typ' => 'quatsch', 'wert' => 9, 'aktiv' => true, 'sort' => 5], // invalider Typ → raus
    ]]);

    $schema = $this->settings->kalkulationSchema($this->rootTeam);
    expect($schema)->toHaveCount(2)
        ->and($schema[0]['key'])->toBe('a')                          // nach sort
        ->and($schema[1]['key'])->toBe('b');
});
