<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Preisvergleich;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistGpLaPreference;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\KostenCacheService;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 Controlling PR-B (Dominique 19.10.): Leitartikel je Mandant = sein Pin im vorhandenen Overlay. io-Verteilung: Stamm global
 * `stamm_paket`, Mandant (rootTeam) mit Opt-in für EINEN Lieferanten und eigenem Preis, Listenpreise beim Master. Gerankt wird mit
 * den Artikeln und Preisen des Mandanten; „Bezugsquelle setzen“ legt für Nicht-Besitzer den Pin fest, der Master den globalen Lead.
 * Der Pin wirkt in den Team-Kosten auch auf ein Rezept des Masters (geerbt/Abo).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    TeamAncestryRegistry::flushAll();

    $this->gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 's85b|butter', 'name' => 'Butter', 'status' => 'approved', 'stamm_class' => StammPaket::PAKET]);
    $mkLief = fn (string $n) => FoodAlchemistSupplier::create(['team_id' => null, 'name' => $n, 'stamm_class' => StammPaket::PAKET]);
    $this->liefA = $mkLief('Großhandel A');
    $this->liefB = $mkLief('Großhandel B');
    $mkLa = function (FoodAlchemistSupplier $l, string $n, float $listenpreis) {
        $la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $l->id, 'designation' => $n, 'qty' => 1.0, 'unit_code' => 'kg', 'stamm_class' => StammPaket::PAKET]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $this->gp->id]);
        app(PriceService::class)->createTeamPrice($this->master, $la, $listenpreis);   // BHG-Konditionen

        return $la;
    };
    $this->laA = $mkLa($this->liefA, 'Butter 1 kg A', 9.0);
    $this->laB = $mkLa($this->liefB, 'Butter 1 kg B', 7.0);
    $this->gp->update(['lead_la_supplier_item_id' => $this->laB->id]);   // Plattform-Lead (Master)

    StammPaket::freischalten($this->rootTeam);
    DB::table(StammPaket::LIEFERANTEN_TABELLE)->insert(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->liefA->id, 'created_at' => now(), 'updated_at' => now()]);
    StammPaket::flush();
    app(PriceService::class)->createTeamPrice($this->rootTeam, $this->laA, 8.0);   // Mandanten-Preis

    $this->mandantAdmin = $this->makeUser($this->rootTeam, 'Mandant Admin', 'owner');
    $this->mandantLeser = $this->makeUser($this->rootTeam, 'Mandant Leser', 'viewer');
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->pin = fn (Team $t) => FoodAlchemistGpLaPreference::where('team_id', $t->id)->where('gp_id', $this->gp->id)->where('gepinnt', true)->value('supplier_item_id');
});

it('Rangliste mit Team-Preisen: nur die Artikel des Mandanten zu seinen Preisen; ohne Team-Sicht wie bisher alle zum Listenpreis', function () {
    $svc = app(LeadLaService::class);
    $team = $svc->ranglisteBulk(collect([$this->gp]), $this->rootTeam, teamPreise: true)[$this->gp->id];
    $liste = $svc->ranglisteBulk(collect([$this->gp]), $this->rootTeam)[$this->gp->id];

    expect($team->pluck('id')->map(fn ($i) => (int) $i)->all())->toBe([(int) $this->laA->id])
        ->and((float) $team->first()->aktiver_preis)->toBe(8.0)
        ->and($liste->pluck('id')->count())->toBe(2);
});

it('Mandant setzt im Preisvergleich seinen Leitartikel (Pin); der Plattform-Lead bleibt; Anzeige „euer Leitartikel“', function () {
    $this->actingAs($this->mandantAdmin);
    Livewire::test(Preisvergleich::class)->call('bezugsquelleSetzen', $this->gp->id, $this->laA->id)
        ->assertSet('fehler', null)->assertSet('hinweis', fn ($h) => str_contains((string) $h, 'Euer Leitartikel'))
        ->set('q', 'Butter')->assertSeeHtml('data-leitartikel="eigen"');

    expect((int) ($this->pin)($this->rootTeam))->toBe((int) $this->laA->id)
        ->and((int) $this->gp->fresh()->lead_la_supplier_item_id)->toBe((int) $this->laB->id);
});

it('Pin nur auf einen für das Team beziehbaren Artikel und nur mit Kuratieren', function () {
    $this->actingAs($this->mandantAdmin);
    expect(fn () => app(LeadLaService::class)->setzeLeitartikel($this->rootTeam, $this->gp, $this->laB->id))->toThrow(RuntimeException::class, 'nicht beziehen');
    $this->actingAs($this->mandantLeser);
    expect(fn () => app(LeadLaService::class)->setzeLeitartikel($this->rootTeam, $this->gp, $this->laA->id))
        ->toThrow(\Platform\FoodAlchemist\Exceptions\FaRechtFehltException::class)
        ->and(($this->pin)($this->rootTeam))->toBeNull();
});

it('Master setzt über dieselbe Aktion den globalen Lead (kein Pin)', function () {
    $this->actingAs($this->kurator);
    expect(app(LeadLaService::class)->setzeLeitartikel($this->master, $this->gp, $this->laA->id))->toBe('global')
        ->and((int) $this->gp->fresh()->lead_la_supplier_item_id)->toBe((int) $this->laA->id)
        ->and(($this->pin)($this->master))->toBeNull();
});

it('der Pin wirkt in den Team-Kosten eines Master-Rezepts (geerbt/Abo): vorher Plattform-Lead, danach der Leitartikel des Mandanten', function () {
    // auch Lieferant B freischalten, mit eigenem Preis — so rechnet „vorher“ eindeutig über den Plattform-Lead B
    DB::table(StammPaket::LIEFERANTEN_TABELLE)->insert(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->liefB->id, 'created_at' => now(), 'updated_at' => now()]);
    StammPaket::flush();
    app(PriceService::class)->createTeamPrice($this->rootTeam, $this->laB, 6.0);
    $rezept = $this->makeRecipe($this->master, 'Buttersauce');
    $this->makeIngredient($rezept, 'Butter', $this->gp, '100');
    $kosten = fn () => app(KostenCacheService::class)->fuer($rezept->fresh(), $this->rootTeam);

    expect(round((float) $kosten()['ek_summe'], 2))->toBe(0.6);   // Plattform-Lead B zum Mandanten-Preis 6,00 €/kg

    $this->actingAs($this->mandantAdmin);
    app(LeadLaService::class)->setzeLeitartikel($this->rootTeam, $this->gp, $this->laA->id);
    $k = $kosten();
    expect($k['vollstaendig'])->toBeTrue()->and(round((float) $k['ek_summe'], 2))->toBe(0.8);   // 100 g × 8,00 €/kg
});
