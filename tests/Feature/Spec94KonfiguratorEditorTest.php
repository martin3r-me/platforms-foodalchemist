<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Angebote\KonfiguratorEditor;
use Platform\FoodAlchemist\Livewire\Angebote\Konfiguratoren;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfiguratorPaketMenge;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\LeistungVorlageService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94 Stufe 2 · Konfigurator-Editor: anlegen, Concept verknüpfen, Leistung aus Vorlage übernehmen, Paket anlegen,
 * Handwert setzen, Preisvorschau rendert; Fremdteam sieht den Konfigurator nicht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('Liste legt einen Konfigurator an und öffnet ihn im Editor', function () {
    Livewire::test(Konfiguratoren::class)->call('neu')->assertSee('Neuer Konfigurator');
    expect(FoodAlchemistKonfigurator::where('team_id', $this->rootTeam->id)->count())->toBe(1);
});

it('Editor: Concept, Vorlage, Paket, Handwert und Preisvorschau', function () {
    $k = app(KonfiguratorService::class)->anlegen($this->rootTeam, ['name' => 'Sommerfest']);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Grillbuffet', 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => 40, 'ek_per_person_cache' => 12]);
    $v = app(LeistungVorlageService::class)->anlegen($this->rootTeam, ['kategorie' => 'personal', 'bezeichnung' => 'Servicekraft', 'mengen_regel' => 'schluessel',
        'bezug' => 'gaeste', 'schluessel_n' => 30, 'preis_je' => 'stueck', 'haeufigkeit' => 'je_event', 'ek_satz' => 200,
        'vk_modus' => 'fixed', 'vk_satz' => 300, 'vk_begruendung' => 'Test']);

    $t = Livewire::test(KonfiguratorEditor::class)
        ->call('oeffnen', $k->id)
        ->set('conceptSuche', 'Grill')
        ->assertSee('Grillbuffet')
        ->call('conceptHinzu', $c->id)
        ->set('vorlageAuswahl', [(string) $v->id])
        ->call('vorlagenUebernehmen')
        ->set('paketNeu', ['von_pax' => '100', 'bis_pax' => '199', 'bezeichnung' => ''])
        ->call('paketAnlegen')
        ->assertSet('fehler', null);

    $k->refresh();
    $paket = $k->pakete()->first();
    $leistung = $k->leistungen()->first();
    expect($k->konzepte()->count())->toBe(1)->and($leistung->vorlage_id)->toBe($v->id)->and($paket->referenz_pax)->toBe(149);

    $t->call('mengeSetzen', $paket->id, $leistung->id, '6')->assertSet('fehler', null);
    expect((float) FoodAlchemistKonfiguratorPaketMenge::where('paket_id', $paket->id)->where('leistung_id', $leistung->id)->whereNull('konfigurator_konzept_id')->value('anzahl'))->toBe(6.0);

    // 40 + 6 × 300 / 149 = 52,08
    $t->assertSee('52,08 €')->assertSee('Grillbuffet');

    $t->set('form.kombi_rabatt_pct', '5')->call('speichern')->assertSet('fehler', null);
    expect((float) $k->fresh()->kombi_rabatt_pct)->toBe(5.0);
});

it('Paketgrenzen-Vorschlag im Editor und Übernahme', function () {
    $k = app(KonfiguratorService::class)->anlegen($this->rootTeam, ['name' => 'Gala']);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Menü', 'kind' => 'concept', 'status' => 'active', 'price_per_person_cache' => 50, 'ek_per_person_cache' => 15]);
    app(KonfiguratorService::class)->konzeptHinzu($this->rootTeam, $k->id, ['rolle' => 'haupt', 'dinner_concept_id' => $c->id]);
    app(KonfiguratorService::class)->leistungHinzu($this->rootTeam, $k->id, ['kategorie' => 'logistik', 'bezeichnung' => 'LKW', 'mengen_regel' => 'fest', 'anzahl' => 1,
        'preis_je' => 'stueck', 'haeufigkeit' => 'je_event', 'ek_satz' => 500, 'vk_modus' => 'fixed', 'vk_satz' => 800, 'vk_begruendung' => 'Test']);

    Livewire::test(KonfiguratorEditor::class)
        ->call('oeffnen', $k->id)
        ->set('vorschlagParameter', ['von' => '50', 'bis' => '500', 'toleranz' => '10', 'raster' => '25', 'mindestbreite' => '25'])
        ->call('vorschlagen')
        ->assertSet('fehler', null)
        ->call('vorschlagUebernehmen')
        ->assertSet('vorschlag', null);

    expect($k->pakete()->min('von_pax'))->toBe(50)->and($k->pakete()->max('bis_pax'))->toBe(500);
});

it('Fremdteam: ein Geschwister-Team öffnet den Konfigurator nicht', function () {
    $k = app(KonfiguratorService::class)->anlegen($this->childA, ['name' => 'Geheim']);
    $this->actingAs($this->makeUser($this->childB, 'B'));

    Livewire::test(KonfiguratorEditor::class)->call('oeffnen', $k->id)->assertSet('selectedId', null);
    Livewire::test(Konfiguratoren::class)->assertDontSee('Geheim');
});
