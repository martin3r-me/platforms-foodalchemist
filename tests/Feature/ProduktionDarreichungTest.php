<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungDelta;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Services\PlanungsblattService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 2026-10-07: Produktion rechnet aus der Darreichung, die an der Quelle gewählt ist (Speiseplan,
 * Konzept, Paket, Foodbook), sonst aus der Standard-Darreichung — mit derselben Regel wie der
 * Portions-EK: Grammatur × Anzahl, bei Deltas Gramm je Komponente (weggelassen = 0).
 *
 * Gericht „Bratkartoffeln" = 1000 g Kartoffeln + 200 g Speck (Ansatz 1,2 kg).
 * Formen: Teller 300 g (Standard), Kinder 150 g, „ohne Speck" (Delta: Speck weggelassen).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->blatt = app(PlanungsblattService::class);
    $this->g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->portion = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count', 'default_in_g' => null]);

    $kartoffel = $this->makeGp($this->rootTeam, 'Kartoffeln');
    $speck = $this->makeGp($this->rootTeam, 'Speck');
    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'bratkartoffeln', 'name' => 'Bratkartoffeln',
        'status' => 'approved', 'is_sales_recipe' => true,
    ]);
    $this->gericht->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $kartoffel->id, 'raw_text' => 'Kartoffeln', 'quantity' => 1000, 'unit_vocab_id' => $this->g->id, 'match_method' => 'manual']);
    $this->speckZeile = $this->gericht->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 1, 'gp_id' => $speck->id, 'raw_text' => 'Speck', 'quantity' => 200, 'unit_vocab_id' => $this->g->id, 'match_method' => 'manual']);
    app(RecipeRecomputeService::class)->recomputePipeline($this->gericht->id);
    $this->gericht->refresh()->update(['yield_kg_manual' => 1.2]);   // Ansatz fest 1,2 kg (unabhängig von Verlust-Defaults)

    $form = fn (string $code) => FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => $code, 'label' => ucfirst($code)]);
    $this->teller = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $form('teller')->id, 'is_standard' => true, 'quantity_per_unit_g' => 300, 'unit_count' => 1, 'price_mode' => 'auto']);
    $this->kinder = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $form('kinder')->id, 'is_standard' => false, 'quantity_per_unit_g' => 150, 'unit_count' => 1, 'price_mode' => 'auto']);
    $this->ohneSpeck = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $form('ohne-speck')->id, 'is_standard' => false, 'quantity_per_unit_g' => 250, 'unit_count' => 1, 'price_mode' => 'auto']);
    FoodAlchemistRecipeDarreichungDelta::create(['team_id' => $this->rootTeam->id, 'presentation_id' => $this->ohneSpeck->id, 'recipe_ingredient_id' => $this->speckZeile->id, 'omitted' => true]);

    $this->gpKg = fn (array $blatt) => collect($blatt['gp_bedarf'])->mapWithKeys(fn ($g) => [$g['name'] => $g['menge_kg']])->all();
});

it('Standard-Darreichung: Menge aus deren Grammatur (40 × 300 g = 12 kg Gericht)', function () {
    $b = $this->blatt->produktionsblattFuerZiele($this->rootTeam, [['recipe_id' => $this->gericht->id, 'portions' => 40]]);
    $kg = ($this->gpKg)($b);
    expect($kg['Kartoffeln'])->toBe(10.0)->and($kg['Speck'])->toBe(2.0);
    expect($b['rezepte'][0]['portionen'])->toBe(40);
});

it('gewählte Darreichung im Ziel: Kinderportion 150 g produziert die halbe Menge', function () {
    $b = $this->blatt->produktionsblattFuerZiele($this->rootTeam, [['recipe_id' => $this->gericht->id, 'portions' => 40, 'presentation_id' => $this->kinder->id]]);
    $kg = ($this->gpKg)($b);
    expect($kg['Kartoffeln'])->toBe(5.0)->and($kg['Speck'])->toBe(1.0)
        ->and($b['rezepte'][0]['formen'][0])->toMatchArray(['label' => 'Kinder', 'portionen' => 40, 'gramm' => 150.0]);
});

it('Darreichung mit weggelassener Komponente: Speck 0, Kartoffeln aus der Standard-Komposition je Einheit', function () {
    $b = $this->blatt->produktionsblattFuerZiele($this->rootTeam, [['recipe_id' => $this->gericht->id, 'portions' => 40, 'presentation_id' => $this->ohneSpeck->id]]);
    $kg = ($this->gpKg)($b);
    // Standard je Einheit (300 g): 250 g Kartoffeln + 50 g Speck → ohne Speck: 40 × 250 g = 10 kg.
    expect($kg['Kartoffeln'])->toBe(10.0)->and($kg['Speck'] ?? 0.0)->toBe(0.0);
});

it('mehrere Formen desselben Gerichts werden summiert und einzeln ausgewiesen', function () {
    $b = $this->blatt->produktionsblattFuerZiele($this->rootTeam, [
        ['recipe_id' => $this->gericht->id, 'portions' => 40],
        ['recipe_id' => $this->gericht->id, 'portions' => 20, 'presentation_id' => $this->kinder->id],
    ]);
    $kg = ($this->gpKg)($b);
    expect($kg['Kartoffeln'])->toBe(12.5)                         // 10 + 2,5
        ->and($b['rezepte'][0]['portionen'])->toBe(60)
        ->and(collect($b['rezepte'][0]['formen'])->pluck('portionen', 'label')->all())->toBe(['Teller' => 40, 'Kinder' => 20]);
});

it('fremde Darreichung im Ziel wird ignoriert (Standard)', function () {
    $anderes = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'x', 'name' => 'X', 'status' => 'approved', 'is_sales_recipe' => true]);
    $fremd = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $anderes->id, 'serving_form_id' => $this->kinder->serving_form_id, 'is_standard' => true, 'quantity_per_unit_g' => 10, 'unit_count' => 1, 'price_mode' => 'auto']);
    $b = $this->blatt->produktionsblattFuerZiele($this->rootTeam, [['recipe_id' => $this->gericht->id, 'portions' => 40, 'presentation_id' => $fremd->id]]);
    expect(($this->gpKg)($b)['Kartoffeln'])->toBe(10.0);
});

it('Konzept: Slot-Darreichung greift auch bei Menge in Portionen (vorher nur bei Gramm)', function () {
    $concepts = app(ConceptService::class);
    $concept = $concepts->create($this->rootTeam, ['name' => 'Mittagsmenü']);
    $slot = $concepts->addSlot($this->rootTeam, $concept->id, ['role' => 'Beilage']);
    $slot = $concepts->fillSlot($this->rootTeam, $slot->id, ['sales_recipe_id' => $this->gericht->id]);
    $slot->update(['quantity' => 1, 'unit_vocab_id' => $this->portion->id]);
    $concepts->setSlotDarreichung($this->rootTeam, $slot->id, $this->kinder->id);

    $b = $this->blatt->produktionsblatt($this->rootTeam, ['concept_id' => $concept->id, 'persons' => 20]);
    expect(($this->gpKg)($b)['Kartoffeln'])->toBe(2.5);          // 20 × 150 g ÷ 1200 g × 1000 g
});

it('Speiseplan: Eintrag gibt seine Darreichung an die Produktion, Paket seine Menge und Form je Gericht', function () {
    $svc = app(SpeiseplanService::class);
    $plan = $svc->create($this->rootTeam, ['name' => 'Kantine', 'start_date' => '2026-10-05', 'default_pax' => 100]);
    $eintrag = $svc->addEintrag($this->rootTeam, $plan->id, ['entry_date' => '2026-10-05', 'sales_recipe_id' => $this->gericht->id, 'presentation_id' => $this->kinder->id]);

    $paket = app(PaketService::class)->create($this->rootTeam, ['name' => 'Beilagen-Duo']);
    app(PaketService::class)->syncGerichte($this->rootTeam, $paket->id, [['sales_recipe_id' => $this->gericht->id, 'quantity' => 0.5, 'unit_vocab_id' => $this->portion->id]]);
    $paket->dishes()->first()->update(['presentation_id' => $this->ohneSpeck->id]);
    $paketEintrag = $svc->addEintrag($this->rootTeam, $plan->id, ['entry_date' => '2026-10-05', 'package_id' => $paket->id]);

    $ziele = (new ReflectionMethod(SpeiseplanService::class, 'produktionsZiele'))
        ->invoke($svc, $plan->fresh(['entries', 'lines']), $plan->fresh()->entries, '2026-10-05');
    $byRef = collect($ziele)->keyBy(fn ($z) => str_contains($z['source_ref'], ':d') ? 'paket' : 'gericht');
    expect($byRef['gericht']['presentation_id'])->toBe($this->kinder->id)
        ->and($byRef['gericht']['portions'])->toBe(100)
        ->and($byRef['paket']['presentation_id'])->toBe($this->ohneSpeck->id)
        ->and((float) $byRef['paket']['portions'])->toBe(50.0);   // 0,5 Portion × 100 Pax

    $b = $this->blatt->produktionsblattFuerZiele($this->rootTeam, array_values($ziele));
    $kg = ($this->gpKg)($b);
    // Kinder 100 × 150 g → 12,5 Ansätze → 12,5 kg Kartoffeln, 2,5 kg Speck;
    // Paket ohne Speck 50 × 250 g Kartoffeln = 12,5 kg Kartoffeln, 0 Speck.
    expect($kg['Kartoffeln'])->toBe(25.0)->and($kg['Speck'])->toBe(2.5);
});

it('Form ohne Grammatur rechnet wie bisher über die Portionszahl (keine stille Änderung)', function () {
    $this->teller->update(['quantity_per_unit_g' => null]);
    $this->gericht->update(['sales_unit_count' => 4]);
    $b = $this->blatt->produktionsblattFuerZiele($this->rootTeam, [['recipe_id' => $this->gericht->id, 'portions' => 40]]);
    expect(($this->gpKg)($b)['Kartoffeln'])->toBe(10.0);          // 40 ÷ 4 = 10 Ansätze
});

it('Produktions-Editor: händisches Gericht-Ziel mit Darreichung, zwei Formen = zwei Ziele', function () {
    $lw = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Produktion\Editor::class)->call('oeffnenNeu');

    $formen = $lw->instance()->darreichungenFuer($this->gericht->id);
    expect(collect($formen)->pluck('label')->all())->toBe(['Teller', 'Kinder', 'Ohne-speck'])
        ->and($formen[0])->toMatchArray(['standard' => true, 'gramm' => 300.0]);

    $lw->call('zielEinfuegen', 'recipe', $this->gericht->id, 80, null, null)
        ->call('zielEinfuegen', 'recipe', $this->gericht->id, 40, null, (string) $this->kinder->id);
    $targets = collect($lw->get('targets'));
    expect($targets)->toHaveCount(2)
        ->and($targets->firstWhere('presentation_id', $this->kinder->id)['label'])->toContain('Kinder')
        ->and($targets->whereNull('presentation_id')->first()['portions'])->toEqual(80.0);

    // Re-Add derselben Form ersetzt nur diese (Dedup je Gericht + Form).
    $lw->call('zielEinfuegen', 'recipe', $this->gericht->id, 50, null, (string) $this->kinder->id);
    expect(collect($lw->get('targets')))->toHaveCount(2)
        ->and(collect($lw->get('targets'))->firstWhere('presentation_id', $this->kinder->id)['portions'])->toEqual(50.0);

    // Fremde Darreichung wird verworfen → Standard.
    $lw->call('zielEinfuegen', 'recipe', $this->gericht->id, 10, null, '999999');
    expect(collect($lw->get('targets'))->whereNull('presentation_id')->first()['portions'])->toEqual(10.0);
});
