<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as FoodbooksIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Leitstelle-Kaskade: Foodbook → Konzept (erbt Leitplanken: concept.level aus Foodbook-Niveau)
 * → passende Gerichte (Bestand). Plus der gated „neu"-Zweig (KI, braucht Provider).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->fbSvc = app(FoodbookService::class);
    $this->frames = app(PlanningFrameService::class);

    $g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $hg = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'HG', 'label' => 'Hauptgericht']);
    $klasse = FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'dish_main_group_id' => $hg->id, 'code' => 'HG_N', 'label' => 'Neutral', 'diet_form' => 'neutral']);
    $gp = $this->makeGp($this->rootTeam, 'Tomate');
    $this->dish = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'r1', 'name' => 'HG: Tomaten-Teller', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 12.00, 'dish_class_id' => $klasse->id,
    ]);
    $this->dish->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $gp->id, 'raw_text' => 'Tomate', 'quantity' => 100, 'unit_vocab_id' => $g->id]);

    // Foodbook mit Leitplanke default_niveau = haute_cuisine (kanonisch) + Gerüst mit einem Slot.
    $this->fb = $this->fbSvc->create($this->rootTeam, ['label' => 'Leitstelle-FB']);
    $this->fbSvc->update($this->rootTeam, $this->fb->id, ['default_niveau' => 'haute_cuisine']);
    $this->frame = $this->frames->frameFor($this->rootTeam, 'foodbook', $this->fb->id);
    $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1]);
    $this->fbSvc->strukturAusGeruest($this->rootTeam, $this->fb->id); // setzt slot.chapter_id
    $this->slot = $this->frame->refresh()->slots->first();
});

it('übernehmen erzeugt ein Konzept, das das Foodbook-Niveau erbt (haute_cuisine → concept.level haute)', function () {
    $res = $this->fbSvc->uebernehmeVorschlag($this->rootTeam, $this->fb->id, $this->slot->id, $this->dish->id);

    $concept = FoodAlchemistConcept::find($res['concept_id']);
    expect($concept)->not->toBeNull()
        ->and($concept->level)->toBe('haute')           // denormalisiert fürs Concepter-Vokabular
        ->and($concept->created_via)->toBe('foodbook_slot');
});

it('slotFuellen(bestand) legt das Konzept an + füllt es mit passenden Bestands-Gerichten', function () {
    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('slotFuellen', $this->slot->id, 'bestand')
        ->assertSet('slotFuellStatus.' . $this->slot->id, fn ($v) => is_string($v) && str_contains($v, 'gefüllt'));

    // Kapitel-Konzept existiert + trägt das Gericht als Slot.
    $block = $this->fb->refresh()->chapters->first()->blocks->firstWhere('type', 'concept_ref');
    expect($block)->not->toBeNull();
    $slots = FoodAlchemistConceptSlot::where('concept_id', $block->concept_id)->where('sales_recipe_id', $this->dish->id)->count();
    expect($slots)->toBe(1);
});

it('slotFuellen(neu) ist gated (Provider-Hinweis, kein Gericht übernommen)', function () {
    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('slotFuellen', $this->slot->id, 'neu')
        ->assertSet('slotFuellStatus.' . $this->slot->id, fn ($v) => is_string($v) && str_contains($v, 'Provider'));

    // Kein Konzept-Slot mit dem Gericht angelegt.
    expect(FoodAlchemistConceptSlot::where('sales_recipe_id', $this->dish->id)->count())->toBe(0);
});

// ── E1.5 kapitelweite Dedup (Konzept-Slots ∪ recipe_ref-Blöcke) ──────────────

it('zweimaliges Übernehmen desselben Gerichts meldet schon_drin + legt nur einen Konzept-Slot an', function () {
    $a = $this->fbSvc->uebernehmeVorschlag($this->rootTeam, $this->fb->id, $this->slot->id, $this->dish->id);
    $b = $this->fbSvc->uebernehmeVorschlag($this->rootTeam, $this->fb->id, $this->slot->id, $this->dish->id);

    expect($a['schon_drin'])->toBeFalse()
        ->and($b['schon_drin'])->toBeTrue()
        ->and($b['concept_id'])->toBe($a['concept_id']);   // führendes Kapitel-Konzept zurückgegeben
    expect(FoodAlchemistConceptSlot::where('concept_id', $a['concept_id'])->where('sales_recipe_id', $this->dish->id)->count())->toBe(1);
});

it('Gericht bereits als recipe_ref-Block im Kapitel → Übernehmen dedupt kapitelweit (kein Konzept, kein Slot)', function () {
    // Einzel-Weg: Gericht liegt schon als recipe_ref direkt am Kapitel.
    $chapterId = (int) $this->slot->refresh()->chapter_id;
    $this->fbSvc->addBlock($this->rootTeam, $chapterId, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->dish->id]);

    $res = $this->fbSvc->uebernehmeVorschlag($this->rootTeam, $this->fb->id, $this->slot->id, $this->dish->id);

    expect($res['schon_drin'])->toBeTrue();
    // Union-Dedup greift VOR jeder Anlage: weder Konzept-Block noch Konzept-Slot entstehen.
    $kapitel = $this->fb->refresh()->chapters->firstWhere('id', $chapterId);
    expect($kapitel->blocks->where('type', 'concept_ref')->count())->toBe(0);
    expect(FoodAlchemistConceptSlot::where('sales_recipe_id', $this->dish->id)->count())->toBe(0);
});
