<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as FoodbooksIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
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

// ── E7.2 uebernehmeGericht-Kern: gezieltes Ziel-Konzept via $conceptId ──────

it('uebernehmeGericht mit $conceptId hängt das Gericht gezielt in DIESES Konzept (kein neues Konzept)', function () {
    $chapterId = (int) $this->slot->refresh()->chapter_id;
    // Ziel-Konzept + concept_ref-Block vorbereiten (wie es kapitelFreigeben in E7.3 tun wird).
    $ziel = app(\Platform\FoodAlchemist\Services\ConceptService::class)
        ->create($this->rootTeam, ['name' => 'Paket X', 'status' => 'draft']);
    $this->fbSvc->addBlock($this->rootTeam, $chapterId, ['type' => 'concept_ref', 'concept_id' => $ziel->id]);

    $res = $this->fbSvc->uebernehmeGericht(
        $this->rootTeam, $this->fb->id, $chapterId, $this->dish->id, 'Vorspeise', 'kapitel_freigabe', $ziel->id
    );

    expect($res['schon_drin'])->toBeFalse()
        ->and($res['concept_id'])->toBe($ziel->id)
        ->and($res['chapter_id'])->toBe($chapterId);
    // Gericht landet als Slot im Ziel-Konzept; KEIN zweites Konzept angelegt.
    expect(FoodAlchemistConceptSlot::where('concept_id', $ziel->id)->where('sales_recipe_id', $this->dish->id)->count())->toBe(1)
        ->and(FoodAlchemistConcept::where('team_id', $this->rootTeam->id)->count())->toBe(1);
});

it('uebernehmeGericht ohne $conceptId verhält sich wie der Wrapper (führendes Kapitel-Konzept, foodbook_slot)', function () {
    $chapterId = (int) $this->slot->refresh()->chapter_id;

    $res = $this->fbSvc->uebernehmeGericht($this->rootTeam, $this->fb->id, $chapterId, $this->dish->id, 'Hauptgang');

    $concept = FoodAlchemistConcept::find($res['concept_id']);
    expect($res['schon_drin'])->toBeFalse()
        ->and($concept)->not->toBeNull()
        ->and($concept->created_via)->toBe('foodbook_slot')
        ->and($concept->level)->toBe('haute');
});

/**
 * Etappe 5 P3 — Foodbook als Leitstelle-Trigger: aus dem Foodbook-Gerüst eine Voll-Kaskade starten
 * (Ausgabe-Modul = Quelle) und in den Planung-Editor zur Sammel-Review leiten. Der Service-Pfad
 * (Frame → Concept-Step je Slot + GenerateConceptJob-Attach) ist in PlanningCascadeTest gepinnt;
 * hier fehlte die Livewire-Trigger-Deckung (Session-Anlage, Redirect, Fehlerpfad).
 */
it('vollKaskadeStarten (Spec 42 F2): öffnet die Leitstelle im Owner-Kontext, plant NICHT mehr im Modul', function () {
    Queue::fake();

    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('vollKaskadeStarten')
        ->assertRedirect(route('foodalchemist.planung.index', ['fb_owner' => $this->fb->id]))
        ->assertSet('kaskadeMeldung', null);

    // Spec 42: Planung zieht in die Leitstelle — das Modul legt weder Session noch Lauf an und
    // dispatcht keinen Job. Rahmen/Inhalte entstehen erst in der Leitstelle (foodbookAusBrief).
    expect(FoodAlchemistPlanningSession::where('team_id', $this->rootTeam->id)
        ->where('created_via', 'foodbook_vollkaskade')->count())->toBe(0);
    expect(FoodAlchemistCascadeRun::where('source_owner_type', 'foodbook')
        ->where('source_owner_id', $this->fb->id)->count())->toBe(0);
    Queue::assertNotPushed(GenerateConceptJob::class);
});
