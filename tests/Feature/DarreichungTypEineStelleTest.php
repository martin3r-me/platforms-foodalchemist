<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Services\GpStamm\GpMasseService;
use Platform\FoodAlchemist\Services\Rezept\SalesRecipeService;
use Platform\FoodAlchemist\Support\NonFoodRegeln;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Servier-Typ und Gefäß an EINER Stelle (Entscheid Dominique 18.11.2026): Typ (neutral, Vokabular) und konkretes Gefäß (GP mit Preis) stehen an der
 * Darreichung. Das Feld „Servier-Vehikel“ am Gericht ist keine eigene Pflegestelle mehr: abgeleitet aus der Standard-Darreichung, Vorgabe für neue.
 * 98 · 3.5 6b: der Typ wird nicht mehr ans Gericht gespiegelt — wirksam ist {@see DarreichungService::vehikelId} der Standard-Darreichung; am
 * Gericht bleibt nur die Herkunft (serving_vehicle_source / _ai_confidence).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(DarreichungService::class);
    FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id], ['label' => 'Unbestimmt']);
    $this->buffet = FoodAlchemistServierform::firstOrCreate(['code' => 'buffet', 'team_id' => $this->rootTeam->id], ['label' => 'Buffet']);
    $typ = fn (string $n) => DB::table('foodalchemist_vocab_serving_vehicles')->insertGetId(['uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'slug' => mb_strtolower($n), 'name' => $n, 'sort_order' => 1, 'is_inactive' => false, 'created_at' => now(), 'updated_at' => now()]);
    $this->glasTyp = $typ('Schraubglas');
    $this->tellerTyp = $typ('Teller flach');
    $this->gericht = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'perl', 'name' => '[VOR] Perlcouscous-Salat',
        'status' => 'draft', 'is_sales_recipe' => true, 'yield_kg' => 0.12]);
    $this->std = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);
    $this->glas = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'gef|schraubglas', 'name' => 'Schraubglas: 282 ml', 'status' => 'approved',
        'commodity_group_code' => '16', 'sub_category' => NonFoodRegeln::SUB_GESCHIRR]);
    app(GpMasseService::class)->setzeServierTyp($this->glas, $this->glasTyp);
});

it('Gericht-Modal: kein eigenes Vehikel-Feld mehr, der Anrichten-Reiter zeigt den Typ der Standard-Darreichung', function () {
    $this->svc->aktualisieren($this->rootTeam, $this->std->id, ['vessel_gp_id' => $this->glas->id]);

    $c = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id);
    expect(array_key_exists('serving_vehicle_vocab_id', $c->get('form')))->toBeFalse();
    $c->assertDontSeeHtml('id="vk-vehikel"')->assertSeeHtml('data-vk-vehikel-standard')->assertSeeHtml('<span class="font-medium">Schraubglas</span>')->assertSee('· Gefäß Schraubglas: 282 ml')
        ->assertSeeHtml('data-dar-gefaess-passend');   // Gefäß-Auswahl: passend zum Typ zuerst
});

it('Typ oder Gefäß an der Standard-Darreichung → wirksamer Typ des Gerichts; Speichern im Kopf überschreibt nichts', function () {
    $wirksam = fn () => $this->svc->vehikelId($this->std->fresh());
    $this->svc->aktualisieren($this->rootTeam, $this->std->id, ['serving_vehicle_vocab_id' => $this->tellerTyp]);
    expect($wirksam())->toBe($this->tellerTyp);

    $this->svc->aktualisieren($this->rootTeam, $this->std->id, ['serving_vehicle_vocab_id' => null, 'vessel_gp_id' => $this->glas->id]);
    expect($wirksam())->toBe($this->glasTyp)
        ->and($this->gericht->fresh()->serving_vehicle_source)->toBe('manual');

    Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id)->call('bearbeitenStarten')->call('speichern')->assertSet('fehler', null);
    expect($wirksam())->toBe($this->glasTyp);

    // Typ am GP geändert → der wirksame Typ zieht live nach, die Herkunft am Gericht bleibt
    app(GpMasseService::class)->setzeServierTyp($this->glas->refresh(), $this->tellerTyp);
    expect($wirksam())->toBe($this->tellerTyp)
        ->and($this->gericht->fresh()->serving_vehicle_source)->toBe('manual');
});

it('Speichern der Zeile ohne Typänderung lässt die Herkunft am Gericht stehen (z. B. ki)', function () {
    $this->svc->aktualisieren($this->rootTeam, $this->std->id, ['serving_vehicle_vocab_id' => $this->tellerTyp]);
    DB::table('foodalchemist_recipes')->where('id', $this->gericht->id)->update(['serving_vehicle_source' => 'ki', 'serving_vehicle_ai_confidence' => 0.7]);
    $this->svc->aktualisieren($this->rootTeam, $this->std->id, ['unit_count' => '1', 'serving_vehicle_vocab_id' => $this->tellerTyp]);   // Zeile speichert alle Felder mit
    expect($this->gericht->fresh()->serving_vehicle_source)->toBe('ki');
});

it('neue Darreichung übernimmt den Typ des Gerichts als Vorgabe; KI-Vorschlag geht an die Standard-Darreichung, nicht über ein Gefäß', function () {
    $this->svc->aktualisieren($this->rootTeam, $this->std->id, ['serving_vehicle_vocab_id' => $this->tellerTyp]);
    $neu = $this->svc->anlegen($this->rootTeam, $this->gericht->id, $this->buffet->id);
    expect((int) $neu->serving_vehicle_vocab_id)->toBe($this->tellerTyp);

    expect($this->svc->typVorschlagUebernehmen($this->rootTeam, $this->gericht->id, $this->glasTyp, 0.8))->toBeTrue()
        ->and((int) $this->std->fresh()->serving_vehicle_vocab_id)->toBe($this->glasTyp)
        ->and($this->gericht->fresh()->serving_vehicle_source)->toBe('ki');

    $this->svc->aktualisieren($this->rootTeam, $this->std->id, ['serving_vehicle_vocab_id' => null, 'vessel_gp_id' => $this->glas->id]);
    expect($this->svc->typVorschlagUebernehmen($this->rootTeam, $this->gericht->id, $this->tellerTyp, 0.8))->toBeFalse()
        ->and($this->std->fresh()->serving_vehicle_vocab_id)->toBeNull();
});

it('MCP/Kopf-API serving_vehicle_vocab_id schreibt weiter in die Standard-Darreichung (Lockstep)', function () {
    app(SalesRecipeService::class)->updateVk($this->rootTeam, $this->gericht->id, ['serving_vehicle_vocab_id' => $this->tellerTyp]);
    expect((int) $this->std->fresh()->serving_vehicle_vocab_id)->toBe($this->tellerTyp)
        ->and($this->gericht->fresh()->serving_vehicle_source)->toBe('manual');   // 98 · 3.5 6b: am Gericht nur die Herkunft
});

it('Standardwechsel zwischen gleichartigen Glasvarianten übernimmt Typ und vererbte Menge, aber keine fremden Zeiten', function () {
    $this->svc->aktualisieren($this->rootTeam, $this->std->id, [
        'serving_vehicle_vocab_id' => $this->tellerTyp,
        'arbeitszeiten' => [['use_case' => 'abfuellen', 'basis' => 'einheit', 'ort' => 'kueche', 'wert' => 20]],
    ]);
    // Plan 98 B: Grammaturen als Altbestand-Fixture — das Gericht hat keine Komponenten, ein Anteil ist nicht berechenbar
    $this->grammaturAltbestand($this->std->fresh(), 120);
    $klein = $this->grammaturAltbestand($this->svc->anlegen($this->rootTeam, $this->gericht->id, $this->buffet->id), 80);
    $erbt = $this->svc->anlegen($this->rootTeam, $this->gericht->id, $this->buffet->id);
    $this->svc->aktualisieren($this->rootTeam, $klein->id, [
        'vessel_gp_id' => $this->glas->id, 'serving_vehicle_vocab_id' => null,
        'arbeitszeiten' => [['use_case' => 'abfuellen', 'basis' => 'einheit', 'ort' => 'vor_ort', 'wert' => 30]],
    ]);
    $this->svc->setzeStandard($this->rootTeam, $klein->id);

    expect($this->svc->vehikelId($klein->fresh()))->toBe($this->glasTyp)      // 98 · 3.5 6b: wirksamer Typ der (neuen) Standard-Darreichung
        ->and((bool) $klein->fresh()->is_standard)->toBeTrue()
        ->and($erbt->fresh()->quantity_per_unit_g)->toBe(80.0)
        ->and($erbt->fresh()->arbeitszeiten)->toBeNull()
        ->and($this->std->fresh()->quantity_per_unit_g)->toBe(120.0);
    $time = app(\Platform\FoodAlchemist\Services\Produktion\ProductionTimeService::class)
        ->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 100, null, [
            ['darreichung_id' => $klein->id, 'portionen' => 100],
        ]);
    expect($time['active_person_minutes'])->toBe(0.0)->and($time['vor_ort_minutes'])->toBe(50.0)
        ->and($this->std->fresh()->arbeitszeiten[0]['ort'])->toBe('kueche');
});
