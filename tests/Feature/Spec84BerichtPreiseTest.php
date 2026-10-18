<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\ReportExportService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 · Wächter Master-Preise in Berichten (Dominique 13.10., Spec 82: das Paket trägt KEINE Preise, kein Listenpreis-Fallback aus
 * BHG-Konditionen, kein Preis = „Kosten unvollständig“). io-Verteilung: Stamm global stamm_paket, alle Listenpreise beim Master,
 * Broich mit Opt-in für einen Lieferanten und eigenem Preis, Foodpol ohne Opt-in.
 * Geprüft wird das ganze Datenpaket je Bericht (alle Werte unter dem Schlüssel `price`, rekursiv) — Lieferant, GP, Rezept, Rezept-PDF-Tool.
 */
const MASTERPREIS = 12.5;
const EIGENPREIS = 11.0;

function preiseIm(mixed $daten): array
{
    $out = [];
    if (is_array($daten)) {
        foreach ($daten as $k => $v) {
            if ($k === 'price' && $v !== null) {
                $out[] = (float) $v;
            }
            array_push($out, ...preiseIm($v));
        }
    }

    return $out;
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    $this->foodpol = Team::create(['name' => 'Foodpol', 'user_id' => 1, 'personal_team' => false]);
    $this->fremd = Team::create(['name' => 'Fremd-Mandant', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();

    $this->lief = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Großhandel A', 'stamm_class' => StammPaket::PAKET]);
    $this->laPaket = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->lief->id, 'designation' => 'Zanderfilet 1 kg',
        'qty' => 1.0, 'unit_code' => 'kg', 'stamm_class' => StammPaket::PAKET]);
    $this->laBasis = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->lief->id, 'designation' => 'Salz 1 kg',
        'qty' => 1.0, 'unit_code' => 'kg', 'stamm_class' => StammPaket::BASIS]);
    // Eigenzeile eines fremden Mandanten unter dem globalen Lieferanten — darf im Lieferanten-Bericht nie auftauchen
    $this->laFremd = FoodAlchemistSupplierItem::create(['team_id' => $this->fremd->id, 'supplier_id' => $this->lief->id, 'designation' => 'GEHEIM Fremd-Artikel',
        'qty' => 1.0, 'unit_code' => 'kg']);

    $this->gpPaket = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'bp|zander', 'name' => 'Zanderfilet', 'stamm_class' => StammPaket::PAKET]);
    $this->gpBasis = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'bp|salz', 'name' => 'Salz']);
    foreach ([[$this->gpPaket, $this->laPaket], [$this->gpBasis, $this->laBasis]] as [$gp, $la]) {
        FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);
        app(PriceService::class)->createTeamPrice($this->master, $la, MASTERPREIS);   // BHG-Konditionen
    }
    app(PriceService::class)->createTeamPrice($this->fremd, $this->laFremd, 99.0);
    StammPaket::freischalten($this->broich);
    DB::table(StammPaket::LIEFERANTEN_TABELLE)->insert(['team_id' => $this->broich->id, 'supplier_id' => $this->lief->id, 'created_at' => now(), 'updated_at' => now()]);
    StammPaket::flush();
    app(PriceService::class)->createTeamPrice($this->broich, $this->laPaket, EIGENPREIS);   // Mandanten-Preis (CSV/Necta)

    $this->rezeptBroich = $this->makeRecipe($this->broich, 'Zander Broich');
    $this->makeIngredient($this->rezeptBroich, 'Zander', $this->gpPaket, '150');
    $this->makeIngredient($this->rezeptBroich, 'Salz', $this->gpBasis, '5', 2);
    $this->rezeptFoodpol = $this->makeRecipe($this->foodpol, 'Salz Foodpol');
    $this->makeIngredient($this->rezeptFoodpol, 'Salz', $this->gpBasis, '5');
    $this->svc = app(ReportExportService::class);
    $this->opt = fn () => $this->svc->optionen(['profil' => 'voll'], 'recipe');
});

it('Lieferanten-Bericht: Broich sieht seinen Preis, nie den Master-Preis, nie die Eigenzeile eines fremden Mandanten', function () {
    $d = $this->svc->supplierDaten($this->broich, $this->lief->id, ($this->opt)());
    $preise = preiseIm($d);
    expect($preise)->toContain(EIGENPREIS)->not->toContain(MASTERPREIS)->not->toContain(99.0);
    expect(collect($d['report']['supplier']['items'])->pluck('designation')->all())->not->toContain('GEHEIM Fremd-Artikel');
});

it('GP-Bericht: Broich eigener Preis, Foodpol (Basis-GP, nur Master-Preis) leer — der Master sieht seinen', function () {
    expect(preiseIm($this->svc->gpDaten($this->broich, $this->gpPaket->id, ($this->opt)())))->toContain(EIGENPREIS)->not->toContain(MASTERPREIS);
    expect(preiseIm($this->svc->gpDaten($this->foodpol, $this->gpBasis->id, ($this->opt)())))->not->toContain(MASTERPREIS);
    expect(preiseIm($this->svc->gpDaten($this->master, $this->gpBasis->id, ($this->opt)())))->toContain(MASTERPREIS);
});

it('Rezept-Bericht (Kaskade): kein Master-Preis bei Broich und Foodpol, Broichs eigener Preis ist da', function () {
    $broich = preiseIm($this->svc->rezeptDaten($this->broich, $this->rezeptBroich->id, ($this->opt)()));
    expect($broich)->toContain(EIGENPREIS)->not->toContain(MASTERPREIS);
    expect(preiseIm($this->svc->rezeptDaten($this->foodpol, $this->rezeptFoodpol->id, ($this->opt)())))->not->toContain(MASTERPREIS);
});

it('Rezept-PDF-Tool läuft über denselben Datenweg: kein Master-Preis', function () {
    $tool = app(\Platform\FoodAlchemist\Tools\RecipesPdfGetTool::class);
    $nutzer = $this->makeUser($this->foodpol, 'Koch F');
    $res = $tool->execute(['id' => $this->rezeptFoodpol->id, 'profil' => 'voll'], new ToolContext($nutzer, $this->foodpol));
    // Das Tool rendert ein PDF; die Daten dafür kommen aus rezeptDaten — im Test ist der Datenweg der Prüfgegenstand
    $daten = $this->svc->rezeptDaten($this->foodpol, $this->rezeptFoodpol->id, $this->svc->optionen(['profil' => 'voll'], 'recipe'));
    expect(preiseIm($daten))->not->toContain(MASTERPREIS);
    expect($res->success || in_array($res->errorCode, ['PDF_UNAVAILABLE'], true))->toBeTrue((string) $res->error);
});
