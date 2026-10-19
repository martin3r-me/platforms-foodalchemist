<?php

use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition;
use Platform\FoodAlchemist\Services\BenchmarkService;
use Platform\FoodAlchemist\Services\DarreichungResolver;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\ReportExportService;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 · Wareneinsatz-Maßstab: Wareneinsatz % = EK EINER Portion ÷ VK EINER Portion.
 *
 * Vorher teilten Benchmark, Rezept-Bericht, Cockpit-Rückfall, Verkaufs-Browser, blockPreis (Foodbook/Angebot),
 * Speisekarte und Concepter-Paket den EK des GANZEN Ansatzes durch den Portions-VK. Beispiel (Kurator 19.10.):
 * Ansatz 4 Portionen, EK 8,00 €, VK 5,00 € → richtig 40 % (2,00 ÷ 5,00), vorher 160 %.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->gericht = $this->makeRecipe($this->rootTeam, 'HG: Vier Portionen', [
        'is_sales_recipe' => true, 'status' => 'approved', 'ek_total_eur' => 8.00, 'sales_unit_count' => 4,
    ]);
    $sf = FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id], ['label' => 'Unbestimmt']);
    $this->standard = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'sales_net' => 5.00, 'ek_portion' => null,
    ]);
});

it('ekProPortion: Ansatz ÷ Portionen ohne ek_portion, ek_portion hat Vorrang', function () {
    $resolver = app(DarreichungResolver::class);
    expect($resolver->ekProPortion($this->gericht->fresh()))->toBe(2.0);

    $this->standard->update(['ek_portion' => 1.50]);
    expect($resolver->ekProPortion($this->gericht->fresh()))->toBe(1.5);
});

it('ekProPortion ohne sales_unit_count im Select: in Tests ein Wurf (Lücke finden)', function () {
    $ohneSpalte = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::query()->select(['id', 'ek_total_eur'])->find($this->gericht->id);
    $this->standard->delete();

    expect(fn () => app(DarreichungResolver::class)->ekProPortion($ohneSpalte))->toThrow(LogicException::class, 'sales_unit_count');
});

it('ekProPortion ohne sales_unit_count im Select: in Produktion nachgeladen (2,00 €) und in fa-langsam gewarnt, kein Wurf', function () {
    config(['foodalchemist.leistung.ek_pro_portion_streng' => false]);   // Produktionsweg
    $ohneSpalte = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::query()->select(['id', 'ek_total_eur'])->find($this->gericht->id);
    $this->standard->delete();

    $kanal = Mockery::mock();
    $kanal->shouldReceive('warning')->once()->withArgs(fn (string $text, array $kontext) => str_contains($text, 'sales_unit_count')
        && $kontext['art'] === 'ek_pro_portion_nachgeladen' && $kontext['recipe_id'] === (int) $this->gericht->id
        && array_key_exists('aufrufer', $kontext));   // aus dem Test direkt aufgerufen: kein src-Frame → null
    \Illuminate\Support\Facades\Log::shouldReceive('channel')->andReturnUsing(
        fn (string $c) => $c === \Platform\FoodAlchemist\Support\LangsameAbfragen::KANAL ? $kanal : Mockery::mock()->shouldIgnoreMissing()
    );

    expect(app(DarreichungResolver::class)->ekProPortion($ohneSpalte))->toBe(2.0);
});

it('Benchmark: Ø Wareneinsatz 40 % statt 160 %', function () {
    expect(app(BenchmarkService::class)->kpisFuerTeam($this->rootTeam->id)['avg_w_pct'])->toEqualWithDelta(40.0, 0.01);
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

it('Wochenauswertung: nachgeladene Spalten erscheinen als eigener Block mit Aufrufer und Anzahl, nicht als langsame Abfrage', function () {
    $kanal = \Platform\FoodAlchemist\Support\LangsameAbfragen::KANAL;
    $logDir = sys_get_temp_dir() . '/fa-langsam-spec85-' . uniqid();
    mkdir($logDir);
    config(['logging.channels.' . $kanal . '.path' => $logDir . '/fa-langsam.log', 'foodalchemist.leistung.ek_pro_portion_streng' => false]);
    \Illuminate\Support\Facades\Log::forgetChannel($kanal);

    try {
        $this->standard->delete();   // kein ek_portion → Rückfall Ansatz ÷ Portionen braucht sales_unit_count
        foreach ([1, 2] as $_) {
            $pos = new FoodAlchemistSpeisekartePosition(['type' => 'gericht_ref', 'price_mode' => 'auto']);
            $pos->setRelation('dish', \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::query()->select(['id', 'ek_total_eur', 'sales_net'])->find($this->gericht->id));
            app(SpeisekarteService::class)->positionEkVk($pos);   // Aufrufer aus src/ → SpeisekarteService
        }

        \Illuminate\Support\Facades\Artisan::call('foodalchemist:langsame-abfragen', ['--tage' => 1]);
        $out = \Illuminate\Support\Facades\Artisan::output();

        expect($out)->toContain('0 langsame Abfragen')
            ->toContain('2 nachgeladene Spalten')
            ->toContain('Nachgeladene Spalten')
            ->toContain('sales_unit_count (EK je Portion)')
            ->toContain('Services/SpeisekarteService.php')
            ->toContain((string) $this->gericht->id)
            ->and($out)->toMatch('/\|\s+2\s+\|\s+sales_unit_count/');
    } finally {
        array_map('unlink', glob($logDir . '/*') ?: []);
        @rmdir($logDir);
        \Illuminate\Support\Facades\Log::forgetChannel($kanal);
    }
});
