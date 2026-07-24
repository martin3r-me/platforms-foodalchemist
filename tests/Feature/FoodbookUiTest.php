<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as FoodbooksIndex;
use Platform\FoodAlchemist\Livewire\Foodbooks\LeitstelleRail;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M11-03: Livewire-Smoke des Foodbook-Editors — anlegen, Kapitel, Concept einfügen,
 * Pax-Gesamtpreis im Cockpit. Voll-Page-Render gegen platform::layouts.app.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);

    // Concept „Grill-Buffet" (4,50 €/P) als einfügbarer Inhalt
    $paket = app(PaketService::class)->create($this->rootTeam, ['name' => 'Salad Wall', 'role' => 'Vorspeise', 'price_mode' => 'manuell']);
    app(PaketService::class)->update($this->rootTeam, $paket->id, ['price_per_person' => 4.50]);
    $this->concept = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $slot = app(ConceptService::class)->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Vorspeise']);
    app(ConceptService::class)->fillSlot($this->rootTeam, $slot->id, ['package_id' => $paket->id]);
});

it('Foodbook-Editor: anlegen, Kapitel, Concept einfügen, €/Person im Cockpit', function () {
    Livewire::test(FoodbooksIndex::class)->assertOk()->call('neu');
    $fb = FoodAlchemistFoodbook::first();
    expect($fb)->not->toBeNull();

    $comp = Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $fb->id)
        ->set('form.label', 'Angebot Adler')
        ->set('form.customer', 'Hotel Adler')
        ->set('form.personen', 100)
        ->call('speichern')
        ->set('neuesKapitelTitel', 'Menü')
        ->call('kapitelNeu');

    $kap = $fb->kapitel()->first();
    expect($kap)->not->toBeNull();

    // Concept einfügen (KEIN Gericht-Picker) → Cockpit zeigt €/Person: das Foodbook ist
    // seit dem Angebote-Umbau person-unabhängiges Portfolio (Pax × Gesamt lebt im ANGEBOT)
    $comp->call('conceptHinzu', $this->concept->id)
        ->assertSee('Grill-Buffet')
        ->assertSee('4,50');

    expect($kap->blocks()->where('type', 'concept_ref')->count())->toBe(1)
        ->and((int) $fb->refresh()->personen)->toBe(100);             // Pax bleibt gespeichert (Staffel/Angebot)
});

it('Foodbook-Editor: kapitelNeu(parentId) legt ein Unterkapitel unter dem Eltern-Kapitel an', function () {
    Livewire::test(FoodbooksIndex::class)->call('neu');
    $fb = FoodAlchemistFoodbook::first();
    $comp = Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $fb->id)
        ->set('neuesKapitelTitel', 'Hauptteil')->call('kapitelNeu');       // Top-Kapitel (parentId = null)

    $top = $fb->kapitel()->whereNull('parent_id')->first();
    expect($top)->not->toBeNull();

    $comp->call('kapitelNeu', $top->id);                                   // Unterkapitel unter Top
    $sub = $fb->kapitel()->where('parent_id', $top->id)->first();
    expect($sub)->not->toBeNull()
        ->and($sub->parent_id)->toBe($top->id);                            // nicht flach → echtes Unterkapitel
});

it('Foodbook-Editor: Header-Preis-Block (person) erscheint mit €/Person im Cockpit', function () {
    Livewire::test(FoodbooksIndex::class)->call('neu');
    $fb = FoodAlchemistFoodbook::first();
    $comp = Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $fb->id)
        ->set('form.personen', 50)->call('speichern')
        ->set('neuesKapitelTitel', 'Pakete')->call('kapitelNeu');

    $kap = $fb->kapitel()->first();
    $comp->call('presetHinzu', 'header_frei_preis', 'format.menue_paket', 'Menü-Paket', 'person', true);
    $block = $kap->blocks()->where('type', 'header_frei_preis')->first();
    expect($block)->not->toBeNull();

    // Preis setzen via Inline-Editor
    $comp->call('blockBearbeiten', $block->id)
        ->set('blockForm.price_value', 38)
        ->set('blockForm.price_basis', 'person')
        ->call('blockSpeichern')
        ->assertSee('38,00');                                         // €/Person — Pax-Gesamt lebt im Angebot

    expect((float) $block->refresh()->price_value)->toBe(38.0);
});

// Spec 19 E5.2: Leitstellen-Checkliste auf Tab-Leisten-Ebene (7 Chips) + Phasen-Stepper daneben.
// Der Stepper wanderte aus der Briefing-Karte hierher; die Checkliste hängt am LeitstelleService.
it('Foodbook-Editor: Leitstellen-Checkliste rendert 7 klickbare Schritte auf Tab-Ebene', function () {
    Livewire::test(FoodbooksIndex::class)->call('neu');
    $fb = FoodAlchemistFoodbook::first();

    $html = Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $fb->id)
        ->assertSee('data-leitstelle-checkliste', false)              // Leiste da
        ->assertSee('data-fb-leitstelle', false)                     // Container auf Tab-Ebene
        ->assertSee('fb-goto', false)                                // Sprung-Event-Bus verdrahtet
        ->assertSee('data-checkliste-schritt="bedarf"', false)       // erster Schritt
        ->assertSee('data-checkliste-schritt="preise"', false)       // letzter Schritt
        ->assertSee('Bedarf')->assertSee('Preise')
        ->html();

    // Genau 7 Schritte, alle mit Status-Attribut
    expect(substr_count($html, 'data-checkliste-schritt="'))->toBe(7);
});

// ── Spec 19 E5.3: Leitstelle-Rail (Nested-Livewire) — Kopf- vs. Kapitel-Modus ──

it('Leitstelle-Rail Kopf-Modus: 3-Panel-Umschalter + Checkliste + Kapitel-Matrix', function () {
    $svc = app(FoodbookService::class);
    $fb = $svc->create($this->rootTeam, ['label' => 'Rail-FB']);
    $svc->addKapitel($this->rootTeam, $fb->id, ['title' => 'Vorspeisen']);

    $html = Livewire::test(LeitstelleRail::class, ['foodbookId' => $fb->id, 'kapitelId' => null])
        ->assertOk()
        ->assertSee('data-rail-kopf', false)
        ->assertSee('data-rail-panel-btn="fortschritt"', false)
        ->assertSee('data-rail-panel-btn="speisen"', false)
        ->assertSee('data-rail-panel-btn="kalkulation"', false)
        ->assertSee('data-rail-matrix', false)
        ->assertSee('fb-cockpit-tab', false)                 // Auto-Default-Event-Listener verdrahtet
        ->assertSee('data-rail-progress', false)             // kompakter Fortschritts-Zähler
        ->assertSee('Vorspeisen')
        ->html();

    // Kapitel-Planung darf im Kopf-Modus NICHT auftauchen.
    expect($html)->not->toContain('data-rail-kapitel');
});

it('Leitstelle-Rail Kapitel-Modus: Ziele-Editing speichert M3-Spalten + meldet an den Eltern', function () {
    $svc = app(FoodbookService::class);
    $fb = $svc->create($this->rootTeam, ['label' => 'Rail-FB']);
    $k = $svc->addKapitel($this->rootTeam, $fb->id, ['title' => 'Hauptgänge']);
    $zg = FoodAlchemistTargetGroup::create(['team_id' => $this->rootTeam->id, 'name' => 'Bankett', 'sort_order' => 1]);

    $comp = Livewire::test(LeitstelleRail::class, ['foodbookId' => $fb->id, 'kapitelId' => $k->id])
        ->assertOk()
        ->assertSee('data-rail-kapitel', false)
        ->assertSee('data-rail-ziele', false)
        ->assertSee('Bankett');

    // M3-Ziele setzen → updateKapitel persistiert; Event ans Eltern-Cockpit.
    $comp->set('ziel.target_count', 3)
        ->set('ziel.niveau', 'gehoben')
        ->set('ziel.price_anchor', 24.50)
        ->call('zieleSpeichern')
        ->assertDispatched('leitstelle-kapitel-geaendert');

    $k->refresh();
    expect((int) $k->target_count)->toBe(3)
        ->and($k->niveau)->toBe('gehoben')
        ->and((float) $k->price_anchor)->toBe(24.50);

    // Zielgruppen-Chip toggeln → chapter_target_groups-Sync + Event.
    $comp->call('zielgruppeToggle', $zg->id)
        ->assertDispatched('leitstelle-kapitel-geaendert');
    expect($k->targetGroups()->count())->toBe(1);

    // Nochmals toggeln entfernt wieder.
    $comp->call('zielgruppeToggle', $zg->id);
    expect($k->targetGroups()->count())->toBe(0);
});

// ── Spec 19 E7.5: Anlage-Modal (Kapitel-Go) + „Anlage zurückziehen" über die Rail ──

it('Leitstelle-Rail: „Kapitel anlegen" materialisiert und „zurückziehen" macht es rückgängig', function () {
    $svc = app(FoodbookService::class);
    $fb = $svc->create($this->rootTeam, ['label' => 'Anlage-Rail']);
    $k = $svc->addKapitel($this->rootTeam, $fb->id, ['title' => 'Buffet']);

    // Ein echtes VK-Gericht als Einzel-Skizze im Kapitel.
    $hg = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'HG', 'label' => 'Hauptgericht']);
    $klasse = FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'dish_main_group_id' => $hg->id, 'code' => 'HG_N', 'label' => 'Neutral', 'diet_form' => 'neutral']);
    $dish = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'rX', 'name' => 'HG: Sellerie-Steak', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 14.0, 'dish_class_id' => $klasse->id]);
    app(IdeenService::class)->uebernehmeBestand($this->rootTeam, ['chapter_id' => $k->id, 'sales_recipe_id' => $dish->id]);

    // Modal-Trigger + ☑-Liste sichtbar, solange nicht angelegt.
    $comp = Livewire::test(LeitstelleRail::class, ['foodbookId' => $fb->id, 'kapitelId' => $k->id])
        ->assertOk()
        ->assertSee('data-rail-go', false)
        ->assertSee('data-anlage-bestaetigen', false)
        ->assertSee('HG: Sellerie-Steak');

    // „Jetzt anlegen" → recipe_ref-Block entsteht, Event ans Cockpit.
    $comp->set('anlageNote', 'go')
        ->call('kapitelAnlegen')
        ->assertDispatched('leitstelle-kapitel-geaendert');
    expect($k->refresh()->released_at)->not->toBeNull()
        ->and($k->blocks()->where('type', 'recipe_ref')->count())->toBe(1);

    // Nach Anlage: Undo-Button sichtbar (draft, kein Snapshot) → zurückziehen.
    $comp = Livewire::test(LeitstelleRail::class, ['foodbookId' => $fb->id, 'kapitelId' => $k->id])
        ->assertSee('data-rail-undo', false)
        ->assertSee('data-rail-released', false);
    $comp->call('anlageZuruckziehen')
        ->assertDispatched('leitstelle-kapitel-geaendert');
    expect($k->refresh()->released_at)->toBeNull()
        ->and($k->blocks()->count())->toBe(0);
});
