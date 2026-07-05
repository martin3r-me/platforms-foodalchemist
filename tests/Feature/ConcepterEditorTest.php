<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M10R-3: Voll-Editor-Modal (kontext-adaptiv Concept | Paket) — öffnen, Kopf
 * speichern, Positionen/Gerichte editieren, Tabs.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->pakete = app(PaketService::class);
    $this->concepts = app(ConceptService::class);

    $this->green = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g', 'name' => 'Green Power',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 2.00, 'ek_total_eur' => 0.60,
    ]);
    $this->paket = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'role' => 'Vorspeise']);
    $this->pakete->update($this->rootTeam, $this->paket->id, ['price_per_person' => 4.50]);
    $this->concept = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
});

it('öffnet ein Concept, lädt den Kopf + meldet modal.open', function () {
    Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->assertSet('type', 'concepts')
        ->assertSet('id', $this->concept->id)
        ->assertSet('form.name', 'Grill-Buffet')
        ->assertSet('tab', 'aufbau')
        ->assertDispatched('modal.open');
});

it('speichert die Kopf-Felder (Konsumentenname, Klasse, Zielpreis)', function () {
    Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->set('form.consumer_name', 'Sommerbuffet')
        ->set('form.class', 'Buffet')
        ->set('form.target_price_per_person', 36.00)
        ->call('speichern')
        ->assertDispatched('concepter-gespeichert');

    $c = FoodAlchemistConcept::find($this->concept->id);
    expect($c->consumer_name)->toBe('Sommerbuffet')
        ->and($c->class)->toBe('Buffet')
        ->and((float) $c->target_price_per_person)->toBe(36.0);
});

it('Aufbau: Position anlegen + mit Paket füllen', function () {
    $comp = Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->set('neuerSlotRolle', 'Vorspeise')
        ->call('slotHinzu');

    $slot = $this->concept->slots()->first();
    expect($slot)->not->toBeNull();

    $comp->call('fuellePaket', $slot->id, $this->paket->id);
    expect($slot->refresh()->package_id)->toBe($this->paket->id);
});

it('öffnet ein Paket und schnürt Gerichte (hinzufügen/entfernen)', function () {
    $comp = Livewire::test(Editor::class)
        ->call('oeffnen', 'pakete', $this->paket->id)
        ->assertSet('form.name', 'Salad Wall')
        ->call('gerichtHinzu', $this->green->id);

    expect($this->paket->gerichte()->count())->toBe(1);

    $comp->call('gerichtRaus', $this->green->id);
    expect($this->paket->gerichte()->count())->toBe(0);
});

it('Tab-Wechsel funktioniert', function () {
    Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->call('setTab', 'kalkulation')
        ->assertSet('tab', 'kalkulation')
        ->call('setTab', 'unsinn')
        ->assertSet('tab', 'kalkulation');                            // ungültiger Tab ignoriert
});

it('M10R-4: inline neues Paket im Slot schnüren öffnet das Paket im selben Modal', function () {
    $slot = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Vorspeise']);
    $vorher = FoodAlchemistPaket::count();

    Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->call('neuesPaketImSlot', $slot->id)
        ->assertSet('type', 'pakete')                                 // Editor zeigt jetzt das neue Paket
        ->assertSet('id', fn ($v) => $v !== null);

    expect(FoodAlchemistPaket::count())->toBe($vorher + 1)
        ->and($slot->refresh()->package_id)->not->toBeNull();
});

it('C-02: Pflicht/optional-Toggle je Slot speichert is_pflicht', function () {
    $slot = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Vorspeise']);

    Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->set("slotForm.{$slot->id}.is_pflicht", false)
        ->call('slotSpeichern', $slot->id);

    expect($slot->refresh()->is_pflicht)->toBeFalse();
});

it('M10R-4: Als Vorlage speichern aus dem Editor', function () {
    $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Vorspeise']);

    Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->call('alsVorlage')
        ->assertDispatched('concepter-vorlage-gespeichert');

    expect(FoodAlchemistConcept::vorlagen()->count())->toBe(1);
});

it('Phase 3: positionEinfuegen legt aus der Seiten-Liste direkt eine Gericht- bzw. Basisrezept-Position an', function () {
    $basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'b', 'name' => 'Demi-Glace',
        'status' => 'approved', 'is_sales_recipe' => false, 'ek_total_eur' => 1.20,
    ]);

    $comp = Livewire::test(Editor::class)->call('oeffnen', 'concepts', $this->concept->id);

    $comp->call('positionEinfuegen', 'gericht', $this->green->id);
    $comp->call('positionEinfuegen', 'basisrezept', $basis->id);

    $slots = $this->concept->slots()->orderBy('position')->get();
    expect($slots)->toHaveCount(2)
        ->and($slots[0]->sales_recipe_id)->toBe($this->green->id)
        ->and($slots[0]->type)->toBe('gericht')
        ->and($slots[1]->sales_recipe_id)->toBe($basis->id)
        ->and($slots[1]->type)->toBe('basisrezept');
});

it('Build C: Einfügeziel + Drop sortieren neue Positionen an die gewählte Stelle (nicht nur ans Ende)', function () {
    $mk = fn (string $k, string $n) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => $k, 'name' => $n,
        'status' => 'approved', 'is_sales_recipe' => false, 'ek_total_eur' => 1.0,
    ]);
    $a = $mk('a', 'Basis A');
    $b = $mk('b', 'Basis B');
    $c = $mk('c', 'Basis C');

    $comp = Livewire::test(Editor::class)->call('oeffnen', 'concepts', $this->concept->id);
    $comp->call('positionEinfuegen', 'gericht', $this->green->id);   // [green]
    $gruen = $this->concept->slots()->orderBy('position')->first();

    // Ziel setzen + wieder abwählen (Toggle)
    $comp->call('zielSetzen', $gruen->id)->assertSet('einfuegenNachId', $gruen->id);
    $comp->call('zielSetzen', $gruen->id)->assertSet('einfuegenNachId', null);

    $comp->call('positionEinfuegen', 'basisrezept', $a->id);          // ans Ende → [green, A]

    // Ziel = hinter green → B landet zwischen green und A
    $comp->call('zielSetzen', $gruen->id);
    $comp->call('positionEinfuegen', 'basisrezept', $b->id);          // [green, B, A]

    // Drop = explizit hinter green → C zwischen green und B
    $comp->call('positionDrop', 'basisrezept', $c->id, $gruen->id);   // [green, C, B, A]

    $reihenfolge = $this->concept->slots()->orderBy('position')->pluck('sales_recipe_id')->all();
    expect($reihenfolge)->toBe([$this->green->id, $c->id, $b->id, $a->id]);
});

it('Paket = Abschnitt: die Gerichte des Pakets stehen immer als eingerückte Zeilen darunter', function () {
    $this->pakete->syncGerichte($this->rootTeam, $this->paket->id, [['sales_recipe_id' => $this->green->id, 'quantity' => 1]]);
    $slot = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['package_id' => $this->paket->id]);

    // Ohne Toggle: Paket-Header (Name) + sein Gericht direkt sichtbar.
    Livewire::test(Editor::class)->call('oeffnen', 'concepts', $this->concept->id)
        ->assertSee('Salad Wall')      // Paket als Abschnitts-Header
        ->assertSee('Green Power');    // Gericht eingerückt darunter
});

it('Q2: positionEinfuegen(paket) legt eine Paket-Position an (linke Liste, Umschalter Paket)', function () {
    $comp = Livewire::test(Editor::class)->call('oeffnen', 'concepts', $this->concept->id)
        ->set('linkeListe', 'paket');

    $comp->call('positionEinfuegen', 'paket', $this->paket->id);

    $slot = $this->concept->slots()->orderBy('position')->first();
    expect($slot->package_id)->toBe($this->paket->id)
        ->and($slot->type)->toBe('paket');
});

it('Inline-Reorder: positionVerschieben sortiert eine Position hinter eine andere', function () {
    $mk = fn (string $k, string $n) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => $k, 'name' => $n,
        'status' => 'approved', 'is_sales_recipe' => false, 'ek_total_eur' => 1.0,
    ]);
    $a = $mk('ra', 'R A');
    $b = $mk('rb', 'R B');

    $comp = Livewire::test(Editor::class)->call('oeffnen', 'concepts', $this->concept->id);
    $comp->call('positionEinfuegen', 'gericht', $this->green->id);   // [green]
    $comp->call('positionEinfuegen', 'basisrezept', $a->id);          // [green, A]
    $comp->call('positionEinfuegen', 'basisrezept', $b->id);          // [green, A, B]

    $slots = $this->concept->slots()->orderBy('position')->get();
    [$gruen, $sa, $sb] = [$slots[0], $slots[1], $slots[2]];

    // B hinter green ziehen → [green, B, A]
    $comp->call('positionVerschieben', $sb->id, $gruen->id);
    $reihenfolge = $this->concept->slots()->orderBy('position')->pluck('id')->all();
    expect($reihenfolge)->toBe([$gruen->id, $sb->id, $sa->id]);
});

it('+ Paket: legt Paket-Position an, öffnet den Paket-Editor und springt zurück ins Concept', function () {
    $comp = Livewire::test(Editor::class)->call('oeffnen', 'concepts', $this->concept->id);

    $comp->call('neuesPaketAlsPosition')
        ->assertSet('type', 'pakete')
        ->assertSet('rueckSprungConceptId', $this->concept->id);

    // Eine Paket-Position ist im Concept entstanden.
    expect($this->concept->slots()->whereNotNull('package_id')->count())->toBe(1);

    // Zurück ins Concept.
    $comp->call('zurueckZumConcept')
        ->assertSet('type', 'concepts')
        ->assertSet('id', $this->concept->id)
        ->assertSet('rueckSprungConceptId', null);
});

it('Einheiten-Fix: Einfügen setzt die Portion-Einheit als Default (Gericht + Basisrezept)', function () {
    $portion = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count',
    ]);
    $basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'eb', 'name' => 'Edel-Basis',
        'status' => 'approved', 'is_sales_recipe' => false, 'ek_total_eur' => 1.0,
    ]);

    $comp = Livewire::test(Editor::class)->call('oeffnen', 'concepts', $this->concept->id);
    $comp->call('positionEinfuegen', 'gericht', $this->green->id);
    $comp->call('positionEinfuegen', 'basisrezept', $basis->id);

    $slots = $this->concept->slots()->orderBy('position')->get();
    expect($slots[0]->unit_vocab_id)->toBe($portion->id)
        ->and((float) $slots[0]->quantity)->toBe(1.0)
        ->and($slots[1]->unit_vocab_id)->toBe($portion->id);
});

it('Reinspringen: paketOeffnen öffnet das Paket und merkt den Rückweg ins Concept', function () {
    $comp = Livewire::test(Editor::class)->call('oeffnen', 'concepts', $this->concept->id);

    $comp->call('paketOeffnen', $this->paket->id)
        ->assertSet('type', 'pakete')
        ->assertSet('id', $this->paket->id)
        ->assertSet('rueckSprungConceptId', $this->concept->id);
});

it('Concept-VK: auto = Summe der Positionen, manuell überschreibt sie (EK bleibt aus Positionen)', function () {
    \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count',
    ]);
    $comp = Livewire::test(Editor::class)->call('oeffnen', 'concepts', $this->concept->id);
    $comp->call('positionEinfuegen', 'gericht', $this->green->id);   // green: vk_netto 2.00, ek 0.60

    $svc = app(ConceptService::class);
    $auto = $svc->preisCockpit($this->concept->fresh());
    expect((float) $auto['price_per_person'])->toBe(2.0)
        ->and((float) $auto['summe_pro_person'])->toBe(2.0)
        ->and($auto['price_mode'])->toBe('auto');

    // Manuell auf 99 €/Person (z. B. Lunchbuffet, Preis auf EK-Basis)
    $comp->call('setPreisModus', 'manuell')->set('form.price_per_person_manual', 99)->call('speichern');

    $manuell = $svc->preisCockpit($this->concept->fresh());
    expect((float) $manuell['price_per_person'])->toBe(99.0)         // manueller Preis gewinnt
        ->and((float) $manuell['summe_pro_person'])->toBe(2.0)        // berechnete Summe bleibt sichtbar
        ->and($manuell['price_mode'])->toBe('manuell')
        ->and((float) $manuell['ek_per_person'])->toBe(0.6);          // EK weiter aus den Positionen
});
