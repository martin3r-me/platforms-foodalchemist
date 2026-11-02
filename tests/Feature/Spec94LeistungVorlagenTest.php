<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Livewire\Angebote\LeistungVorlagen;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfiguratorLeistung;
use Platform\FoodAlchemist\Models\FoodAlchemistLeistungVorlage;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Services\CatalogPricingService;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\LeistungVorlageService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94 · Leistungs-Vorlagen: VK aus Leistungsklasse und Basissatz (eine Formel mit den Speisen), Bereich der
 * Aufschlagsklassen, Vererbung über die Team-Kette, Übernahme als Kopie mit umgehängtem Bezug, Oberfläche.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    // Basissatz 2,0 über die Ziel-Wareneinsatzquote (50 %)
    app(TeamSettingsService::class)->update($this->rootTeam, ['target_food_cost_pct' => 50.0]);
    $this->svc = app(LeistungVorlageService::class);
    $this->personal = FoodAlchemistMarkupClass::create(['team_id' => $this->rootTeam->id, 'code' => 'PERS', 'label' => 'Personal',
        'class_factor_pct' => 60, 'bereich' => 'leistung']);
    $this->speise = FoodAlchemistMarkupClass::create(['team_id' => $this->rootTeam->id, 'code' => 'STD', 'label' => 'Standard',
        'class_factor_pct' => 100, 'bereich' => 'speisen']);
});

function v94(array $in = []): array
{
    return $in + ['kategorie' => 'personal', 'bezeichnung' => 'Servicekraft', 'mengen_regel' => 'schluessel', 'bezug' => 'gaeste', 'schluessel_n' => 30,
        'preis_je' => 'stunde', 'stunden_quelle' => 'einsatzzeit', 'haeufigkeit' => 'je_tageszeit', 'ek_satz' => 30];
}

it('VK automatisch: EK × Basissatz × Leistungsklasse; fest nur mit Begründung', function () {
    $v = $this->svc->anlegen($this->rootTeam, v94(['markup_class_id' => $this->personal->id]));
    expect((float) $v->vk_satz)->toBe(36.0);   // 30 × 2,0 × 60 %

    expect(fn () => $this->svc->aendern($this->rootTeam, $v->id, ['vk_modus' => 'fixed', 'vk_satz' => 42]))
        ->toThrow(InvalidArgumentException::class, 'Begründung');
    $v = $this->svc->aendern($this->rootTeam, $v->id, ['vk_modus' => 'fixed', 'vk_satz' => 42, 'vk_begruendung' => 'Marktpreis']);
    expect((float) $v->vk_satz)->toBe(42.0);
});

it('Bereich: eine Speisenklasse ist an einer Leistung nicht wählbar, Speisen-Auswahlen sehen keine Leistungsklasse', function () {
    expect(fn () => $this->svc->anlegen($this->rootTeam, v94(['markup_class_id' => $this->speise->id])))
        ->toThrow(InvalidArgumentException::class, 'Leistungsklasse');

    // Team-Standard nur aus Speisenklassen
    app(TeamSettingsService::class)->update($this->rootTeam, ['default_markup_class_id' => $this->personal->id]);
    expect(app(TeamSettingsService::class)->defaultMarkupClassId($this->rootTeam))->toBeNull();

    // Die gemeinsame Formel: Leistung ohne Klasse fällt nicht auf den Speisen-Standard, sondern rechnet neutral
    app(TeamSettingsService::class)->update($this->rootTeam, ['default_markup_class_id' => $this->speise->id]);
    $katalog = app(CatalogPricingService::class);
    $base = $katalog->enterpriseBaseRate($this->rootTeam);
    $leistung = $katalog->klassenVk($this->rootTeam, 10.0, null, $base, 'leistung');
    $speise = $katalog->klassenVk($this->rootTeam, 10.0, $this->personal, $base);
    expect($leistung['class'])->toBeNull()->and($leistung['calculated'])->toBe(20.0)
        ->and($speise['class']?->id)->toBe($this->speise->id);   // Leistungsklasse an einer Darreichung zählt nicht → Team-Standard
});

it('Vererbung: das Unterteam sieht die Vorlage des Oberteams, ändert sie aber nicht', function () {
    $v = $this->svc->anlegen($this->rootTeam, v94());

    expect($this->svc->liste($this->childA)->pluck('id')->all())->toContain($v->id);
    expect(fn () => $this->svc->aendern($this->childA, $v->id, ['bezeichnung' => 'Umbenannt']))->toThrow(GeerbtNurLesenException::class);

    $eigene = $this->svc->anlegen($this->childA, v94(['bezeichnung' => 'Eigene Servicekraft']));
    expect($this->svc->liste($this->rootTeam)->pluck('id')->all())->not->toContain($eigene->id)
        ->and($this->svc->liste($this->childB)->pluck('id')->all())->not->toContain($eigene->id);
});

it('Übernahme aus Vorlagen: Kopie mit Stand, Bezug auf die übernommene Leistung umgehängt, Abgleich erkennt Änderungen', function () {
    $service = $this->svc->anlegen($this->rootTeam, v94());
    $leitung = $this->svc->anlegen($this->rootTeam, v94(['bezeichnung' => 'Teamleitung', 'bezug' => 'leistung', 'bezug_leistung_id' => $service->id, 'schluessel_n' => 8]));
    $ks = app(KonfiguratorService::class);
    $k = $ks->anlegen($this->rootTeam, ['name' => 'Gala']);

    $erg = $ks->ausVorlagen($this->rootTeam, $k->id, [$service->id, $leitung->id]);
    $kopie = collect($erg['leistungen'])->keyBy('vorlage_id');
    expect($erg['warnungen'])->toBe([])
        ->and($kopie[$leitung->id]->fresh()->bezug_leistung_id)->toBe($kopie[$service->id]->id)
        ->and($ks->vorlageGeaendert($this->rootTeam, $k->id))->toBe([]);

    $this->travel(1)->minutes();
    $this->svc->aendern($this->rootTeam, $service->id, ['ek_satz' => 32]);
    expect($ks->vorlageGeaendert($this->rootTeam, $k->id))->toBe([$kopie[$service->id]->id])
        ->and((float) $kopie[$service->id]->fresh()->ek_satz)->toBe(30.0);   // Kopie ändert sich nicht still

    $ks->vorlageAbgleich($this->rootTeam, $kopie[$service->id]->id);
    expect((float) FoodAlchemistKonfiguratorLeistung::find($kopie[$service->id]->id)->ek_satz)->toBe(32.0)
        ->and($ks->vorlageGeaendert($this->rootTeam, $k->id))->toBe([]);
});

it('Oberfläche: Vorlage anlegen, Liste zeigt sie, geerbte Vorlage ist nicht bearbeitbar', function () {
    Livewire::test(LeistungVorlagen::class)
        ->call('neu', 'logistik')
        ->set('form.bezeichnung', 'LKW 7,5 t')
        ->set('form.mengen_regel', 'schluessel')
        ->set('form.schluessel_n', '250')
        ->set('form.ek_satz', '180')
        ->set('form.markup_class_id', (string) $this->personal->id)
        ->call('speichern')
        ->assertSet('fehler', null)
        ->assertSee('LKW 7,5 t');
    expect(FoodAlchemistLeistungVorlage::where('bezeichnung', 'LKW 7,5 t')->value('kategorie'))->toBe('logistik');

    $this->actingAs($this->makeUser($this->childA, 'Kind'));
    $id = FoodAlchemistLeistungVorlage::where('bezeichnung', 'LKW 7,5 t')->value('id');
    Livewire::test(LeistungVorlagen::class)
        ->assertSee('Geerbt')
        ->call('bearbeiten', $id)
        ->assertSet('editId', null)
        ->assertSee('geerbt');
});
