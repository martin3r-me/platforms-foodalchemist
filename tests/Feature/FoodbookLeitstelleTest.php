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

// ── E3.3 Bedarf-Sektion: Foodbook-Default-Dimensionen (Zielgruppen/Einsatzmomente/Defaults) ──

it('toggleFbZielgruppe schaltet den Default-Zielgruppen-Pivot an und wieder aus', function () {
    $zg = \Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Test-Bankett', 'sort_order' => 10,
    ]);

    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('toggleFbZielgruppe', $zg->id);
    expect($this->fb->refresh()->targetGroups()->where('target_group_id', $zg->id)->exists())->toBeTrue();

    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('toggleFbZielgruppe', $zg->id);
    expect($this->fb->refresh()->targetGroups()->where('target_group_id', $zg->id)->exists())->toBeFalse();
});

it('toggleFbEinsatzmoment schaltet den Tagesablauf-Pivot (1–n) am Foodbook', function () {
    $em = \Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Apéro', 'sort_order' => 10,
    ]);

    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('toggleFbEinsatzmoment', $em->id);

    expect($this->fb->refresh()->serviceMoments()->where('service_moment_id', $em->id)->exists())->toBeTrue();
});

it('bedarfSetzen sichert Default-Eventtyp (FK) und Wareneinsatz-Ziel (%); Leerwert setzt auf null', function () {
    $et = \Platform\FoodAlchemist\Models\FoodAlchemistEventtyp::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Gala', 'sort_order' => 10,
    ]);

    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('bedarfSetzen', 'default_event_type_id', (string) $et->id)
        ->call('bedarfSetzen', 'target_food_cost_pct', '28.5');

    $fb = $this->fb->refresh();
    expect((int) $fb->default_event_type_id)->toBe($et->id)
        ->and((float) $fb->target_food_cost_pct)->toBe(28.5);

    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('bedarfSetzen', 'default_event_type_id', '');
    expect($this->fb->refresh()->default_event_type_id)->toBeNull();
});

// ── E6.3 Kreativ-Skizzenfläche (Livewire: freie Idee · aus Bestand · Paket-Bündelung · verwerfen) ──

it('ideeHinzu legt eine freie Skizze (entwurf) am gewählten Kapitel an', function () {
    $chapterId = (int) $this->slot->refresh()->chapter_id;

    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('kapitelWaehle', $chapterId)
        ->set('ideeTitel', 'Ceviche vom Saibling')
        ->call('ideeHinzu')
        ->assertSet('ideeTitel', ''); // Feld nach Anlage geleert

    $idee = \Platform\FoodAlchemist\Models\FoodAlchemistDishIdea::where('chapter_id', $chapterId)->first();
    expect($idee)->not->toBeNull()
        ->and($idee->title)->toBe('Ceviche vom Saibling')
        ->and($idee->status)->toBe('entwurf')
        ->and($idee->target_form)->toBe('einzel')
        ->and($idee->sales_recipe_id)->toBeNull();
});

it('ideeHinzu ohne Titel meldet einen Fehler und legt nichts an', function () {
    $chapterId = (int) $this->slot->refresh()->chapter_id;

    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('kapitelWaehle', $chapterId)
        ->set('ideeTitel', '   ')
        ->call('ideeHinzu')
        ->assertSet('ideenFehler', fn ($v) => is_string($v) && str_contains($v, 'Titel'));

    expect(\Platform\FoodAlchemist\Models\FoodAlchemistDishIdea::where('chapter_id', $chapterId)->count())->toBe(0);
});

it('skizzeAusBestand übernimmt ein VK-Gericht als Skizze (loser Zeiger, kein Duplikat)', function () {
    $chapterId = (int) $this->slot->refresh()->chapter_id;

    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('kapitelWaehle', $chapterId)
        ->call('skizzeAusBestand', $this->dish->id);

    $idee = \Platform\FoodAlchemist\Models\FoodAlchemistDishIdea::where('chapter_id', $chapterId)->first();
    expect($idee)->not->toBeNull()
        ->and($idee->sales_recipe_id)->toBe($this->dish->id)
        ->and($idee->title)->toBe('HG: Tomaten-Teller');
    // Kein Konzept/Slot entstanden (Invariante: Skizzen erden nichts).
    expect(FoodAlchemistConceptSlot::where('sales_recipe_id', $this->dish->id)->count())->toBe(0);
});

it('paketBilden bündelt markierte Skizzen; ausPaketLoesen + paketAufloesen kehren sie zurück auf einzel', function () {
    $chapterId = (int) $this->slot->refresh()->chapter_id;
    $svc = app(\Platform\FoodAlchemist\Services\IdeenService::class);
    $a = $svc->add($this->rootTeam, ['chapter_id' => $chapterId, 'title' => 'Gruß A']);
    $b = $svc->add($this->rootTeam, ['chapter_id' => $chapterId, 'title' => 'Gruß B']);

    $comp = Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('kapitelWaehle', $chapterId)
        ->set('ideeAuswahl', [$a->id, $b->id])
        ->set('paketName', 'Amuse-Bouche')
        ->call('paketBilden')
        ->assertSet('ideeAuswahl', []);

    $gruppe = \Platform\FoodAlchemist\Models\FoodAlchemistDishIdeaGroup::where('chapter_id', $chapterId)->first();
    expect($gruppe)->not->toBeNull()->and($gruppe->name)->toBe('Amuse-Bouche');
    expect($a->refresh()->group_id)->toBe($gruppe->id)
        ->and($a->target_form)->toBe('paket')
        ->and($b->refresh()->group_id)->toBe($gruppe->id);

    // Eine Skizze aus dem Paket lösen → einzel.
    $comp->call('ausPaketLoesen', $a->id);
    expect($a->refresh()->group_id)->toBeNull()->and($a->target_form)->toBe('einzel');

    // Ganzes Paket auflösen → Gruppe weg, Rest-Mitglied wieder einzel.
    $comp->call('paketAufloesen', $gruppe->id);
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistDishIdeaGroup::find($gruppe->id))->toBeNull();
    expect($b->refresh()->group_id)->toBeNull()->and($b->target_form)->toBe('einzel');
});

it('ideeVerwerfen + ideeReaktivieren schalten den Skizzen-Status (entwurf ↔ verworfen)', function () {
    $chapterId = (int) $this->slot->refresh()->chapter_id;
    $idee = app(\Platform\FoodAlchemist\Services\IdeenService::class)
        ->add($this->rootTeam, ['chapter_id' => $chapterId, 'title' => 'Testidee']);

    $comp = Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('kapitelWaehle', $chapterId)
        ->call('ideeVerwerfen', $idee->id);
    expect($idee->refresh()->status)->toBe('verworfen');

    $comp->call('ideeReaktivieren', $idee->id);
    expect($idee->refresh()->status)->toBe('entwurf');
});
