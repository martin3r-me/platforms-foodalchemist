<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Settings\Einkauf as EinkaufSettings;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\StammLieferantService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\LeadLaRepickTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Lead-Neuwahl mit Vorschau (2026-10-05). Befund: Matrix-/Strategie-Änderungen wählten keinen
 * gespeicherten Lead neu, der Rezept-EK blieb stehen. Jetzt: bewusster Knopf/MCP-Call, der
 * nur eigene GPs anfasst, manuelle Leads und Kandidaten ohne Preis stehen lässt und danach
 * die nutzenden Rezepte neu rechnet.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->leads = app(LeadLaService::class);
    $this->settings = app(TeamSettingsService::class);
    $this->stamm = app(StammLieferantService::class);

    $this->mkGp = function (string $name, $team = null) {
        $gp = $this->makeGp($team ?? $this->rootTeam, $name);
        $gp->update(['commodity_group_code' => '01', 'status' => 'approved', 'requires_la' => true]);

        return $gp->refresh();
    };
    $this->mkLa = function ($gp, string $lieferant, ?float $preis) {
        $supplier = FoodAlchemistSupplier::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => $lieferant]);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
            'designation' => 'LA ' . uniqid(), 'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        if ($preis !== null) {
            FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
        }

        return $la;
    };

    // Ausgangslage wie auf demo: günstigster Preis, Lead = billiger Fremdlieferant.
    $this->settings->update($this->rootTeam, ['lead_la_strategie' => 'guenstigster_preis']);
    $this->gp = ($this->mkGp)('Karotte');
    $this->billig = ($this->mkLa)($this->gp, 'BOS Food', 2.00);
    $this->stammLa = ($this->mkLa)($this->gp, 'Frischeteam Kluth', 3.00);
    $this->leads->applyLeadLa($this->gp, $this->rootTeam);
});

it('Matrix + Strategie allein ändern den Lead nicht — die Vorschau zeigt den Wechsel', function () {
    $this->stamm->setStamm($this->rootTeam, $this->stammLa->supplier_id, '01');
    $this->settings->update($this->rootTeam, ['lead_la_strategie' => 'stamm_lieferant']);

    expect((int) $this->gp->fresh()->lead_la_supplier_item_id)->toBe($this->billig->id);   // bleibt stehen

    $v = $this->leads->repickVorschau($this->rootTeam);
    expect($v['wechsel'])->toHaveCount(1)
        ->and($v['wechsel'][0]['alt_la_id'])->toBe($this->billig->id)
        ->and($v['wechsel'][0]['neu_la_id'])->toBe($this->stammLa->id)
        ->and($v['wechsel'][0]['neu_ist_stamm'])->toBeTrue()
        ->and((int) $this->gp->fresh()->lead_la_supplier_item_id)->toBe($this->billig->id);  // Vorschau schreibt nichts
});

it('Übernehmen setzt den Lead und rechnet den Rezept-EK neu', function () {
    $r = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'repick-ek', 'name' => 'Karottenpüree', 'status' => 'draft']);
    $this->makeIngredient($r, 'Karotte', $this->gp, '1000', 1);
    app(RecipeRecomputeService::class)->recomputePipeline($r->id);
    $ekVorher = (float) $r->fresh()->ek_total_eur;

    $this->stamm->setStamm($this->rootTeam, $this->stammLa->supplier_id, '01');
    $this->settings->update($this->rootTeam, ['lead_la_strategie' => 'stamm_lieferant']);
    $ergebnis = $this->leads->repickAnwenden($this->rootTeam, [$this->gp->id]);

    expect($ergebnis['umgestellt'])->toBe(1)
        ->and((int) $this->gp->fresh()->lead_la_supplier_item_id)->toBe($this->stammLa->id)
        ->and((float) $r->fresh()->ek_total_eur)->toBeGreaterThan($ekVorher);
});

it('manuell gesetzte Leads und Kandidaten ohne Preis bleiben', function () {
    $this->stamm->setStamm($this->rootTeam, $this->stammLa->supplier_id, '01');
    $this->settings->update($this->rootTeam, ['lead_la_strategie' => 'stamm_lieferant']);

    $this->leads->setLeadLa($this->rootTeam, $this->gp, $this->billig->id);          // manuell, ohne Begründung
    $ohnePreisGp = ($this->mkGp)('Pastinake');
    $altLa = ($this->mkLa)($ohnePreisGp, 'BOS Food', 1.50);
    $this->leads->applyLeadLa($ohnePreisGp, $this->rootTeam);
    ($this->mkLa)($ohnePreisGp, 'Frischeteam Kluth', null);                           // Stamm, aber ohne Preis

    $v = $this->leads->repickVorschau($this->rootTeam);
    expect($v['wechsel'])->toBe([])
        ->and($v['manuell_geschuetzt'])->toBe(1)
        ->and((int) $ohnePreisGp->fresh()->lead_la_supplier_item_id)->toBe($altLa->id);

    // Override gelöst → der GP ist wieder Kandidat der Neuwahl.
    $this->leads->setLeadLa($this->rootTeam, $this->gp, null);
    $this->leads->applyLeadLa($this->gp->refresh(), $this->rootTeam);
    $this->settings->update($this->rootTeam, ['lead_la_strategie' => 'guenstigster_preis']);
    $this->leads->applyLeadLa($this->gp->refresh(), $this->rootTeam);
    $this->settings->update($this->rootTeam, ['lead_la_strategie' => 'stamm_lieferant']);
    expect(array_column($this->leads->repickVorschau($this->rootTeam)['wechsel'], 'gp_id'))->toBe([$this->gp->id]);
});

it('fremde (geerbte) GPs tauchen nicht auf', function () {
    $kindGp = ($this->mkGp)('Sellerie', $this->childA);
    ($this->mkLa)($kindGp, 'BOS Food', 1.0);
    ($this->mkLa)($kindGp, 'Frischeteam Kluth', 2.0);
    $this->stamm->setStamm($this->rootTeam, $this->stammLa->supplier_id, '01');
    $this->settings->update($this->rootTeam, ['lead_la_strategie' => 'stamm_lieferant']);

    expect(array_column($this->leads->repickVorschau($this->rootTeam)['wechsel'], 'gp_id'))->not->toContain($kindGp->id);
});

it('UI: Vorschau öffnen, übernehmen, ehrliche Meldung bei Matrix-Änderung', function () {
    $this->settings->update($this->rootTeam, ['lead_la_strategie' => 'stamm_lieferant']);

    $c = Livewire::test(EinkaufSettings::class)
        ->set('stammNeu.01', (string) $this->stammLa->supplier_id)
        ->call('stammSetzen', '01')
        ->assertSet('meldung', fn ($m) => str_contains($m, 'Bestehende Leads bleiben'))
        ->call('repickVorschau')
        ->assertSee('1 würden den Lead wechseln')
        ->assertSet('repickAuswahl', [$this->gp->id])
        ->call('repickUebernehmen')
        ->assertSet('repick', null);

    expect((int) $this->gp->fresh()->lead_la_supplier_item_id)->toBe($this->stammLa->id);
});

it('MCP lead_la.REPICK: dry_run schreibt nichts, dry_run=false übernimmt', function () {
    $this->stamm->setStamm($this->rootTeam, $this->stammLa->supplier_id, '01');
    $this->settings->update($this->rootTeam, ['lead_la_strategie' => 'stamm_lieferant']);
    $ctx = new ToolContext($this->makeUser($this->rootTeam, 'Mcp'), $this->rootTeam);
    $tool = app(LeadLaRepickTool::class);

    $trocken = $tool->execute([], $ctx);
    expect($trocken->success)->toBeTrue()
        ->and($trocken->data['dry_run'])->toBeTrue()
        ->and($trocken->data['wechsel'])->toHaveCount(1)
        ->and((int) $this->gp->fresh()->lead_la_supplier_item_id)->toBe($this->billig->id);

    $echt = $tool->execute(['dry_run' => false, 'warengruppe' => '01'], $ctx);
    expect($echt->success)->toBeTrue()
        ->and($echt->data['umgestellt'])->toBe(1)
        ->and((int) $this->gp->fresh()->lead_la_supplier_item_id)->toBe($this->stammLa->id);
});
