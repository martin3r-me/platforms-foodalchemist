<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\InventurService;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Letzter Preis für eingestellte Artikel (Dominique 27.10.2026): Necta stellt die Preiszeile auf status '2' ohne Preis um, der Wert steht nur noch in der
 * Preis-Historie. Für die Kalkulation zählt dann der letzte bekannte Preis — gekennzeichnet, nie still als aktiver Preis. Ein bestellbarer Artikel mit
 * aktivem Preis gewinnt die Lead-Wahl immer. Bestellung, activeFor und activePriceSubquery bleiben streng.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.bearbeitungssperre' => false]);
    $this->sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
    $this->la = fn (string $name, ?string $lieferstatus = null) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->sup->id, 'designation' => $name, 'qty' => 1.0, 'unit_code' => 'kg',
        'lieferstatus' => $lieferstatus, 'lieferstatus_seit' => $lieferstatus !== null ? '2026-10-14 00:00:00' : null,
    ]);
    // eingestellte Preiszeile (status 2 ohne Preis) wie Necta sie hinterlässt
    $this->eingestellt = function (FoodAlchemistSupplierItem $la) {
        DB::table('foodalchemist_prices')->insert(['uuid' => (string) Str::uuid(), 'supplier_item_id' => $la->id, 'team_id' => $this->rootTeam->id, 'status' => '2',
            'price' => null, 'is_blocked' => false, 'status_valid_from' => '2026-10-14 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
    };
    $this->historie = fn (FoodAlchemistSupplierItem $la, ?float $preis, ?string $ab, string $status = '0', array $extra = []) => DB::table('foodalchemist_price_history')->insert($extra + [
        'uuid' => (string) Str::uuid(), 'price_id' => null, 'supplier_item_id' => $la->id, 'team_id' => $this->rootTeam->id, 'status' => $status,
        'price' => $preis, 'status_valid_from' => $ab, 'is_blocked' => false, 'quelle' => 'necta_historie', 'created_at' => now(), 'updated_at' => now()]);
    $this->gpMit = function (string $name, FoodAlchemistSupplierItem ...$las) {
        $gp = $this->makeGp($this->rootTeam, $name);
        foreach ($las as $la) {
            FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        }
        $gp->update(['lead_la_supplier_item_id' => $las[0]->id, 'requires_la' => true, 'status' => 'approved']);

        return $gp->refresh();
    };
    $this->preise = app(PriceService::class);
});

it('letzter Preis kommt aus der Historie: neuester Normalpreis, keine Aktion, nichts Ausgeblendetes; activeFor bleibt streng', function () {
    $la = ($this->la)('Lachs Seite', 'eingestellt');
    ($this->eingestellt)($la);
    ($this->historie)($la, 11.00, '2026-03-01 00:00:00');
    ($this->historie)($la, 12.50, '2026-05-01 00:00:00');
    ($this->historie)($la, 9.99, '2026-06-01 00:00:00', 'A');                                                        // Aktion zählt nicht
    ($this->historie)($la, 99.00, '2026-07-01 00:00:00', '0', ['ausgeblendet_at' => now(), 'ausgeblendet_preis' => 99.00]);   // Erfassungsfehler

    $lp = $this->preise->letzterPreisFuer($la->id, $this->rootTeam);

    expect($this->preise->activeFor($la->id, $this->rootTeam))->toBeNull()
        ->and($lp['preis'])->toBe(12.5)->and($lp['warnung'])->toBeFalse()
        ->and($lp['text'])->toContain('gültig ab 01.05.2026')->toContain('eingestellt seit 14.10.2026');
});

it('Kennzeichen warnt bei Preisen älter als 12 Monate und ohne Datum; bestellbare Artikel bekommen nie einen letzten Preis', function () {
    $alt = ($this->la)('Alter Artikel', 'eingestellt');
    ($this->historie)($alt, 5.00, '2025-01-10 00:00:00');
    $ohneDatum = ($this->la)('Ohne Datum', 'eingestellt');
    ($this->historie)($ohneDatum, 6.00, null);
    $bestellbar = ($this->la)('Bestellbar');
    ($this->historie)($bestellbar, 7.00, '2026-05-01 00:00:00');

    expect($this->preise->letzterPreisFuer($alt->id, $this->rootTeam)['warnung'])->toBeTrue()
        ->and($this->preise->letzterPreisFuer($alt->id, $this->rootTeam)['text'])->toContain('älter als 12 Monate')
        ->and($this->preise->letzterPreisFuer($ohneDatum->id, $this->rootTeam)['warnung'])->toBeTrue()
        ->and($this->preise->letzterPreisFuer($ohneDatum->id, $this->rootTeam)['text'])->toContain('Datum unbekannt')
        ->and($this->preise->letzterPreisFuer($bestellbar->id, $this->rootTeam))->toBeNull();
});

it('Lead-Wahl: bestellbarer Artikel mit aktivem Preis schlägt den billigeren letzten Preis eines eingestellten; ohne Alternative gewinnt der letzte Preis', function () {
    $eingestellt = ($this->la)('Lachs eingestellt', 'eingestellt');
    ($this->historie)($eingestellt, 10.00, '2026-05-01 00:00:00');
    $bestellbar = ($this->la)('Lachs bestellbar');
    $this->preise->createFor($this->rootTeam, $bestellbar, 20.00);
    $gp = ($this->gpMit)('Lachs', $eingestellt, $bestellbar);

    expect(app(LeadLaService::class)->pickLeadLa($gp, $this->rootTeam))->toBe($bestellbar->id);

    $nurEingestellt = ($this->gpMit)('Forelle', ($e2 = ($this->la)('Forelle eingestellt', 'eingestellt')));
    ($this->historie)($e2, 8.00, '2026-05-01 00:00:00');
    $kette = app(LeadLaService::class)->rangliste($nurEingestellt, $this->rootTeam);
    expect($kette->first()->id)->toBe($e2->id)->and($kette->first()->preis_quelle)->toBe('letzter_eingestellt')
        ->and($kette->first()->hat_aktiven_preis)->toBeFalse();
});

it('GP-Liste, Rezept-EK und Inventur rechnen mit dem letzten Preis und kennzeichnen ihn', function () {
    $la = ($this->la)('Matjes', 'eingestellt');
    ($this->eingestellt)($la);
    ($this->historie)($la, 15.00, '2026-05-01 00:00:00');
    $gp = ($this->gpMit)('Matjesfilet', $la);

    $zeile = app(GpService::class)->paginateBrowser([], $this->rootTeam)->getCollection()->firstWhere('id', $gp->id);
    expect((float) $zeile->lead_preis)->toBe(15.0)->and($zeile->lead_letzter['warnung'])->toBeFalse();

    $r = $this->makeRecipe($this->rootTeam, 'Matjessalat');
    $this->makeIngredient($r, 'Matjes', $gp, '1000');
    app(RecipeRecomputeService::class)->recomputePipeline($r->id);
    expect((float) $r->fresh()->ek_total_eur)->toBe(15.0)->and((int) $r->fresh()->ek_n_letzter_preis)->toBe(1);

    [$preis, $letzter] = app(InventurService::class)->preisJeBasisMitQuelle($this->rootTeam, null, $la->id, 'g');
    expect($preis)->toBe(0.015)->and($letzter)->toBeTrue();
});

it('Bestellung bleibt zu: eingestellte Artikel sind nicht lieferbar, auch mit letztem Preis', function () {
    $la = ($this->la)('Matjes', 'eingestellt');
    ($this->historie)($la, 15.00, '2026-05-01 00:00:00');

    expect(FoodAlchemistSupplierItem::query()->lieferbar()->whereKey($la->id)->exists())->toBeFalse()
        ->and($this->preise->activeFor($la->id, $this->rootTeam))->toBeNull();
});

it('Sicht: ein Historien-Preis eines fremden Mandanten sickert nicht durch', function () {
    $la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => 'Globaler Artikel', 'qty' => 1.0,
        'unit_code' => 'kg', 'lieferstatus' => 'eingestellt', 'lieferstatus_seit' => '2026-10-14 00:00:00']);
    ($this->historie)($la, 30.00, '2026-06-01 00:00:00', '0', ['team_id' => $this->childB->id]);   // Teampreis von Kind B
    ($this->historie)($la, 20.00, '2026-04-01 00:00:00', '0', ['team_id' => null]);                  // Listenpreis

    expect($this->preise->letzterPreisFuer($la->id, $this->childA)['preis'] ?? null)->toBe(20.0)
        ->and($this->preise->letzterPreisFuer($la->id, $this->childB)['preis'] ?? null)->toBe(30.0);
});

it('MCP: gp_lead.GET liefert die Preisquelle des letzten Preises', function () {
    $la = ($this->la)('Forelle eingestellt', 'eingestellt');
    ($this->historie)($la, 8.00, '2026-05-01 00:00:00');
    $gp = ($this->gpMit)('Forelle', $la);
    $user = $this->makeUser($this->rootTeam, 'Einkauf Inhaber');

    $r = app(ToolRegistry::class)->get('foodalchemist.gp_lead.GET')->execute(['gp_id' => $gp->id], new ToolContext($user, $this->rootTeam));
    expect($r->success)->toBeTrue()->and($r->data['lead']['preis_quelle'])->toBe('letzter_eingestellt')
        ->and($r->data['lead']['eingestellt_seit'])->not->toBeNull();
});
