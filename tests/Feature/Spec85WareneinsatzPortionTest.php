<?php

use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition;
use Platform\FoodAlchemist\Services\Trend\BenchmarkService;
use Platform\FoodAlchemist\Services\Rezept\DarreichungResolver;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;
use Platform\FoodAlchemist\Services\Ausgabe\ReportExportService;
use Platform\FoodAlchemist\Services\Rezept\SalesRecipeService;
use Platform\FoodAlchemist\Services\Ausgabe\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 · Wareneinsatz-Maßstab: Wareneinsatz % = EK EINER Portion ÷ VK EINER Portion.
 *
 * Vorher teilten Benchmark, Rezept-Bericht, Cockpit-Rückfall, Verkaufs-Browser, blockPreis (Foodbook/Angebot),
 * Speisekarte und Concepter-Paket den EK des GANZEN Ansatzes durch den Portions-VK. Beispiel (Kurator 19.10.):
 * Ansatz 4 Portionen, EK 8,00 €, VK 5,00 € → richtig 40 % (2,00 ÷ 5,00), vorher 160 %.
 *
 * 98 · 3.5 6a (Dominique 27.11., „Ja, fest 1“): Darreichung = Portion, ein Gericht ist EINE Portion — `sales_unit_count`
 * teilt nicht mehr. Die Fixture ist darum eine Portion zu 2,00 € EK; Maßstab und Prüfwerte (40 % / 30 %) bleiben.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->gericht = $this->makeRecipe($this->rootTeam, 'HG: Vier Portionen', [
        'is_sales_recipe' => true, 'status' => 'approved', 'ek_total_eur' => 2.00,
    ]);
    $sf = FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id], ['label' => 'Unbestimmt']);
    $this->standard = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'sales_net' => 5.00, 'ek_portion' => null,
    ]);
});

it('ekProPortion: ohne ek_portion der EK des Gerichts (eine Portion), ek_portion hat Vorrang', function () {
    $resolver = app(DarreichungResolver::class);
    expect($resolver->ekProPortion($this->gericht->fresh()))->toBe(2.0);

    $this->standard->update(['ek_portion' => 1.50]);
    expect($resolver->ekProPortion($this->gericht->fresh()))->toBe(1.5);
});

it('ekProPortion braucht sales_unit_count nicht mehr: kein Wurf, kein Nachladen, keine Warnung (98 · 3.5 6a)', function () {
    $ohneSpalte = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::query()->select(['id', 'ek_total_eur'])->find($this->gericht->id);
    $this->standard->delete();

    $kanal = Mockery::mock();
    $kanal->shouldNotReceive('warning');
    \Illuminate\Support\Facades\Log::shouldReceive('channel')->andReturnUsing(
        fn (string $c) => $c === \Platform\FoodAlchemist\Support\LangsameAbfragen::KANAL ? $kanal : Mockery::mock()->shouldIgnoreMissing()
    );

    expect(app(DarreichungResolver::class)->ekProPortion($ohneSpalte))->toBe(2.0)
        ->and(array_key_exists('sales_unit_count', $ohneSpalte->getAttributes()))->toBeFalse();
});

it('Benchmark: Teiler fest 1 — ohne ek_portion zählt der Gericht-EK als Portions-EK, sales_unit_count wird ignoriert (98 · 3.5-6a)', function () {
    // Dominique 27.11.: Darreichung = Portion, Teiler fest 1. Das Gericht trägt noch den Spiegel sales_unit_count = 4;
    // vorher teilte der Benchmark 2,00 € ÷ 4 = 0,50 € (10 %), jetzt 2,00 € gegen 5,00 € VK = 40 %.
    $this->gericht->update(['ek_total_eur' => 2.00]);
    expect(app(BenchmarkService::class)->kpisFuerTeam($this->rootTeam->id)['avg_w_pct'])->toEqualWithDelta(40.0, 0.01);

    $this->standard->update(['ek_portion' => 1.50]);   // ek_portion hat Vorrang
    expect(app(BenchmarkService::class)->kpisFuerTeam($this->rootTeam->id)['avg_w_pct'])->toEqualWithDelta(30.0, 0.01);
});

it('Rezept-Bericht: food_cost_percent 40 %, mit ek_portion 30 %', function () {
    $svc = app(ReportExportService::class);
    expect($svc->rezeptDaten($this->rootTeam, $this->gericht->id, [])['recipe']['food_cost_percent'])->toBe(40.0);

    $this->standard->update(['ek_portion' => 1.50]);
    expect($svc->rezeptDaten($this->rootTeam, $this->gericht->id, [])['recipe']['food_cost_percent'])->toBe(30.0);
});

it('VK-Cockpit: Rückfall ohne ek_portion rechnet je Portion (40 %), ek_portion hat Vorrang (30 %)', function () {
    $svc = app(SalesRecipeService::class);
    expect((float) $svc->cockpit($this->gericht->fresh())['marge']['wareneinsatz_pct'])->toEqualWithDelta(40.0, 0.01);

    $this->standard->update(['ek_portion' => 1.50]);
    expect((float) $svc->cockpit($this->gericht->fresh())['marge']['wareneinsatz_pct'])->toEqualWithDelta(30.0, 0.01);
});

it('Foodbook blockPreis: EK und VK je Portion (×Menge), Speisekarte: Wareneinsatz 40 %', function () {
    $block = new FoodAlchemistFoodbookBlock(['type' => 'recipe_ref', 'quantity' => 2, 'price_basis' => 'person']);
    $block->setRelation('dish', $this->gericht->fresh());
    $preis = app(FoodbookService::class)->blockPreis($block);
    expect($preis['vk_pp'])->toBe(10.0)->and($preis['ek_pp'])->toBe(4.0);   // 2 × 5,00 / 2 × 2,00

    $pos = new FoodAlchemistSpeisekartePosition(['type' => 'gericht_ref', 'price_mode' => 'auto']);
    $pos->setRelation('dish', $this->gericht->fresh());
    expect(app(SpeisekarteService::class)->positionEkVk($pos)['we'])->toBe(40.0);
});
