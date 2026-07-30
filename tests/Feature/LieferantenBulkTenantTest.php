<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Suppliers\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MVP-013/014/015 (Audit 23, P0): Die Bulk-Leiste der Lieferanten-Seite benutzte den
 * LESE-Scope `visibleToTeam()` als Schreibautorisierung. Ein Kind-Team konnte einen geerbten
 * (Master-)Katalog-Artikel per `bulkLoeschen()` löschen — Sichtbarkeit genügte für
 * `findOrFail(...)->delete()`, Eigentum wurde nie geprüft. `bulkMappingEntfernen()` prüfte die
 * Artikel-ID gar nicht, `bulkArtikelAktion()` lief ohne Transaktion (Teilpersistenz), und die
 * Render-Keys der Match-Vorschläge waren nicht team-gescopt (Leseleck).
 *
 * Die Regel: Sichtbarkeit erlaubt Lesen und Referenzieren, NUR das eigene Team darf schreiben.
 * Vorbild im selben Service: `setDiscontinued()` prüft `isOwnedBy()` und wirft.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    // Master-Artikel (Root), aus dem Kind-Team sichtbar (Vererbung), aber nicht besessen.
    $this->rootSupplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Master-Lieferant']);
    $this->masterItem = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->rootSupplier->id, 'designation' => 'Master-Sauerkraut 10l',
    ]);

    // Eigener Artikel des Kind-Teams.
    $this->childSupplier = FoodAlchemistSupplier::create(['team_id' => $this->childA->id, 'name' => 'Kind-Lieferant']);
    $this->childItem = FoodAlchemistSupplierItem::create([
        'team_id' => $this->childA->id, 'supplier_id' => $this->childSupplier->id, 'designation' => 'Kind-Artikel',
    ]);

    $this->actingAs($this->makeUser($this->childA, 'Kind A User'));
});

it('löscht keinen geerbten Katalog-Artikel per Bulk (MVP-013)', function () {
    Livewire::test(Index::class)
        ->set('auswahl', [$this->masterItem->id => true])
        ->call('bulkLoeschen')
        ->assertSet('fehler', fn ($m) => is_string($m) && $m !== '');

    // Der Master-Artikel existiert unverändert weiter.
    expect(FoodAlchemistSupplierItem::withTrashed()->find($this->masterItem->id)->deleted_at)->toBeNull();
});

it('löscht einen eigenen Artikel weiterhin', function () {
    Livewire::test(Index::class)
        ->set('auswahl', [$this->childItem->id => true])
        ->call('bulkLoeschen');

    expect(FoodAlchemistSupplierItem::withTrashed()->find($this->childItem->id)->deleted_at)->not->toBeNull();
});

it('bricht eine gemischte Bulk-Löschung atomar ab — kein Teil-Löschen (MVP-015)', function () {
    // Eigen + geerbt in einem Rutsch: der Fremd-Artikel muss den ganzen Lauf zurückrollen,
    // sonst bleibt der eigene gelöscht und der fremde stehen (halber Zustand).
    Livewire::test(Index::class)
        ->set('auswahl', [$this->childItem->id => true, $this->masterItem->id => true])
        ->call('bulkLoeschen')
        ->assertSet('fehler', fn ($m) => is_string($m) && $m !== '');

    expect(FoodAlchemistSupplierItem::withTrashed()->find($this->childItem->id)->deleted_at)->toBeNull()
        ->and(FoodAlchemistSupplierItem::withTrashed()->find($this->masterItem->id)->deleted_at)->toBeNull();
});

it('entfernt kein Mapping an einem geerbten Artikel (MVP-013)', function () {
    Livewire::test(Index::class)
        ->set('auswahl', [$this->masterItem->id => true])
        ->call('bulkMappingEntfernen')
        ->assertSet('fehler', fn ($m) => is_string($m) && $m !== '');
});

it('zeigt keine Match-Vorschläge fremder Teams in der Review-Liste (MVP-014)', function () {
    $gp = $this->makeGp($this->childA, 'Sauerkraut');

    // Vorschlag des eigenen Teams am eigenen Lieferanten …
    \Platform\FoodAlchemist\Models\FoodAlchemistMatchProposal::create([
        'team_id' => $this->childA->id, 'supplier_item_id' => $this->childItem->id, 'gp_id' => $gp->id,
        'score' => 0.9, 'band' => 'fuzzy_high', 'methode' => 'fuzzy_name', 'status' => 'offen',
    ]);
    // … und ein FREMDER Vorschlag (Team B) am selben Artikel — das Leseleck aus dem Audit.
    \Platform\FoodAlchemist\Models\FoodAlchemistMatchProposal::create([
        'team_id' => $this->childB->id, 'supplier_item_id' => $this->childItem->id, 'gp_id' => $gp->id,
        'score' => 0.95, 'band' => 'exact', 'methode' => 'exact_ean', 'status' => 'offen',
    ]);

    Livewire::test(Index::class)
        ->set('supplierId', $this->childSupplier->id)
        ->set('reviewOffen', true)
        ->assertViewHas('vorschlaege', fn ($v) => $v->count() === 1 && (int) $v->first()->team_id === $this->childA->id)
        ->assertViewHas('offeneVorschlaege', 1);
});
