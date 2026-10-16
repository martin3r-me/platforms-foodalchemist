<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\MatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Entscheid Dominique 16.10.2026: von Necta eingestellte Artikel (status 2 ohne Preis, nicht mehr bestellbar) tauchen in den
 * Matching-Vorschlägen nicht mehr auf; „läuft aus“ bleibt vorschlagbar. Im Nährwert-Durchschnitt der GPs bleiben sie (nicht hier).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Kluth']);
    $la = fn (string $name, ?string $status) => FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'designation' => $name, 'article_number' => uniqid(), 'qty' => 1, 'unit_code' => 'kg', 'lieferstatus' => $status,
        'lieferstatus_quelle' => $status === null ? null : 'necta']);
    $this->gelistet = $la('Paprika Mix rot gelb', null);
    $this->auslaufend = $la('Paprika Mix bunt', 'auslaufend');
    $this->eingestellt = $la('Paprika Mix Chinakohl Weisskohl', 'eingestellt');
});

it('Verknüpfen-Suche und GP-Vorschläge bieten eingestellte Artikel nicht an, „läuft aus“ schon', function () {
    $treffer = app(LeadLaService::class)->sucheVerknuepfbare($this->rootTeam, 'Paprika Mix')->pluck('id')->all();
    $gp = $this->makeGp($this->rootTeam, 'Paprika Mix');
    $kandidaten = app(LeadLaService::class)->kandidatenFuerGp($this->rootTeam, $gp, 10)->pluck('id')->all();

    expect($treffer)->toContain($this->gelistet->id)->toContain($this->auslaufend->id)->not->toContain($this->eingestellt->id)
        ->and($kandidaten)->not->toContain($this->eingestellt->id);
});

it('GP-Matching für einen Lieferanten lässt eingestellte Artikel aus', function () {
    $this->makeGp($this->rootTeam, 'Paprika Mix');
    app(MatchService::class)->bulkFuerLieferant($this->rootTeam, $this->supplier->id);

    $vorschlaege = DB::table('foodalchemist_match_proposals')->pluck('supplier_item_id')->map(fn ($v) => (int) $v)->all();
    expect($vorschlaege)->not->toContain($this->eingestellt->id);
});
