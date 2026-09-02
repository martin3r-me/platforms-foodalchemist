<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M3-09/10: GP-Modal — Neuanlage validiert (GL-12), AUTO-SYNC-Vorschau,
 * Hard-Stop + force, KI-Felder mit GL-07-Lebenszyklus (Fake-Provider-Roundtrip).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    config(['foodalchemist.ai.provider' => 'fake']);
});

it('Neuanlage über den Builder: AUTO-SYNC-Name, Slug/gp_key-Vorschau, Insert validiert (DoD M3-09)', function () {
    Livewire::test(GpModal::class)
        ->call('oeffnen')
        ->set('builder.hauptzutat', 'Zander')
        ->set('builder.condition', 'TK')
        ->set('builder.form', 'Filet')
        ->assertSeeHtml('Zander: TK, Filet')          // AUTO-SYNC-Vorschau
        ->assertSeeHtml('zander||filet')              // gp_key-Vorschau (3 Slots)
        ->call('speichern')
        ->assertSet('fehler', null)
        ->assertDispatched('gp-gespeichert');

    $gp = FoodAlchemistGp::where('name', 'Zander: TK, Filet')->firstOrFail();
    expect($gp->gp_key)->toBe('zander||filet')
        ->and($gp->main_ingredient_slug)->toBe('zander')
        ->and($gp->status->value)->toBe('tentative')
        ->and($gp->team_id)->toBe($this->rootTeam->id);
});

it('Hard-Error (§7.1 Verpackungswort) blockt den Insert mit Fehlertext', function () {
    Livewire::test(GpModal::class)
        ->call('oeffnen')
        ->set('builder.hauptzutat', 'Tomaten Kiste')
        ->set('builder.condition', 'frisch')
        ->call('speichern')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, '§7.1'))
        ->assertNotDispatched('gp-gespeichert');

    expect(FoodAlchemistGp::where('name', 'like', '%Kiste%')->exists())->toBeFalse();
});

it('GT-12-10 im Modal: Duplikat ⇒ HARD_STOP-Fehler, force-Checkbox legt trotzdem an', function () {
    $modal = Livewire::test(GpModal::class)
        ->call('oeffnen')
        ->set('builder.hauptzutat', 'Tomate')
        ->set('builder.condition', 'trocken')
        ->set('builder.processing', 'pulverfoermig')
        ->call('speichern');
    expect(FoodAlchemistGp::where('gp_key', 'tomate|pulverfoermig|')->count())->toBe(1);

    $modal->call('oeffnen')
        ->set('builder.hauptzutat', 'Tomate')
        ->set('builder.condition', 'trocken')
        ->set('builder.processing', 'pulverfoermig')
        ->call('speichern')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'HARD_STOP_EXISTING_GP'))
        ->set('force', true)
        ->call('speichern')
        ->assertSet('fehler', null);

    expect(FoodAlchemistGp::where('gp_key', 'like', 'tomate|pulverfoermig|%')->count())->toBe(2);
});

it('M3-10 (DoD): Fake-Provider-Roundtrip zustand — ai → accept ändert Feld + Lineage, clear setzt zurück', function () {
    $gp = $this->makeGp($this->rootTeam, 'Erbsen: TK');

    $modal = Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->set('builder.condition', 'TK')                 // Kontext fürs Fake-Echo
        ->call('ai_zustand')
        ->assertSet('kiVorschlag.condition.confidence', 0.87);

    $modal->call('accept_zustand');
    $gp->refresh();
    expect($gp->condition)->toBe('TK')
        ->and($gp->condition_source)->toBe('ki')
        ->and((float) $gp->condition_ai_confidence)->toBe(0.87)
        ->and($gp->condition_ai_reasoning)->toContain('FakeAiProvider');

    $modal->call('clear_zustand');
    $gp->refresh();
    expect($gp->condition)->toBeNull()->and($gp->condition_source)->toBeNull();
});

it('GL-07 Override-First: manuell gepflegter zustand wird vom accept NICHT überschrieben', function () {
    $gp = $this->makeGp($this->rootTeam, 'Erbsen: frisch');
    $gp->update(['condition' => 'frisch', 'condition_source' => 'manual']);

    Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->set('builder.condition', 'TK')
        ->call('ai_zustand')
        ->call('accept_zustand')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'manuell'));

    expect($gp->fresh()->condition)->toBe('frisch');     // unverändert
});

it('M3-10: Fake-Roundtrip tags — accept schreibt tag_-Spalten + Lineage-Trio', function () {
    $gp = $this->makeGp($this->rootTeam, 'Erbsen: TK');

    Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->set('tags.is_vegan', '1')                    // Kontext fürs Fake-Echo
        ->set('tags.is_gluten_free', '0')
        ->call('ai_tags')
        ->call('accept_tags');

    $gp->refresh();
    expect($gp->tag_is_vegan)->toBeTrue()
        ->and($gp->tag_is_gluten_free)->toBeFalse()
        ->and($gp->tag_source)->toBe('ki')
        ->and((float) $gp->tag_ai_confidence)->toBe(0.87);
});

it('✨ gp.suggest (Neuanlage): Fake-Echo befüllt die Builder-Felder nicht mit Fremd-Keys', function () {
    Livewire::test(GpModal::class)
        ->call('oeffnen')
        ->set('kiRohtext', 'Zanderfilet TK 400g')
        ->call('kiVorschlagNaming')
        // Fake echo't {bezeichnung: …} — kein hauptzutat-Key ⇒ Builder bleibt leer (kein Müll-Mapping)
        ->assertSet('builder.hauptzutat', '');
});

it('Neuanlage verknüpft den ausgewählten Lieferantenartikel direkt und legt fehlende Structure an', function () {
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos Venlo']);
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'article_number' => '40909330', 'designation' => 'BIETENCREME MET DRAGON SOUS VIDE GEGAARD',
    ]);

    Livewire::test(GpModal::class)
        ->call('oeffnen', null, $la->id)
        ->set('builder.hauptzutat', 'Rote Bete')
        ->set('builder.condition', 'frisch')
        ->set('builder.processing', 'sous-vide gegart')
        ->set('builder.form', 'Creme')
        ->call('speichern')
        ->assertSet('fehler', null);

    $gp = FoodAlchemistGp::where('name', 'Rote Bete: frisch, sous-vide gegart')->firstOrFail();
    expect((int) FoodAlchemistSupplierItemStructure::where('supplier_item_id', $la->id)->value('gp_id'))->toBe($gp->id)
        ->and($gp->fresh()->n_las_total)->toBe(1);
});

it('LA-first öffnet sofort und plant den automatischen prüfbaren gp.suggest-Lauf', function () {
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos Venlo']);
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'BIETENCREME MET DRAGON SOUS VIDE GEGAARD',
        'is_organic' => true, 'is_vegan' => true,
    ]);

    Livewire::test(GpModal::class)
        ->call('oeffnen', null, $la->id, true)
        ->assertSet('supplierItemId', $la->id)
        ->assertSet('kiRohtext', $la->designation)
        ->assertSet('builder.bio', true)
        ->assertSet('builder.vegan', true)
        ->assertSet('autoSuggestPending', true)
        ->assertDispatched('modal.open', name: 'gp-modal')
        ->assertDispatched('gp-modal.auto-suggest', laId: $la->id);
});

it('automatischer LA-Vorschlag endet sauber und lässt die Quelle zur Prüfung ausgewählt', function () {
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos Venlo']);
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'BIETENCREME MET DRAGON SOUS VIDE GEGAARD',
        'regulated_name' => 'Rote-Bete-Creme mit Estragon',
    ]);

    Livewire::test(GpModal::class)
        ->call('oeffnen', null, $la->id, true)
        ->call('autoSuggestFromSupplierItem', $la->id)
        ->assertSet('autoSuggestPending', false)
        ->assertSet('supplierItemId', $la->id)
        ->assertSet('fehler', null);
});

it('LA-first rollt die GP-Anlage zurück wenn der Artikel zwischenzeitlich gemappt wurde', function () {
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos Venlo']);
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'Rote Bete sous-vide mit Estragon',
    ]);
    $struktur = FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => null,
    ]);
    $modal = Livewire::test(GpModal::class)
        ->call('oeffnen', null, $la->id)
        ->set('builder.hauptzutat', 'Rote Bete')
        ->set('builder.condition', 'frisch')
        ->set('builder.processing', 'sous-vide gegart');

    $anderesGp = $this->makeGp($this->rootTeam, 'Rote Bete Bestand');
    $struktur->update(['gp_id' => $anderesGp->id]);

    $modal->call('speichern')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'bereits einem anderen GP'));

    expect(FoodAlchemistGp::where('name', 'Rote Bete: frisch, sous-vide gegart')->exists())->toBeFalse()
        ->and((int) $struktur->fresh()->gp_id)->toBe($anderesGp->id);
});

it('LA-first Ersatz-Anlage speichert GP, Artikel-Mapping und Äquivalenz in einer Transaktion', function () {
    $source = $this->makeGp($this->rootTeam, 'Rote-Bete-Creme Convenience');
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos Venlo']);
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'Rote Bete Creme Alternative',
    ]);

    Livewire::test(GpModal::class)
        ->call('oeffnen', null, $la->id, false, 'gp', $source->id, 'Gleiche Funktion und Verarbeitung.', 0.93)
        ->set('builder.hauptzutat', 'Rote Bete')
        ->set('builder.condition', 'konserviert')
        ->set('builder.processing', 'püriert')
        ->call('speichern')
        ->assertSet('fehler', null)
        ->assertNotDispatched('gp-selected');

    $created = FoodAlchemistGp::where('name', 'Rote Bete: konserviert, püriert')->firstOrFail();
    $equiv = FoodAlchemistComponentEquivalent::where('source_kind', 'gp')
        ->where('source_id', $source->id)->where('alt_kind', 'gp')->where('alt_id', $created->id)->firstOrFail();

    expect((int) FoodAlchemistSupplierItemStructure::where('supplier_item_id', $la->id)->value('gp_id'))->toBe($created->id)
        ->and((float) $equiv->match_confidence)->toBe(0.93)
        ->and($equiv->notes)->toBe('Gleiche Funktion und Verarbeitung.');
});

// ── 06·H4b: Favorit direkt im GP-Editor pinnen (2. Andockpunkt) ──

it('favoriteToggle pinnt einen Convenience-GP und nimmt ihn wieder heraus', function () {
    $gp = $this->makeGp($this->rootTeam, 'TK-Spätzle');
    $gp->update(['status' => 'approved', 'tag_is_convenience' => true]);

    Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->call('favoriteToggle')
        ->assertSet('fehler', null)
        ->assertDispatched('gp-gespeichert');
    expect($gp->refresh()->is_favorite)->toBeTrue();

    Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->call('favoriteToggle');
    expect($gp->refresh()->is_favorite)->toBeFalse();
});

it('favoriteToggle pinnt AUCH nicht-Convenience-GPs (kein §4-Zwang mehr)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Frischer Spinat');
    $gp->update(['status' => 'approved']); // tag_is_convenience bleibt null/false

    Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->call('favoriteToggle')
        ->assertSet('fehler', null);

    expect($gp->refresh()->is_favorite)->toBeTrue();
});

it('favoriteToggle blockt geerbte Katalog-GPs (D1: nur Besitzer-Team)', function () {
    // GP gehört dem Root; aktiver User sitzt im Kind-Team → read-only.
    $gp = $this->makeGp($this->rootTeam, 'TK-Erbsen');
    $gp->update(['status' => 'approved', 'tag_is_convenience' => true]);
    $this->actingAs($this->makeUser($this->childA, 'Kind User'));

    Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->call('favoriteToggle')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'D1'));

    expect($gp->refresh()->is_favorite)->toBeFalse();
});
