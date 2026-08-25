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

// Spec-42-Vollzug S3b: die 7-Schritt-Planungs-Checkliste ist entfallen (Planung → Leitstelle); der
// Phasen-Stepper (Versand-Status) bleibt. Der frühere Checklisten-Render-Test ist damit gegenstandslos.

// ── Spec 19 E5.3 / S3b: Leitstelle-Rail (Nested-Livewire) — Kopf-Modus (nur Kuration) ──

it('Leitstelle-Rail Kopf-Modus: 3-Panel-Umschalter + Kapitel-Matrix', function () {
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
        ->assertSee('Vorspeisen')
        ->html();

    // Kapitel-Planung darf im Kopf-Modus NICHT auftauchen.
    expect($html)->not->toContain('data-rail-kapitel');
});

// S3b: Die Rail-Kapitel-Planung (M3-Ziele-Editor + Kapitel-Go „Anlegen"/Undo) ist in die Leitstelle
// gewandert und dort abgedeckt (LeitstelleKapitelKaskadeTest: starteKapitelKaskade + KapitelRail-Setter).
// Der Kapitel-Modus der Rail zeigt jetzt nur noch Kuration/QC (Coverage + Kalkulation).

// Bug (Dominique 2026-08-23): im Foodbook-Katalog liess sich Gericht nicht wählen.
// Verdacht: Property $katalogModus + Methode katalogModus() gleich benannt (Speisekarte: pickerModus).
// #3 (2026-08-25): der Picker enthält ausschließlich Concept · Paket · Format — der Gericht-Reiter
// ist raus. Format/Paket werden WIE EIN CONCEPT gebucht. Ungültige Werte bleiben auf dem letzten Modus.
it('Foodbook-Katalog: Modus-Wechsel concept->paket->format schaltet (Server-Modus)', function () {
    Livewire::test(FoodbooksIndex::class)->call('neu');
    $fb = FoodAlchemistFoodbook::first();
    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $fb->id)
        ->assertSet('pickerModus', 'concept')
        ->call('katalogModus', 'paket')
        ->assertSet('pickerModus', 'paket')
        ->call('katalogModus', 'format')
        ->assertSet('pickerModus', 'format')
        ->call('katalogModus', 'gericht')          // #3: entfernter Modus wird ignoriert
        ->assertSet('pickerModus', 'format')
        ->call('katalogModus', 'quatsch')
        ->assertSet('pickerModus', 'format');
});

it('#3: Paket-Reiter listet kind=paket (consumer_name), Concept-Reiter nicht; paketHinzu bucht als concept_ref', function () {
    $concepts = app(ConceptService::class);
    // kind=paket-Concept mit Kundenname + aktivem Status
    $paket = $concepts->createPaket($this->rootTeam, ['name' => 'Salatwand intern']);
    $concepts->update($this->rootTeam, $paket->id, ['consumer_name' => 'Frische Salat-Auswahl', 'status' => 'active', 'price_mode' => 'manuell', 'price_per_person_manual' => 6.90]);

    // Service-Ebene: Paket im Paket-Picker, NICHT im Concept-Picker; Concept umgekehrt.
    $svc = app(FoodbookService::class);
    expect($svc->paketKandidaten($this->rootTeam, '')->pluck('id')->all())->toContain($paket->id)
        ->and($svc->paketKandidaten($this->rootTeam, '')->pluck('id')->all())->not->toContain($this->concept->id)
        ->and($svc->conceptKandidaten($this->rootTeam, '')->pluck('id')->all())->not->toContain($paket->id);

    // UI: Paket-Reiter zeigt den Kundennamen (nicht den internen), + einbuchen als concept_ref.
    Livewire::test(FoodbooksIndex::class)->call('neu');
    $fb = FoodAlchemistFoodbook::first();
    $comp = Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $fb->id)
        ->set('neuesKapitelTitel', 'Buffet')->call('kapitelNeu');
    $kap = $fb->kapitel()->first();
    $comp->call('katalogModus', 'paket')
        ->assertSee('Frische Salat-Auswahl')
        ->assertDontSee('Salatwand intern')
        ->call('paketHinzu', $paket->id);

    expect($kap->blocks()->where('type', 'concept_ref')->where('concept_id', $paket->id)->count())->toBe(1);
});

it('#7/#1: Block-Vorschau löst ein eingebettetes Paket auf (Gerichte + Paket-Preis)', function () {
    $concepts = app(ConceptService::class);
    // Gericht + kind=paket-Concept mit manuellem Preis + einem Gericht
    $green = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'gp7', 'name' => 'Salat: Green Power',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 2.00, 'ek_total_eur' => 0.60,
    ]);
    $paket = $concepts->createPaket($this->rootTeam, ['name' => 'Salatwand']);
    $concepts->update($this->rootTeam, $paket->id, ['status' => 'active', 'price_mode' => 'manuell', 'price_per_person_manual' => 7.90]);
    $ps = $concepts->addSlot($this->rootTeam, $paket->id, ['role' => 'Vorspeise']);
    $concepts->fillSlot($this->rootTeam, $ps->id, ['sales_recipe_id' => $green->id]);
    // Concept, das das Paket einbettet
    $concept = $concepts->create($this->rootTeam, ['name' => 'Grill-Menü', 'status' => 'active']);
    $slot = $concepts->addSlot($this->rootTeam, $concept->id, ['role' => 'Vorspeise']);
    $concepts->fillSlot($this->rootTeam, $slot->id, ['embedded_concept_id' => $paket->id]);

    Livewire::test(FoodbooksIndex::class)->call('neu');
    $fb = FoodAlchemistFoodbook::first();
    $comp = Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $fb->id)
        ->set('neuesKapitelTitel', 'Buffet')->call('kapitelNeu');
    $kap = $fb->kapitel()->first();
    $comp->call('conceptHinzu', $concept->id)
        ->assertSee('Salatwand')        // eingebettetes Paket in der Block-Vorschau aufgelöst
        ->assertSee('7,90')             // Paket-Preis (#1)
        ->assertSee('Green Power');     // Gericht des Pakets, eingerückt (#7)
});
