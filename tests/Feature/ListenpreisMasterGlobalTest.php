<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Bugfix io 27.10.2026 (Akazienhonig): Die GP-Detail-Kette liest Preise ohne Team (Listenpreis-Sicht). Auf io sind die Artikel global (team_id NULL,
 * stamm_paket), die Necta-Preise liegen im Master-Team. Vorher trafen dort weder der Listenpreis (team_id NULL oder = Besitzer) noch der Stamm-Filter
 * (blendete stamm_paket ohne Team aus) — „Preis fehlt“ bei allen Artikeln. Jetzt: Master-Preis = Listenpreis globaler Artikel; Mandantenpreise nie.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    MasterTeam::flush();
    $this->sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Delta Fleisch', 'stamm_class' => 'stamm_paket']);
    $this->la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => 'Akazienhonig, GL (500 GR)',
        'article_number' => 'D1', 'stamm_class' => 'stamm_paket', 'qty' => 0.5, 'unit_code' => 'kg']);
    $this->preis = fn (?int $teamId, float $preis) => DB::table('foodalchemist_prices')->insert(['uuid' => (string) Str::uuid(), 'supplier_item_id' => $this->la->id,
        'team_id' => $teamId, 'status' => '0', 'price' => $preis, 'is_blocked' => false, 'status_valid_from' => '2026-10-22 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
    $this->preise = app(PriceService::class);
});

it('globaler Stamm-Artikel mit Master-Preis: Listenpreis-Sicht (ohne Team) und Subquery finden ihn', function () {
    ($this->preis)($this->rootTeam->id, 9.95);

    $sub = FoodAlchemistSupplierItem::query()->whereKey($this->la->id)
        ->select('foodalchemist_supplier_items.id')->selectSub($this->preise->activePriceSubquery('foodalchemist_supplier_items.id', null)->toBase(), 'p')->value('p');

    expect((float) $this->preise->activeFor($this->la->id)?->price)->toBe(9.95)
        ->and((float) $sub)->toBe(9.95);
});

it('GP-Detail-Kette (rangliste ohne Teampreise) zeigt den Master-Preis statt „Preis fehlt“', function () {
    ($this->preis)($this->rootTeam->id, 9.95);
    $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'akazienhonig|trocken', 'name' => 'Akazienhonig: trocken', 'stamm_class' => 'stamm_paket']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $this->la->id, 'gp_id' => $gp->id]);

    $kette = app(LeadLaService::class)->rangliste($gp, $this->rootTeam);

    expect($kette->first()->id)->toBe($this->la->id)->and((float) $kette->first()->aktiver_preis)->toBe(9.95)->and($kette->first()->hat_aktiven_preis)->toBeTrue();
});

it('Mandanten-Teampreise sickern nicht in die Listenpreis-Sicht; der Master-Preis nicht in fremde Team-Sichten', function () {
    ($this->preis)($this->childA->id, 5.00);   // Teampreis von Kind A

    expect($this->preise->activeFor($this->la->id))->toBeNull();                          // Listenpreis-Sicht: kein Mandantenpreis

    ($this->preis)($this->rootTeam->id, 9.95);
    expect((float) $this->preise->activeFor($this->la->id)?->price)->toBe(9.95)          // Master-Listenpreis
        ->and((float) $this->preise->activeFor($this->la->id, $this->childA)?->price)->toBe(5.0)    // eigener Teampreis gewinnt in der eigenen Sicht
        ->and($this->preise->activeFor($this->la->id, $this->childB))->toBeNull();               // Master-Preis (BHG-Konditionen) nie in einer Mandanten-Sicht
});

it('ohne konfiguriertes Master-Team bleibt alles wie vorher (kein Master-Listenpreis)', function () {
    ($this->preis)($this->rootTeam->id, 9.95);
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();

    expect($this->preise->activeFor($this->la->id))->toBeNull();
});

it('Mandant sieht keinen Master-Preis: Ersatzvorschläge (Pairing) zeigen den EK nur in der Team-Sicht', function () {
    ($this->preis)($this->rootTeam->id, 9.95);   // Master-Preis am globalen Artikel
    $quelle = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'akazienhonig|trocken', 'name' => 'Akazienhonig: trocken', 'stamm_class' => 'stamm_paket',
        'lead_la_supplier_item_id' => $this->la->id, 'commodity_group_code' => 'HON']);
    $methode = (new ReflectionClass(\Platform\FoodAlchemist\Services\PairingService::class))->getMethod('gpLeadListenEk');
    $methode->setAccessible(true);
    $ek = fn ($team) => $methode->invoke(app(\Platform\FoodAlchemist\Services\PairingService::class), (int) $quelle->lead_la_supplier_item_id, $team);

    expect($ek($this->rootTeam))->toBe(9.95)       // Master sieht seinen Preis
        ->and($ek($this->childA))->toBeNull();      // Mandant: kein Master-Preis
});

it('Mandant sieht keinen Master-Preis: Preissprung-Signal rechnet Trend und Alternative in der Team-Sicht', function () {
    // zwei Preis-Generationen nur im Master-Team: 10 → 13 (+30 %)
    DB::table('foodalchemist_prices')->insert(['uuid' => (string) Str::uuid(), 'supplier_item_id' => $this->la->id, 'team_id' => $this->rootTeam->id, 'status' => '0',
        'price' => 10.0, 'is_blocked' => false, 'valid_to' => now(), 'created_at' => now()->subDay(), 'updated_at' => now()]);
    ($this->preis)($this->rootTeam->id, 13.0);
    $ps = $this->preise;

    expect($ps->preisTrendBulk([$this->la->id], $this->rootTeam))->toHaveKey($this->la->id)   // Master: Trend sichtbar
        ->and($ps->preisTrendBulk([$this->la->id], $this->childA))->toBe([]);                // Mandant: nichts vom Master

    $quelleDetektor = file_get_contents((new ReflectionClass(\Platform\FoodAlchemist\Services\SignalDetektorService::class))->getFileName());
    expect($quelleDetektor)->toContain("preisTrendBulk(array_keys(\$recent->all()), \$team)")
        ->toContain("activePriceSubquery('i.id', \$team)");   // Wächter: beide Leselinien im Preissprung-Signal tragen das Team
});
