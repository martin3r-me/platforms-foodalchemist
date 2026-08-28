<?php

use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistConformanceFinding;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Schicht 3 · Slice 4c-2 — GP/LA-Konformität als System-Signal (Signale-Cockpit).
 * Die Datenqualitäts-Ampel (DataQualityService) zählt offene Konformitäts-Findings je
 * Artefakt-Typ und emittiert KonformitaetGp/KonformitaetLa. Rezept/VK haben keinen eigenen
 * Typ (zeigen in der Leitstelle).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

$metrik = fn (array $ebenen, string $key) => collect($ebenen)
    ->flatMap(fn ($e) => $e['metriken'])
    ->firstWhere('key', $key);

it('GP-Konformität: DataQuality zählt offene GP-Findings + trägt den Signal-Typ', function () use ($metrik) {
    $gp = $this->makeGp($this->rootTeam, 'Tomaten');
    FoodAlchemistConformanceFinding::create([
        'team_id' => $this->rootTeam->id, 'artifact_type' => 'gp', 'artifact_id' => $gp->id,
        'paragraph' => '§6.1', 'schweregrad' => 'hart', 'feld' => 'name', 'reason' => 'Plural statt Singular',
        'confidence' => 0.9, 'status' => 'offen', 'fingerprint' => 'k-gp-1', 'seen_count' => 1,
    ]);

    $m = $metrik(app(DataQualityService::class)->messeAlleEbenen($this->rootTeam), 'gp_konformitaet');

    expect($m)->not->toBeNull();
    expect($m['wert'])->toBe(1);
    expect($m['signal']['typ'])->toBe(SignalTyp::KonformitaetGp);
});

it('LA-Konformität: DataQuality zählt offene LA-Findings + trägt den Signal-Typ', function () use ($metrik) {
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Testlieferant']);
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => 'Cola 1l',
    ]);
    FoodAlchemistConformanceFinding::create([
        'team_id' => $this->rootTeam->id, 'artifact_type' => 'la', 'artifact_id' => $la->id,
        'paragraph' => '§7', 'schweregrad' => 'weich', 'feld' => 'bezeichnung', 'reason' => 'Gebinde im Namen',
        'confidence' => 0.7, 'status' => 'offen', 'fingerprint' => 'k-la-1', 'seen_count' => 1,
    ]);

    $m = $metrik(app(DataQualityService::class)->messeAlleEbenen($this->rootTeam), 'la_konformitaet');

    expect($m)->not->toBeNull();
    expect($m['wert'])->toBe(1);
    expect($m['signal']['typ'])->toBe(SignalTyp::KonformitaetLa);
});

it('Konformitäts-Signal ist 0 (kein Signal), wenn kein offener Befund existiert', function () use ($metrik) {
    $m = $metrik(app(DataQualityService::class)->messeAlleEbenen($this->rootTeam), 'gp_konformitaet');
    expect($m['wert'])->toBe(0);   // wert=0 → emittiereUndSchliesse schließt/emittiert nicht
});

it('SignalTyp Konformität hat Label + Heroicon (EnumRegistry-Vertrag)', function () {
    foreach ([SignalTyp::KonformitaetGp, SignalTyp::KonformitaetLa] as $t) {
        expect(trim($t->label()))->not->toBe('');
        expect($t->icon())->toStartWith('heroicon-');
    }
});
