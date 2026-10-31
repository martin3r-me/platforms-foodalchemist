<?php

use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\LeadLaRepickTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Kurator 29.10.2026 (io: GP „American Dressing“): ein inaktiver oder gesperrter Lieferant ist nie Lead und nie EK-Quelle, solange ein bestellbarer
 * Lieferant den GP beliefert. Nur wenn es sonst nichts gibt, bleibt er Ausweich — gekennzeichnet. Pin/Sperre bleiben harte Team-Entscheidungen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->leads = app(LeadLaService::class);
    $this->settings = app(TeamSettingsService::class);
    $this->settings->update($this->rootTeam, ['lead_la_strategie' => 'guenstigster_preis']);

    $this->lieferant = fn (string $name, array $attrs = []) => FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => $name] + $attrs);
    $this->la = function ($gp, FoodAlchemistSupplier $sup, ?float $preis) {
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'LA ' . uniqid(), 'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        if ($preis !== null) {
            FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
        }

        return $la;
    };
    $this->gp = function (string $name) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(['commodity_group_code' => '01', 'status' => 'approved', 'requires_la' => true]);

        return $gp->refresh();
    };
    $this->inaktiv = ($this->lieferant)('Handelshof (inaktiv)', ['is_inactive' => true]);
    $this->aktiv = ($this->lieferant)('EDI Großhandel');
});

it('inaktiver Lieferant ist trotz günstigerem Preis nie Rang 1; der aktive Zwilling führt', function () {
    $gp = ($this->gp)('American Dressing');
    $alt = ($this->la)($gp, $this->inaktiv, 5.29);
    $neu = ($this->la)($gp, $this->aktiv, 6.50);

    $kette = $this->leads->rangliste($gp, $this->rootTeam);

    expect($kette->first()->id)->toBe($neu->id)
        ->and($kette->first()->lieferant_inaktiv)->toBeFalse()
        ->and($kette->last()->id)->toBe($alt->id)
        ->and($kette->last()->lieferant_inaktiv)->toBeTrue()
        ->and($this->leads->effektiverLead($gp, $this->rootTeam)->id)->toBe($neu->id)
        ->and($this->leads->applyLeadLa($gp, $this->rootTeam))->toBe($neu->id);
});

it('Gegenprobe: ohne die Regel wäre der günstigere inaktive Artikel Lead (beide aktiv ⇒ Preis entscheidet)', function () {
    $gp = ($this->gp)('American Dressing');
    $billig = ($this->la)($gp, ($this->lieferant)('Zweiter aktiver'), 5.29);
    ($this->la)($gp, $this->aktiv, 6.50);

    expect($this->leads->pickLeadLa($gp, $this->rootTeam))->toBe($billig->id);
});

it('Status „gesperrt“ wird wie inaktiv behandelt', function () {
    $gp = ($this->gp)('Senf');
    ($this->la)($gp, ($this->lieferant)('Gesperrter', ['status' => 'gesperrt']), 1.00);
    $ok = ($this->la)($gp, $this->aktiv, 4.00);

    $kette = $this->leads->rangliste($gp, $this->rootTeam);
    expect($kette->first()->id)->toBe($ok->id)->and($kette->last()->lieferant_inaktiv)->toBeTrue();
});

it('inaktiver Stamm-Lieferant gewinnt auch bei Strategie stamm_lieferant nicht', function () {
    $this->settings->update($this->rootTeam, ['lead_la_strategie' => 'stamm_lieferant']);
    app(\Platform\FoodAlchemist\Services\StammLieferantService::class)->setStamm($this->rootTeam, $this->inaktiv->id, '01');
    $gp = ($this->gp)('Essig');
    ($this->la)($gp, $this->inaktiv, 2.00);
    $ok = ($this->la)($gp, $this->aktiv, 3.00);

    expect($this->leads->pickLeadLa($gp, $this->rootTeam))->toBe($ok->id);
});

it('aktiver Artikel ohne Preis schlägt inaktiven mit Preis (Lieferant vor Preis)', function () {
    $gp = ($this->gp)('Öl');
    ($this->la)($gp, $this->inaktiv, 1.00);
    $ohne = ($this->la)($gp, $this->aktiv, null);

    expect($this->leads->pickLeadLa($gp, $this->rootTeam))->toBe($ohne->id);
});

it('Gegenprobe: gibt es NUR inaktive Lieferanten, bleibt der Artikel Lead (Ausweich) und ist gekennzeichnet', function () {
    $gp = ($this->gp)('Rarität');
    $nur = ($this->la)($gp, $this->inaktiv, 9.00);

    $kette = $this->leads->rangliste($gp, $this->rootTeam);
    expect($kette)->toHaveCount(1)
        ->and($kette->first()->id)->toBe($nur->id)
        ->and($kette->first()->lieferant_inaktiv)->toBeTrue();
});

it('Bulk-Rangliste sortiert bit-identisch zum Einzelweg', function () {
    $gp = ($this->gp)('Dressing');
    ($this->la)($gp, $this->inaktiv, 1.00);
    ($this->la)($gp, $this->aktiv, 2.00);

    $bulk = $this->leads->ranglisteBulk(collect([$gp]), $this->rootTeam)[$gp->id];
    expect($bulk->pluck('id')->all())->toBe($this->leads->rangliste($gp, $this->rootTeam)->pluck('id')->all())
        ->and($bulk->pluck('lieferant_inaktiv')->all())->toBe([false, true]);
});

it('Mandanten-Sicht (Kind-Team, Teampreise) wählt ebenfalls den aktiven Lieferanten', function () {
    $this->alleLieferantenFrei($this->childA);
    $gp = ($this->gp)('Dressing');
    ($this->la)($gp, $this->inaktiv, 1.00);
    $ok = ($this->la)($gp, $this->aktiv, 2.00);

    $kette = $this->leads->rangliste($gp, $this->childA, null, true);
    expect($kette->isNotEmpty() ? $kette->first()->id : null)->toBe($ok->id);
});

it('Pin auf einen inaktiven Lieferanten bleibt hart (Team-Entscheidung), ist aber gekennzeichnet', function () {
    $gp = ($this->gp)('Dressing');
    $alt = ($this->la)($gp, $this->inaktiv, 1.00);
    ($this->la)($gp, $this->aktiv, 2.00);
    $this->leads->pinnen($this->rootTeam, $gp, $alt->id);

    $lead = $this->leads->effektiverLead($gp, $this->rootTeam);
    expect($lead->id)->toBe($alt->id)->and($lead->lieferant_inaktiv)->toBeTrue();
});

it('Recompute-EK: Lead bei inaktivem Lieferanten wird übergangen, der aktive Zwilling zählt', function () {
    $gp = ($this->gp)('American Dressing');
    $alt = ($this->la)($gp, $this->inaktiv, 5.29);
    ($this->la)($gp, $this->aktiv, 6.50);
    $gp->update(['lead_la_supplier_item_id' => $alt->id]);   // Bestand wie auf io: stale Lead beim inaktiven Lieferanten
    $r = $this->makeRecipe($this->rootTeam, 'Salat');
    $this->makeIngredient($r, 'American Dressing', $gp->refresh(), '1000');

    app(RecipeRecomputeService::class)->recomputePipeline($r->id);

    expect((float) $r->fresh()->ek_total_eur)->toBe(6.50);
});

it('Recompute-Ersatz folgt der Rangliste (Stamm-Lieferant), nicht dem günstigsten Kandidaten', function () {
    $this->settings->update($this->rootTeam, ['lead_la_strategie' => 'stamm_lieferant']);
    $stamm = ($this->lieferant)('Stamm aktiv');
    app(\Platform\FoodAlchemist\Services\StammLieferantService::class)->setStamm($this->rootTeam, $stamm->id, '01');
    $gp = ($this->gp)('Dressing');
    $alt = ($this->la)($gp, $this->inaktiv, 1.00);
    ($this->la)($gp, $stamm, 8.00);              // Rang 1 (Stamm), teurer
    ($this->la)($gp, $this->aktiv, 3.00);        // Rang 2, günstiger — darf NICHT gewinnen
    $gp->update(['lead_la_supplier_item_id' => $alt->id]);
    $r = $this->makeRecipe($this->rootTeam, 'Salat');
    $this->makeIngredient($r, 'Dressing', $gp->refresh(), '1000');

    app(RecipeRecomputeService::class)->recomputePipeline($r->id);

    expect((float) $r->fresh()->ek_total_eur)->toBe(8.00);
});

it('Gegenprobe Recompute: nur ein inaktiver Lieferant ⇒ sein Preis zählt weiter (Ausweich)', function () {
    $gp = ($this->gp)('Rarität');
    $nur = ($this->la)($gp, $this->inaktiv, 9.00);
    $gp->update(['lead_la_supplier_item_id' => $nur->id]);
    $r = $this->makeRecipe($this->rootTeam, 'Gericht');
    $this->makeIngredient($r, 'Rarität', $gp->refresh(), '1000');

    app(RecipeRecomputeService::class)->recomputePipeline($r->id);

    expect((float) $r->fresh()->ek_total_eur)->toBe(9.00);
});

it('Recompute-EK ohne Lead (Durchschnittspfad) lässt inaktive Lieferanten außen vor', function () {
    $gp = ($this->gp)('Dressing');
    ($this->la)($gp, $this->inaktiv, 1.00);
    ($this->la)($gp, $this->aktiv, 3.00);
    $r = $this->makeRecipe($this->rootTeam, 'Salat');
    $this->makeIngredient($r, 'Dressing', $gp->refresh(), '1000');

    app(RecipeRecomputeService::class)->recomputePipeline($r->id);

    expect((float) $r->fresh()->ek_total_eur)->toBe(3.00);
});

it('Repick-Vorschau nur_inaktive_lieferanten: Wechsel, kein Ersatz, manuell geschützt; aktive Leads bleiben außen vor', function () {
    $mk = function (string $name, $lieferant, float $p) {
        $gp = ($this->gp)($name);
        $la = ($this->la)($gp, $lieferant, $p);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return [$gp->refresh(), $la];
    };
    [$wechsel, $alt] = $mk('A Dressing', $this->inaktiv, 5.29);
    $ersatz = ($this->la)($wechsel, $this->aktiv, 6.50);
    [$ohneErsatz] = $mk('B Rarität', $this->inaktiv, 9.00);
    [$manuell, $manuellLa] = $mk('C Manuell', $this->inaktiv, 1.00);
    ($this->la)($manuell, $this->aktiv, 2.00);
    $this->leads->setLeadLa($this->rootTeam, $manuell, $manuellLa->id);
    [$aktivGp] = $mk('D Aktiv', $this->aktiv, 3.00);

    $v = $this->leads->repickVorschau($this->rootTeam, null, null, true);

    expect($v['geprueft'])->toBe(3)
        ->and($v['wechsel'])->toHaveCount(1)
        ->and($v['wechsel'][0]['gp_id'])->toBe($wechsel->id)
        ->and($v['wechsel'][0]['alt_la_id'])->toBe($alt->id)
        ->and($v['wechsel'][0]['neu_la_id'])->toBe($ersatz->id)
        ->and($v['wechsel'][0]['alt_lieferant_inaktiv'])->toBeTrue()
        ->and($v['kein_aktiver_ersatz'])->toBe(1)
        ->and($v['manuell_geschuetzt'])->toBe(1)
        ->and(array_column($v['wechsel'], 'gp_id'))->not->toContain($aktivGp->id);
    // Vorschau schreibt nichts
    expect((int) $wechsel->fresh()->lead_la_supplier_item_id)->toBe($alt->id);
});

it('MCP lead_la.REPICK mit nur_inaktive_lieferanten: Vorschau gefiltert, dry_run=false stellt nur die gewählten GPs um', function () {
    $gp = ($this->gp)('Dressing');
    $alt = ($this->la)($gp, $this->inaktiv, 5.29);
    $neu = ($this->la)($gp, $this->aktiv, 6.50);
    $gp->update(['lead_la_supplier_item_id' => $alt->id]);
    $ctx = new ToolContext($this->makeUser($this->rootTeam, 'Mcp'), $this->rootTeam);
    $tool = app(LeadLaRepickTool::class);

    $trocken = $tool->execute(['nur_inaktive_lieferanten' => true], $ctx);
    expect($trocken->data['geprueft'])->toBe(1)->and($trocken->data['wechsel'])->toHaveCount(1)
        ->and((int) $gp->fresh()->lead_la_supplier_item_id)->toBe($alt->id);

    $echt = $tool->execute(['nur_inaktive_lieferanten' => true, 'dry_run' => false, 'gp_ids' => [$gp->id]], $ctx);
    expect($echt->data['umgestellt'])->toBe(1)->and((int) $gp->fresh()->lead_la_supplier_item_id)->toBe($neu->id);
});

it('Master-Team erreicht beim Repick auch die globalen Stamm-GPs, ein Mandant nicht (io 31.10.: 0 geprüft)', function () {
    $gp = ($this->gp)('Stamm Dressing');
    $alt = ($this->la)($gp, $this->inaktiv, 5.29);
    $neu = ($this->la)($gp, $this->aktiv, 6.50);
    $gp->update(['team_id' => null, 'lead_la_supplier_item_id' => $alt->id]);

    config(['foodalchemist.master_team_id' => $this->childA->id]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();
    expect($this->leads->repickVorschau($this->rootTeam, null, null, true)['geprueft'])->toBe(0, 'Mandant: globales GP bleibt außen vor');

    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();
    $v = $this->leads->repickVorschau($this->rootTeam, null, null, true);
    expect($v['geprueft'])->toBe(1)->and($v['wechsel'][0]['neu_la_id'])->toBe($neu->id);

    expect($this->leads->repickAnwenden($this->rootTeam, [$gp->id])['umgestellt'])->toBe(1)
        ->and((int) $gp->fresh()->lead_la_supplier_item_id)->toBe($neu->id);
});
