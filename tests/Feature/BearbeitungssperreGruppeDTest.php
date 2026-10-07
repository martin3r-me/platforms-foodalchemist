<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Produktion\DetailPanel as ProduktionPanel;
use Platform\FoodAlchemist\Livewire\Produktion\Editor as ProduktionEditor;
use Platform\FoodAlchemist\Livewire\Settings\Einheiten as EinheitenSettings;
use Platform\FoodAlchemist\Livewire\Settings\Kalkulation as KalkulationSettings;
use Platform\FoodAlchemist\Livewire\Settings\Ki as KiSettings;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Services\BearbeitungssperreService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 65 · Bearbeitungssperre, Gruppe D: Produktion, Bestellungen, Einstellungen.
 * Einstellungen sperren den Bereich je Team (Ziel settings.<bereich>, team_id), Produktion den Auftrag.
 */
beforeEach(function () {
    config(['foodalchemist.bearbeitungssperre' => true]);
    $this->seedTeamHierarchy();
    $this->anna = $this->makeUser($this->rootTeam, 'Anna');
    $this->ben = $this->makeUser($this->rootTeam, 'Ben');
    $this->svc = app(BearbeitungssperreService::class);
    $this->settings = app(TeamSettingsService::class);
});

// ── Einstellungen · Kalkulation (ein Speichern-Knopf) ───────────────────────

it('Kalkulation: ohne „Bearbeiten" Lesemodus, Speichern wird abgewiesen', function () {
    $this->actingAs($this->anna);
    Livewire::test(KalkulationSettings::class)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->assertDontSeeHtml('data-save-bar-button')
        ->set('mwst.regulaer', '21')->call('speichern');

    expect((float) ($this->settings->for($this->rootTeam)->vat_defaults['regulaer'] ?? 19))->not->toBe(21.0);
});

it('Kalkulation: „Bearbeiten" schaltet frei, Speichern schreibt und gibt den Bereich frei', function () {
    $this->actingAs($this->anna);
    $c = Livewire::test(KalkulationSettings::class)->call('bearbeitenStarten')
        ->assertSeeHtml('data-save-bar-button')
        ->assertSeeHtml('data-fa-lesemodus="0"');
    expect($this->svc->haelt('settings.kalkulation', $this->rootTeam->id, $this->anna->id))->toBeTrue();

    $c->set('mwst.regulaer', '21')->call('speichern')->assertSeeHtml('data-fa-lesemodus="1"');
    expect((float) $this->settings->for($this->rootTeam)->fresh()->vat_defaults['regulaer'])->toBe(21.0)
        ->and($this->svc->haelt('settings.kalkulation', $this->rootTeam->id, $this->anna->id))->toBeFalse();
});

it('Kalkulation: zweite Person des Teams sieht „wird bearbeitet" und kann nicht schreiben', function () {
    $this->svc->sperren('settings.kalkulation', $this->rootTeam->id, $this->anna->id, 'Anna');
    $this->actingAs($this->ben);

    Livewire::test(KalkulationSettings::class)
        ->assertSeeHtml('data-bearbeiten-fremd')->assertSee('Wird von Anna bearbeitet')
        ->call('bearbeitenStarten')
        ->set('mwst.regulaer', '23')->call('speichern');

    expect($this->svc->haelt('settings.kalkulation', $this->rootTeam->id, $this->ben->id))->toBeFalse()
        ->and((float) ($this->settings->for($this->rootTeam)->vat_defaults['regulaer'] ?? 19))->not->toBe(23.0);
});

// ── Einstellungen · KI (updated-Hook schreibt direkt, läuft NICHT über den call-Haken) ─

it('KI: Sprachbefehl-Modus über wire:model.live ist ohne „Bearbeiten" abgewiesen, mit Sperre gespeichert', function () {
    $this->actingAs($this->anna);
    $vorher = $this->settings->voiceAgentModus($this->rootTeam);
    $ziel = $vorher === 'nur_lesen' ? 'fragen' : 'nur_lesen';

    Livewire::test(KiSettings::class)
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->set('sprachAgentModus', $ziel)
        ->assertSet('sprachAgentModus', $vorher);   // Formular springt auf den gespeicherten Wert zurück
    expect($this->settings->voiceAgentModus($this->rootTeam->fresh()))->toBe($vorher);

    Livewire::test(KiSettings::class)->call('bearbeitenStarten')->set('sprachAgentModus', $ziel);
    expect($this->settings->voiceAgentModus($this->rootTeam->fresh()))->toBe($ziel);
});

it('KI: Notschalter (Methode) ist ohne Sperre abgewiesen; zweite Person kann bei fremder Sperre nicht schalten', function () {
    $this->actingAs($this->anna);
    Livewire::test(KiSettings::class)->call('umschalten');
    expect($this->settings->kiAktiv($this->rootTeam->fresh()))->toBeTrue();

    $this->svc->sperren('settings.ki', $this->rootTeam->id, $this->anna->id, 'Anna');
    $this->actingAs($this->ben);
    Livewire::test(KiSettings::class)->assertSee('Wird von Anna bearbeitet')
        ->call('bearbeitenStarten')->call('umschalten')
        ->set('sprachAgentModus', 'nur_lesen');
    expect($this->settings->kiAktiv($this->rootTeam->fresh()))->toBeTrue()
        ->and($this->svc->haelt('settings.ki', $this->rootTeam->id, $this->ben->id))->toBeFalse();
});

// ── Einstellungen · Liste mit Sofort-Aktionen (Bearbeiten → Fertig) ─────────

it('Einheiten: Anlegen nur mit Sperre, „Fertig" gibt frei; Filter bleibt ohne Sperre bedienbar', function () {
    $this->actingAs($this->anna);
    $anzahl = fn () => \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::where('team_id', $this->rootTeam->id)->count();
    $vorher = $anzahl();

    $c = Livewire::test(EinheitenSettings::class)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->set('includeInactive', true)->assertSet('includeInactive', true)
        ->set('neu.slug', 'sperrtest')->set('neu.display_de', 'Sperrtest')->call('create');
    expect($anzahl())->toBe($vorher);

    $c->call('bearbeitenStarten')->assertSeeHtml('data-bearbeiten-fertig')->call('create');
    expect($anzahl())->toBe($vorher + 1)
        ->and($this->svc->haelt('settings.einheiten', $this->rootTeam->id, $this->anna->id))->toBeTrue();

    $c->call('bearbeitenFertig');
    expect($this->svc->haelt('settings.einheiten', $this->rootTeam->id, $this->anna->id))->toBeFalse();
});

it('Einstellungen: die Sperre gilt je Team — ein anderes Team ist nicht berührt', function () {
    $this->svc->sperren('settings.kalkulation', $this->rootTeam->id, $this->anna->id, 'Anna');
    $carla = $this->makeUser($this->childB, 'Carla');
    $this->actingAs($carla);

    Livewire::test(KalkulationSettings::class)->assertSeeHtml('data-bearbeiten-starten')->assertDontSeeHtml('data-bearbeiten-fremd')
        ->call('bearbeitenStarten');
    expect($this->svc->haelt('settings.kalkulation', $this->childB->id, $carla->id))->toBeTrue();
});

// ── Produktion · Editor + Detailspalte (Ziel production_order) ──────────────

function gruppeDAuftrag($team): FoodAlchemistProductionOrder
{
    $rezept = FoodAlchemistRecipe::create([
        'team_id' => $team->id, 'recipe_key' => 'fond', 'name' => 'Brauner Fond',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 2.0, 'work_time_min' => 60,
    ]);

    return app(ProductionOrderService::class)->saveNew($team, '2026-08-20', 'Sperr-Auftrag', [
        ['source_ref' => 'r:fond', 'recipe_id' => $rezept->id, 'amount_kg' => 6.0],
    ]);
}

it('Produktion-Editor: ohne „Bearbeiten" Lesemodus, Speichern und Zeilen-Eingriff abgewiesen', function () {
    $order = gruppeDAuftrag($this->rootTeam);
    $zeile = FoodAlchemistProductionOrderLine::where('production_order_id', $order->id)->first();
    $this->actingAs($this->anna);

    Livewire::test(ProduktionEditor::class)->call('oeffnenBearbeiten', $order->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->assertDontSeeHtml('data-produktion-speichern')
        ->set('name', 'Geändert')->call('speichern')
        ->call('zeileStreichen', $zeile->id, true);

    expect($order->fresh()->name)->toBe('Sperr-Auftrag')
        ->and((bool) $zeile->fresh()->is_struck)->toBeFalse();
});

it('Produktion-Editor: „Bearbeiten" schaltet frei, Speichern schreibt, gibt frei und bleibt offen', function () {
    $order = gruppeDAuftrag($this->rootTeam);
    $this->actingAs($this->anna);

    $c = Livewire::test(ProduktionEditor::class)->call('oeffnenBearbeiten', $order->id)
        ->call('bearbeitenStarten')
        ->assertSeeHtml('data-produktion-speichern')
        ->assertSeeHtml('data-fa-lesemodus="0"');
    expect($this->svc->haelt('production_order', $order->id, $this->anna->id))->toBeTrue();

    $c->set('name', 'Gespeichert')->call('speichern')
        ->assertNotDispatched('modal.close')
        ->assertDispatched('produktion-gespeichert')
        ->assertSeeHtml('data-fa-lesemodus="1"');
    expect($order->fresh()->name)->toBe('Gespeichert')
        ->and($this->svc->haelt('production_order', $order->id, $this->anna->id))->toBeFalse();
});

it('Produktion-Editor: zweite Person sieht „wird bearbeitet" und kann nicht schreiben; Schließen gibt frei', function () {
    $order = gruppeDAuftrag($this->rootTeam);
    $this->actingAs($this->anna);
    Livewire::test(ProduktionEditor::class)->call('oeffnenBearbeiten', $order->id)->call('bearbeitenStarten');

    $this->actingAs($this->ben);
    Livewire::test(ProduktionEditor::class)->call('oeffnenBearbeiten', $order->id)
        ->assertSeeHtml('data-bearbeiten-fremd')->assertSee('Wird von Anna bearbeitet')
        ->call('bearbeitenStarten')
        ->set('name', 'Von Ben')->call('speichern');
    expect($order->fresh()->name)->toBe('Sperr-Auftrag')
        ->and($this->svc->haelt('production_order', $order->id, $this->ben->id))->toBeFalse();

    $this->actingAs($this->anna);
    Livewire::test(ProduktionEditor::class)->call('oeffnenBearbeiten', $order->id)
        ->dispatch('modal.closed', name: 'produktion-editor');
    expect($this->svc->haelt('production_order', $order->id, $this->anna->id))->toBeFalse();
});

it('Produktion-Detailspalte: Status nur mit derselben Sperre wie der Editor', function () {
    $order = gruppeDAuftrag($this->rootTeam);
    $this->actingAs($this->anna);

    $c = Livewire::test(ProduktionPanel::class, ['orderId' => $order->id])
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertDontSeeHtml('data-produktion-status="in_progress"')
        ->call('setStatus', 'in_progress');
    expect($order->fresh()->status->value)->toBe('planned');

    $c->call('bearbeitenStarten')->assertSeeHtml('data-bearbeiten-fertig')->call('setStatus', 'in_progress');
    expect($order->fresh()->status->value)->toBe('in_progress')
        ->and($this->svc->haelt('production_order', $order->id, $this->anna->id))->toBeTrue();

    $c->call('bearbeitenFertig');
    expect($this->svc->haelt('production_order', $order->id, $this->anna->id))->toBeFalse();
});

// ── Alle Einstellungs-Bereiche: Lesemodus mit „Bearbeiten", nach dem Sperren schreibbar ──

it('Einstellungen: jeder Bereich zeigt ohne Sperre den Lesemodus und nach „Bearbeiten" die Eingaben', function (string $klasse, string $bereich) {
    $this->actingAs($this->anna);

    $c = Livewire::test($klasse)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->assertDontSeeHtml('data-fa-lesemodus="0"');

    $c->call('bearbeitenStarten')
        ->assertDontSeeHtml('data-bearbeiten-starten')
        ->assertDontSeeHtml('data-fa-lesemodus="1"');
    expect($this->svc->haelt('settings.' . $bereich, $this->rootTeam->id, $this->anna->id))->toBeTrue();
})->with([
    [\Platform\FoodAlchemist\Livewire\Settings\Aufschlagsklassen::class, 'aufschlagsklassen'],
    [\Platform\FoodAlchemist\Livewire\Settings\Behaelter::class, 'behaelter'],
    [\Platform\FoodAlchemist\Livewire\Settings\Betriebe::class, 'betriebe'],
    [\Platform\FoodAlchemist\Livewire\Settings\BriefVorlagen::class, 'brief_vorlagen'],
    [\Platform\FoodAlchemist\Livewire\Settings\ConcepterDimensionen::class, 'concepter_dimensionen'],
    [\Platform\FoodAlchemist\Livewire\Settings\Einheiten::class, 'einheiten'],
    [\Platform\FoodAlchemist\Livewire\Settings\Einkauf::class, 'einkauf'],
    [\Platform\FoodAlchemist\Livewire\Settings\Einsatzorte::class, 'einsatzorte'],
    [\Platform\FoodAlchemist\Livewire\Settings\Herstellkosten::class, 'herstellkosten'],
    [\Platform\FoodAlchemist\Livewire\Settings\Kalkulation::class, 'kalkulation'],
    [\Platform\FoodAlchemist\Livewire\Settings\Ki::class, 'ki'],
    [\Platform\FoodAlchemist\Livewire\Settings\KonzeptTaxonomie::class, 'konzept_taxonomie'],
    [\Platform\FoodAlchemist\Livewire\Settings\Kueche::class, 'kueche'],
    [\Platform\FoodAlchemist\Livewire\Settings\Posten::class, 'posten'],
    [\Platform\FoodAlchemist\Livewire\Settings\PraesentationsDesigns::class, 'praesentations_designs'],
    [\Platform\FoodAlchemist\Livewire\Settings\Rollen::class, 'rollen'],
    [\Platform\FoodAlchemist\Livewire\Settings\Schreibstile::class, 'schreibstile'],
    [\Platform\FoodAlchemist\Livewire\Settings\SpeiseplanChips::class, 'speiseplan_chips'],
    [\Platform\FoodAlchemist\Livewire\Settings\Taxonomie::class, 'taxonomie'],
    [\Platform\FoodAlchemist\Livewire\Settings\Trendradar::class, 'trendradar'],
    [\Platform\FoodAlchemist\Livewire\Settings\VkTaxonomie::class, 'vk_taxonomie'],
    [\Platform\FoodAlchemist\Livewire\Settings\Warengruppen::class, 'warengruppen'],
    [\Platform\FoodAlchemist\Livewire\Settings\Wissenskategorien::class, 'wissenskategorien'],
    [\Platform\FoodAlchemist\Livewire\Settings\Wissenssteuerung::class, 'wissenssteuerung'],
]);

// ── Bestellungen · Editor (Ziel order) ──────────────────────────────────────

it('Bestellungen-Editor: Beleg ohne „Bearbeiten" Lesemodus, Kopf speichern abgewiesen; mit Sperre schreibbar', function () {
    $this->actingAs($this->anna);
    $lieferant = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs Culinar', 'status' => 'aktiv']);
    $order = \Platform\FoodAlchemist\Models\FoodAlchemistOrder::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $lieferant->id, 'status' => 'draft', 'reference' => 'Alt',
    ]);

    $c = Livewire::test(\Platform\FoodAlchemist\Livewire\Orders\Editor::class)->call('oeffnenBearbeiten', $order->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->set('formReference', 'Neu')->call('saveHeader');
    expect($order->fresh()->reference)->toBe('Alt');

    $c->call('bearbeitenStarten')->assertSeeHtml('data-fa-lesemodus="0"')->call('saveHeader');
    expect($order->fresh()->reference)->toBe('Neu')
        ->and($this->svc->haelt('order', $order->id, $this->anna->id))->toBeTrue();   // Abschnitts-Speichern gibt nicht frei

    $c->call('bearbeitenFertig');
    expect($this->svc->haelt('order', $order->id, $this->anna->id))->toBeFalse();
});
