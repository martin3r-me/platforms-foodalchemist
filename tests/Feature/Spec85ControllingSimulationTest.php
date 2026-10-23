<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Kalkulation\Simulation;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\SimulationService;
use Platform\FoodAlchemist\Services\StandortService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 Controlling PR-F (Dominique 19.10.): die Simulation rechnet mit der Sicht des BETRACHTERS — GP-Auswahl über alles, was das Team sieht (auch globale
 * Stamm-Paket-GPs), wirksamer Lead inklusive seines Pins, Kosten mit den Preisen des betrachtenden Teams (bei Rezepten eines Standorts der Brille mit
 * dessen eigenen), Rezepte nach Team-Brille. io-Verteilung: Stamm global `stamm_paket`; Mandant und Standort A mit Opt-in beider Lieferanten und eigenen
 * Preisen; Plattform-Lead = Lieferant B.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id, 'foodalchemist.team_sicht.stufe' => 4]);
    $this->rootTeam->update(['parent_team_id' => $this->master->id]);
    $this->fremd = Team::create(['name' => 'Fremder Mandant', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->master->id]);
    TeamAncestryRegistry::flushAll();

    $this->gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 's85f|butter', 'name' => 'Butter', 'status' => 'approved', 'stamm_class' => StammPaket::PAKET, 'commodity_group_code' => 'MOPRO']);
    $this->fremdGp = FoodAlchemistGp::create(['team_id' => $this->fremd->id, 'gp_key' => 's85f|geheim', 'name' => 'Geheimzutat', 'status' => 'approved']);
    $mkLief = fn (string $n) => FoodAlchemistSupplier::create(['team_id' => null, 'name' => $n, 'stamm_class' => StammPaket::PAKET]);
    $this->liefA = $mkLief('Großhandel A');
    $this->liefB = $mkLief('Großhandel B');
    $mkLa = function (FoodAlchemistSupplier $l, string $n) {
        $la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $l->id, 'designation' => $n, 'qty' => 1.0, 'unit_code' => 'kg', 'stamm_class' => StammPaket::PAKET]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $this->gp->id]);
        app(PriceService::class)->createTeamPrice($this->master, $la, $n === 'Butter A' ? 9.0 : 7.0);   // BHG-Konditionen

        return $la;
    };
    $this->laA = $mkLa($this->liefA, 'Butter A');
    $this->laB = $mkLa($this->liefB, 'Butter B');
    $this->gp->update(['lead_la_supplier_item_id' => $this->laB->id]);   // Plattform-Lead

    // Mandant: A 8,00 / B 6,00, beide Lieferanten freigeschaltet. Standorte erben Preise und Freischaltung ihres Oberteams (Spec 82 §3: keine eigenen Preise je Unter-Team).
    foreach ([[$this->rootTeam, 8.0, 6.0], [$this->childA, null, null]] as [$t, $preisA, $preisB]) {
        StammPaket::freischalten($t);
        foreach ([$this->liefA, $this->liefB] as $l) {
            DB::table(StammPaket::LIEFERANTEN_TABELLE)->insert(['team_id' => $t->id, 'supplier_id' => $l->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($preisA !== null) {   // Preise nur am Mandanten; der Standort erbt sie über die Kette
            app(PriceService::class)->createTeamPrice($t, $this->laA, $preisA);
            app(PriceService::class)->createTeamPrice($t, $this->laB, $preisB);
        }
    }
    StammPaket::flush();

    // Gericht des Masters (geerbt/Abo) und Gericht von Standort A, je 100 g Butter, VK 10,00 €
    $gericht = function (Team $t, string $name) {
        $r = $this->makeRecipe($t, $name, ['is_sales_recipe' => true, 'status' => 'approved', 'sales_unit_count' => 1]);
        $this->makeIngredient($r, 'Butter', $this->gp, '100');
        $sf = FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $t->id], ['label' => 'Unbestimmt']);
        FoodAlchemistRecipeDarreichung::create(['team_id' => $t->id, 'recipe_id' => $r->id, 'serving_form_id' => $sf->id, 'is_standard' => true, 'sales_net' => 10.0]);

        return $r;
    };
    // Fremder Mandant: eigenes Gericht mit derselben Butter und eigenen Preisen — darf in KEINER Sicht des Mandanten auftauchen (Mandanten-Grenze)
    StammPaket::freischalten($this->fremd);
    foreach ([$this->liefA, $this->liefB] as $l) {
        DB::table(StammPaket::LIEFERANTEN_TABELLE)->insert(['team_id' => $this->fremd->id, 'supplier_id' => $l->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    app(PriceService::class)->createTeamPrice($this->fremd, $this->laA, 5.0);
    app(PriceService::class)->createTeamPrice($this->fremd, $this->laB, 5.0);
    StammPaket::flush();
    $this->masterGericht = $gericht($this->master, 'Buttersauce Master');
    $this->standortGericht = $gericht($this->childA, 'Buttersauce Standort A');
    $this->fremdGericht = $gericht($this->fremd, 'Buttersauce Fremder Mandant');

    $this->inhaber = $this->makeUser($this->rootTeam, 'Mandant Inhaber', 'owner');
    $this->actingAs($this->inhaber);
    $this->sim = app(SimulationService::class);
    $this->brille = fn (string $modus, ?Team $t = null) => app(StandortService::class)->setzeBrille($this->rootTeam, $modus, $t?->id);
});

it('GP-Auswahl: auch globale Stamm-Paket-GPs (Warengruppe, Lieferant); ein GP eines fremden Mandanten ist nicht wählbar', function () {
    expect($this->sim->simuliere($this->rootTeam, 'gp', (string) $this->gp->id, 10)['n_gps'])->toBe(1)
        ->and($this->sim->simuliere($this->rootTeam, 'warengruppe', 'MOPRO', 10)['n_gps'])->toBe(1)
        ->and($this->sim->simuliere($this->rootTeam, 'lieferant', (string) $this->liefB->id, 10)['n_gps'])->toBe(1)   // Plattform-Lead liegt bei B
        ->and($this->sim->simuliere($this->rootTeam, 'gp', (string) $this->fremdGp->id, 10)['n_gps'])->toBe(0);       // Sichtbarkeitsprüfung
});

it('wirksamer Lead: nach dem Pin des Mandanten gehört der GP zu SEINEM Lieferanten, nicht mehr zum Plattform-Lead', function () {
    expect($this->sim->simuliere($this->rootTeam, 'artikel', (string) $this->laB->id, 10)['n_gps'])->toBe(1)
        ->and($this->sim->simuliere($this->rootTeam, 'artikel', (string) $this->laA->id, 10)['n_gps'])->toBe(0);

    app(LeadLaService::class)->setzeLeitartikel($this->rootTeam, $this->gp, $this->laA->id);   // Pin A

    expect($this->sim->simuliere($this->rootTeam, 'artikel', (string) $this->laA->id, 10)['n_gps'])->toBe(1)
        ->and($this->sim->simuliere($this->rootTeam, 'artikel', (string) $this->laB->id, 10)['n_gps'])->toBe(0)
        ->and($this->sim->simuliere($this->rootTeam, 'lieferant', (string) $this->liefA->id, 10)['n_gps'])->toBe(1)
        ->and($this->sim->simuliere($this->rootTeam, 'lieferant', (string) $this->liefB->id, 10)['n_gps'])->toBe(0);
    expect((int) $this->gp->fresh()->lead_la_supplier_item_id)->toBe((int) $this->laB->id);   // der Plattform-Lead bleibt
});

it('Preise des Betrachters: das Master-Gericht kostet im Szenario Butter zum Mandanten-Preis (6,00 €/kg), nicht zu BHG-Konditionen (7,00 €/kg)', function () {
    ($this->brille)('eigen');
    $r = $this->sim->simuliere($this->rootTeam, 'gp', (string) $this->gp->id, 20);

    // 100 g × 6,00 €/kg = 0,60 € je Portion; +20 % → −0,12 € Marge (mit BHG-Preis wären es −0,14 €)
    expect($r['n_gerichte'])->toBe(1)->and($r['marge_delta_eur'])->toBe(-0.12);
});

it('Pin wirkt in der Simulation: mit dem Leitartikel A (8,00 €/kg) rechnet das Master-Gericht 0,80 € je Portion', function () {
    app(LeadLaService::class)->setzeLeitartikel($this->rootTeam, $this->gp, $this->laA->id);
    ($this->brille)('eigen');

    expect($this->sim->simuliere($this->rootTeam, 'gp', (string) $this->gp->id, 20)['marge_delta_eur'])->toBe(-0.16);   // 0,80 € × 20 %
});

it('Team-Brille: „alle“ nimmt die Gerichte der Standorte dazu (zu den Preisen, die der Standort vom Mandanten erbt); „ein Standort“ nur dessen; „eigen“ keine', function () {
    ($this->brille)('alle');
    $r = $this->sim->simuliere($this->rootTeam, 'gp', (string) $this->gp->id, 20);
    $je = collect($r['top'])->keyBy('name');

    expect($r['n_gerichte'])->toBe(2)->and($je->has('Buttersauce Fremder Mandant'))->toBeFalse()
        ->and($je['Buttersauce Master']['marge_delta_eur'])->toBe(-0.12)         // Mandant-Preis 6,00
        ->and($je['Buttersauce Standort A']['marge_delta_eur'])->toBe(-0.12)     // Standort erbt die Mandanten-Preise: 0,60 € × 20 %
        ->and($r['marge_delta_eur'])->toBe(-0.24);

    ($this->brille)('team', $this->childA);
    $a = $this->sim->simuliere($this->rootTeam, 'gp', (string) $this->gp->id, 20);
    expect(collect($a['top'])->pluck('name')->all())->toContain('Buttersauce Standort A');

    ($this->brille)('eigen');
    expect(collect($this->sim->simuliere($this->rootTeam, 'gp', (string) $this->gp->id, 20)['top'])->pluck('name')->all())->not->toContain('Buttersauce Standort A');
});

it('Oberfläche: Team über FaTeam, GP-Schnellsuche findet den globalen Stamm-GP, nicht den des fremden Mandanten', function () {
    Livewire::test(Simulation::class)->set('scope', 'gp')->set('gpQuery', 'Butter')
        ->assertViewHas('gpTreffer', fn ($t) => count($t) === 1 && $t[0]['name'] === 'Butter');
    Livewire::test(Simulation::class)->set('scope', 'gp')->set('gpQuery', 'Geheim')->assertViewHas('gpTreffer', []);

    expect(file_get_contents(dirname(__DIR__, 2).'/src/Livewire/Kalkulation/Simulation.php'))->not->toContain('currentTeamRelation');
});
