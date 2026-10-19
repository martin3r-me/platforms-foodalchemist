<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Exceptions\LeadNurBesitzerException;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Preisvergleich;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\GpLeadPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 Controlling PR-A (Dominique 19.10.): der globale Lead eines Grundprodukts gilt für alle Teams — im angemeldeten Weg
 * setzt ihn nur der Besitzer (`isOwnedBy`) mit Rolle Kuratieren. Vorher konnten Preisvergleich, Wareneinsatz-Batch und
 * `signale.FIX` (nur `visibleToTeam`) den Lead eines geerbten oder globalen GP für alle umstellen. Mandant = rootTeam mit
 * Unter-Team childA; der GP gehört dem Mandanten. Systemwege ohne Anmeldung (Neuwahl-Job, Kommando) bleiben offen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->gp = $this->makeGp($this->rootTeam, 'Butter');
    $lief = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Großhandel', 'status' => 'aktiv']);
    $mkLa = function (string $name, float $preis) use ($lief) {
        $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lief->id, 'gp_id' => $this->gp->id,
            'designation' => $name, 'unit_code' => 'kg', 'qty' => 1]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $this->gp->id]);

        return $la;
    };
    $this->laA = $mkLa('Butter A', 9.0);
    $this->laB = $mkLa('Butter B', 7.0);
    $this->gp->update(['lead_la_supplier_item_id' => $this->laA->id]);
    $this->besitzer = $this->makeUser($this->rootTeam, 'Mandant Admin', 'owner');
    $this->unterteam = $this->makeUser($this->childA, 'Unterteam Admin', 'owner');
    $this->leser = $this->makeUser($this->rootTeam, 'Mandant Leser', 'viewer');
    $this->lead = fn () => (int) $this->gp->fresh()->lead_la_supplier_item_id;
});

it('Preisvergleich: das Unter-Team stellt die Bezugsquelle eines geerbten GP nicht für alle um — es setzt seinen Leitartikel (PR-B)', function () {
    $this->actingAs($this->unterteam);
    Livewire::test(Preisvergleich::class)->call('bezugsquelleSetzen', $this->gp->id, $this->laB->id)
        ->assertSet('fehler', null)->assertSet('hinweis', fn ($h) => str_contains((string) $h, 'Euer Leitartikel'));

    expect(($this->lead)())->toBe((int) $this->laA->id)   // globaler Lead unverändert
        ->and((int) \Platform\FoodAlchemist\Models\FoodAlchemistGpLaPreference::where('team_id', $this->childA->id)->where('gp_id', $this->gp->id)
            ->where('gepinnt', true)->value('supplier_item_id'))->toBe((int) $this->laB->id);
});

it('Preisvergleich: der Besitzer (Mandant, Kuratieren) stellt um', function () {
    $this->actingAs($this->besitzer);
    Livewire::test(Preisvergleich::class)->call('bezugsquelleSetzen', $this->gp->id, $this->laB->id)->assertSet('fehler', null);

    expect(($this->lead)())->toBe((int) $this->laB->id);
});

it('zentraler Riegel im Dienst: Unter-Team → LeadNurBesitzer, Leser des Besitzers → Rolle fehlt; Lead unverändert', function () {
    $svc = app(LeadLaService::class);
    $this->actingAs($this->unterteam);
    expect(fn () => $svc->setLeadLa($this->childA, $this->gp, $this->laB->id, 'Batch'))->toThrow(LeadNurBesitzerException::class);
    $this->actingAs($this->leser);
    expect(fn () => $svc->setLeadLa($this->rootTeam, $this->gp, $this->laB->id, 'Batch'))->toThrow(FaRechtFehltException::class)
        ->and(($this->lead)())->toBe((int) $this->laA->id);
});

it('Systemweg ohne Anmeldung (Neuwahl-Job, Kommando) bleibt offen', function () {
    app(LeadLaService::class)->setLeadLa($this->childA, $this->gp, $this->laB->id, 'System');

    expect(($this->lead)())->toBe((int) $this->laB->id);
});

it('MCP gp_lead.PUT aus dem Unter-Team: abgelehnt, Lead unverändert', function () {
    $this->actingAs($this->unterteam);
    $r = app(GpLeadPutTool::class)->execute(['gp_id' => $this->gp->id, 'la_id' => $this->laB->id], new ToolContext($this->unterteam, $this->childA));

    expect($r->success)->toBeFalse()->and(($this->lead)())->toBe((int) $this->laA->id);
});

it('globaler BASIS-GP: der Master (Kuratieren) setzt den Lead, ein Mandant nie (Dominique 18.10.)', function () {
    $master = \Platform\Core\Models\Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $master->id]);
    \Platform\FoodAlchemist\Support\TeamAncestryRegistry::flushAll();
    $basis = \Platform\FoodAlchemist\Models\FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 's85|oel', 'name' => 'Rapsöl']);   // stamm_class basis
    $lief = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Stamm-Großhandel']);
    $las = collect(['Rapsöl 10 l', 'Rapsöl 5 l'])->map(function ($n) use ($lief, $basis) {
        $la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $lief->id, 'designation' => $n, 'unit_code' => 'l', 'qty' => 1]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $basis->id]);

        return $la;
    });
    $basis->update(['lead_la_supplier_item_id' => $las[0]->id]);
    $svc = app(LeadLaService::class);

    $this->actingAs($this->besitzer);   // Mandant (rootTeam) — besitzt den globalen GP nicht
    expect(fn () => $svc->setLeadLa($this->rootTeam, $basis->fresh(), $las[1]->id))->toThrow(LeadNurBesitzerException::class)
        ->and(LeadLaService::darfGlobalenLead($this->rootTeam, $basis))->toBeFalse();

    $this->actingAs($this->makeUser($master, 'Kurator', 'owner'));
    $svc->setLeadLa($master, $basis->fresh(), $las[1]->id);
    expect((int) $basis->fresh()->lead_la_supplier_item_id)->toBe((int) $las[1]->id)
        ->and($basis->fresh()->isOwnedBy($master))->toBeFalse();   // isOwnedBy/Curate unverändert — nur der Lead-Weg kennt die Ausnahme
});
