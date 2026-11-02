<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfiguratorPaketMenge;
use Platform\FoodAlchemist\Services\KonfiguratorPricingService;
use Platform\FoodAlchemist\Support\KonfiguratorBeispielDoec;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94 · DOEC-Beispiel-Datensatz: Vorlagen, Konfigurator und Pakete entstehen; Mengen folgen den DOEC-Parametern
 * (LKW 1/2/2/3 Touren, Equipment je Gast, Küchenchef fest, F&B-Manager ab 200 Gästen).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('legt den Beispiel-Datensatz an und rechnet Mengen und Preise je DOEC-Paket', function () {
    $culture = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Connecting Culture', 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => 21.83, 'ek_per_person_cache' => 8.73]);
    $k = app(KonfiguratorBeispielDoec::class)->anlegen($this->rootTeam, ['Culture' => ['dinner' => $culture->id]]);

    $leistung = fn (string $teil) => $k->leistungen()->where('bezeichnung', 'like', $teil . '%')->value('id');
    $anzahl = fn (int $paketId, string $teil) => (float) FoodAlchemistKonfiguratorPaketMenge::where('paket_id', $paketId)
        ->where('leistung_id', $leistung($teil))->whereNull('konfigurator_konzept_id')->value('anzahl');
    $pakete = $k->pakete()->get();

    expect($pakete->map(fn ($p) => [$p->von_pax, $p->bis_pax])->all())->toBe(KonfiguratorBeispielDoec::PAKETE)
        ->and($pakete->map(fn ($p) => $anzahl($p->id, 'LKW'))->all())->toBe([1.0, 2.0, 2.0, 3.0])
        ->and($pakete->map(fn ($p) => $anzahl($p->id, 'Equipment'))->all())->toBe([149.0, 349.0, 649.0, 1000.0])
        ->and($pakete->map(fn ($p) => $anzahl($p->id, 'F&B'))->all())->toBe([0.0, 1.0, 1.0, 1.0])
        ->and($pakete->map(fn ($p) => $anzahl($p->id, 'Sous'))->all())->toBe([1.0, 2.0, 2.0, 2.0]);

    $m = app(KonfiguratorPricingService::class)->matrix($k);
    $preise = collect($m['pakete'])->map(fn ($p) => $p['faelle'][0]['preis_pp'])->all();
    // Fixkosten verteilen sich: der Preis je Person sinkt mit der Paketgröße
    expect($preise)->toHaveCount(4)->and($preise[0])->toBeGreaterThan($preise[3])->and($preise[3])->toBeGreaterThan(21.83);
});
