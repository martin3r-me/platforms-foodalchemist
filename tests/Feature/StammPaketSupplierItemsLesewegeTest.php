<?php

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\MatchService;
use Platform\FoodAlchemist\Services\SimulationService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §12 A3 / Mandanten-Trennung — die roh lesenden supplier_items-Wege (Auftrag Kurator 13.10.):
 * DataQualityService, GpService (Lead-Join), SimulationService (Scope „lieferant"), MatchService (Brücken über EAN/Artikelnummer).
 * Stamm-Paket ohne Opt-in und Artikel FREMDER Teams dürfen auf diesen Wegen nicht wirken.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    StammPaket::flush();
    $this->sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Hanos']);
    $this->la = fn (?int $team, string $nr, array $extra = []) => FoodAlchemistSupplierItem::create($extra + [
        'team_id' => $team, 'supplier_id' => $this->sup->id, 'designation' => 'LA ' . $nr, 'article_number' => $nr, 'qty' => 2, 'unit_code' => 'kg',
    ]);
    $this->map = fn ($la, $gp) => FoodAlchemistSupplierItemStructure::create(['team_id' => $la->team_id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
});

it('★ DataQuality: ein GP, dessen einzige LA Stamm-Paket ohne Opt-in ist, gilt als „kein LA"', function () {
    $gp = $this->makeGp($this->rootTeam, 'Mehl');
    $gp->update(['status' => 'approved', 'requires_la' => true]);
    ($this->map)(($this->la)(null, 'P1', ['stamm_class' => 'stamm_paket']), $gp);

    $anzahl = fn () => (new ReflectionMethod(DataQualityService::class, 'gpLage'))
        ->invoke(app(DataQualityService::class), $this->rootTeam, 'kein_la')->count();

    expect($anzahl())->toBe(1);
    StammPaket::freischalten($this->rootTeam); $this->alleLieferantenFrei($this->rootTeam);
    expect($anzahl())->toBe(0);
});

it('★ GpService-Browser: Menge/Einheit eines Stamm-Paket-Leads ohne Opt-in bleiben leer', function () {
    $gp = $this->makeGp($this->rootTeam, 'Salz');
    $lead = ($this->la)(null, 'P2', ['stamm_class' => 'stamm_paket']);
    $gp->update(['lead_la_supplier_item_id' => $lead->id]);

    $zeile = fn () => app(GpService::class)->paginateBrowser([], $this->rootTeam)->getCollection()->firstWhere('id', $gp->id);

    expect($zeile()->lead_qty)->toBeNull()->and($zeile()->lead_unit_code)->toBeNull();
    StammPaket::freischalten($this->rootTeam); $this->alleLieferantenFrei($this->rootTeam);
    expect((float) $zeile()->lead_qty)->toBe(2.0)->and($zeile()->lead_unit_code)->toBe('kg');
});

it('★ Simulation „lieferant": ein Stamm-Paket-Lead ohne Opt-in trifft das GP nicht', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zucker');
    $lead = ($this->la)(null, 'P3', ['stamm_class' => 'stamm_paket']);
    $gp->update(['lead_la_supplier_item_id' => $lead->id]);

    $treffer = fn () => (new ReflectionMethod(SimulationService::class, 'gpsFuerScope'))
        ->invoke(app(SimulationService::class), $this->rootTeam, 'lieferant', (string) $this->sup->id);

    expect($treffer())->toBe([]);
    StammPaket::freischalten($this->rootTeam); $this->alleLieferantenFrei($this->rootTeam);
    expect($treffer())->toBe([$gp->id]);
});

it('★ Match-Brücke über EAN: weder Stamm-Paket ohne Opt-in noch ein FREMDER Mandant steuern Treffer bei', function () {
    $gp = $this->makeGp($this->rootTeam, 'Reis');
    $fremd = Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false]);
    $fremdLa = ($this->la)($fremd->id, 'F1', ['ean_packaging' => '4000000000017']);
    $paketLa = ($this->la)(null, 'P4', ['ean_packaging' => '4000000000024', 'stamm_class' => 'stamm_paket']);
    $eigenLa = ($this->la)($this->rootTeam->id, 'E1', ['ean_packaging' => '4000000000031']);
    foreach ([$fremdLa, $paketLa, $eigenLa] as $la) {
        ($this->map)($la, $gp);
    }

    // Spec 84 E-2 (#475): die Brücke lädt nur die EANs der zu prüfenden Items — hier genau diese drei
    $bruecken = fn () => (new ReflectionMethod(MatchService::class, 'exactBruecken'))
        ->invoke(app(MatchService::class), $this->rootTeam, $this->sup->id, collect([$fremdLa, $paketLa, $eigenLa]))[0];
    $eans = fn () => array_map('strval', array_keys($bruecken()));      // numerische String-Schlüssel werden zu int

    expect($eans())->toBe(['4000000000031']);               // nur der eigene Artikel
    StammPaket::freischalten($this->rootTeam); $this->alleLieferantenFrei($this->rootTeam);
    expect($eans())->toContain('4000000000024')->not->toContain('4000000000017');   // Paket jetzt ja, Fremder nie
});
