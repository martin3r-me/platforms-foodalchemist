<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Livewire\Produktion\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine as Line;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 30 E2 — Zeilen-Tab im Produktions-Editor.
 *
 * Der Tab ist die Bedienfläche für den Zeilen-Eingriff aus E1. Getestet wird HIER (über die
 * Komponente, die der Nutzer bedient) und nicht über die verwaiste DetailPanel-Komponente —
 * genau die Lücke, die im Bestand offen war.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ProductionOrderService::class);
    $this->actingAs($this->makeUser($this->rootTeam, 'Küchenchef'));

    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'fond', 'name' => 'Brauner Fond',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 2.0, 'work_time_min' => 60,
    ]);

    $this->order = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Zeilen-Tab-Test', [
        ['source_ref' => 'r:fond', 'recipe_id' => $this->rezept->id, 'amount_kg' => 6.0],
    ]);
    $this->zeile = fn () => Line::where('production_order_id', $this->order->id)
        ->where('recipe_id', $this->rezept->id)->first();

    $this->editor = fn () => Livewire::test(Editor::class)->call('oeffnenBearbeiten', $this->order->id);
});

it('rendert den Zeilen-Tab mit Rezeptname, Ansätzen und Streichen-Knopf', function () {
    ($this->editor)()
        ->assertSee('Brauner Fond')
        ->assertSeeHtml('data-produktion-zeilen')
        ->assertSeeHtml('data-zeile-streichen')
        ->assertSeeHtml('data-freie-position');
});

it('überschreibt Ansätze über die Zeile und zeigt den berechneten Wert weiter an', function () {
    $berechnet = (float) ($this->zeile)()->ansaetze;

    ($this->editor)()->call('zeileAnsaetze', ($this->zeile)()->id, '5')
        ->assertSet('fehler', null);

    $z = ($this->zeile)();
    expect($z->ansaetze_effektiv)->toBe(5.0)
        ->and((float) $z->ansaetze)->toBe($berechnet);

    // Leeres Feld nimmt den Override zurück
    ($this->editor)()->call('zeileAnsaetze', $z->id, '');
    expect(($this->zeile)()->is_manual_ansaetze)->toBeFalse();
});

it('weist unsinnige Ansätze zurück statt sie still als 0 zu speichern', function () {
    ($this->editor)()->call('zeileAnsaetze', ($this->zeile)()->id, 'drei')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Zahl'));

    expect(($this->zeile)()->is_manual_ansaetze)->toBeFalse();
});

it('nimmt Komma als Dezimaltrenner an', function () {
    ($this->editor)()->call('zeileAnsaetze', ($this->zeile)()->id, '2,5')->assertSet('fehler', null);
    expect(($this->zeile)()->ansaetze_effektiv)->toBe(2.5);
});

it('streicht eine Zeile und stellt sie wieder her', function () {
    $lw = ($this->editor)()->call('zeileStreichen', ($this->zeile)()->id, true);
    expect(($this->zeile)()->is_struck)->toBeTrue();
    $lw->assertSee('gestrichen');

    $lw->call('zeileStreichen', ($this->zeile)()->id, false);
    expect(($this->zeile)()->is_struck)->toBeFalse();
});

it('legt eine freie Position an und entfernt sie wieder', function () {
    $lw = ($this->editor)()
        ->set('freiTitel', 'Brot beim Bäcker abholen')
        ->set('freiZeit', '25')
        ->call('freiePositionAnlegen')
        ->assertSet('fehler', null)
        ->assertSet('freiTitel', '')          // Formular geleert
        ->assertSee('Brot beim Bäcker abholen');

    $frei = Line::where('production_order_id', $this->order->id)->where('origin', 'manual')->firstOrFail();
    expect($frei->recipe_id)->toBeNull()->and((int) $frei->arbeitszeit_min)->toBe(25);

    $lw->call('freiePositionLoeschen', $frei->id);
    expect(Line::whereKey($frei->id)->exists())->toBeFalse();
});

it('verlangt einen Titel für die freie Position', function () {
    ($this->editor)()->set('freiTitel', '   ')->call('freiePositionAnlegen')
        ->assertSet('fehler', 'Freie Position braucht einen Titel.');

    expect(Line::where('production_order_id', $this->order->id)->where('origin', 'manual')->count())->toBe(0);
});

it('zeigt die Warnungen des gespeicherten Auftrags — die standen bisher nirgends im Editor', function () {
    // Overlay setzen, dann Ziel entfernen ⇒ verwaistes Overlay erzeugt eine Warnung
    $this->svc->updateLine($this->rootTeam, ($this->zeile)()->id, ['note' => 'wichtig']);
    $this->svc->replaceTargets($this->rootTeam, $this->order->id, []);

    ($this->editor)()
        ->assertSeeHtml('data-produktion-warnungen')
        ->assertSee('Brauner Fond');
});

it('ein laufender Auftrag zeigt die Zeilen, aber ohne Eingriffs-Knöpfe', function () {
    $this->svc->setStatus($this->rootTeam, $this->order->id, ProductionOrderStatus::InProgress);

    ($this->editor)()
        ->assertSee('Brauner Fond')
        ->assertSee('eingefroren')
        ->assertDontSeeHtml('data-zeile-streichen')
        ->assertDontSeeHtml('data-freie-position');
});
