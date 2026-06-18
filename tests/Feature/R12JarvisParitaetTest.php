<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel;
use Platform\FoodAlchemist\Livewire\Suppliers\Index;
use Platform\FoodAlchemist\Livewire\Suppliers\ItemModal;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R12 (Jarvis-Parität): ★-Favorit direkt in der Artikel-Tabelle, Preis-Inline-Edit
 * im LA-Modal, ✨ LA-Kandidaten-Vorschlag am GP-Panel (deterministischer Token-Match).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->gp = $this->makeGp($this->rootTeam, 'Tomatenmark');
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'designation' => 'Tomatenmark 3-fach 800g', 'qty' => 0.8, 'unit_code' => 'kg',
    ]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->la->id, 'gp_id' => $this->gp->id]);
});

it('Artikel-Tabelle: ★-Klick setzt den globalen Lead am gemappten GP, ohne Mapping kommt die Mapping-Aufforderung', function () {
    Livewire::test(Index::class)
        ->call('leadSetzen', $this->la->id)
        ->assertSet('fehler', null);
    expect((int) $this->gp->fresh()->lead_la_supplier_item_id)->toBe($this->la->id);

    $ohne = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id, 'designation' => 'Ketchup Eimer 5kg',
    ]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $ohne->id, 'gp_id' => null]);
    Livewire::test(Index::class)
        ->call('leadSetzen', $ohne->id)
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'keinem GP zugeordnet'));
});

it('LA-Modal: ✎ Preis-Inline-Edit ändert Preis/Gültig-bis/Notiz (Komma-Parsing), Unsinn blockt', function () {
    $p = app(\Platform\FoodAlchemist\Services\PriceService::class)->createFor($this->rootTeam, $this->la, 20.00);

    Livewire::test(ItemModal::class)
        ->call('oeffnen', $this->la->id)
        ->call('preisBearbeiten', $p->id)
        ->assertSet('preisEdit.preis', '20,00')
        ->set('preisEdit.preis', '21,50')
        ->set('preisEdit.valid_to', '2026-12-31')
        ->set('preisEdit.note', 'Listenpreis 2026')
        ->call('preisUpdate')
        ->assertSet('fehler', null)
        ->assertSet('preisEditId', null);
    $p->refresh();
    expect((float) $p->price)->toBe(21.5)
        ->and(\Illuminate\Support\Carbon::parse($p->valid_to)->format('Y-m-d'))->toBe('2026-12-31')
        ->and($p->note)->toBe('Listenpreis 2026');

    Livewire::test(ItemModal::class)
        ->call('oeffnen', $this->la->id)
        ->call('preisBearbeiten', $p->id)
        ->set('preisEdit.preis', 'quatsch')
        ->call('preisUpdate')
        ->assertSet('fehler', 'Preis braucht eine Zahl ≥ 0.');
    expect((float) $p->fresh()->price)->toBe(21.5);
});

it('LA-Modal: + Preis anlegen blockt nicht-numerische Eingabe — kein stiller 0,00-€-Preis an der Wurzel der Kostenkette', function () {
    Livewire::test(ItemModal::class)
        ->call('oeffnen', $this->la->id)
        ->set('preisNeu.preis', 'abc')
        ->call('preisAnlegen')
        ->assertSet('fehler', 'Preis braucht eine Zahl ≥ 0.');
    expect(FoodAlchemistPrice::where('supplier_item_id', $this->la->id)->count())->toBe(0);

    // gültige Eingabe (Komma-Parsing) legt den Preis weiterhin an
    Livewire::test(ItemModal::class)
        ->call('oeffnen', $this->la->id)
        ->set('preisNeu.preis', '12,50')
        ->call('preisAnlegen')
        ->assertSet('fehler', null);
    expect((float) FoodAlchemistPrice::where('supplier_item_id', $this->la->id)->latest('id')->first()->price)->toBe(12.5);
});

it('GP-Panel: ✨ KI-Vorschlag findet unverknüpfte Token-Treffer, Klick verknüpft', function () {
    // zwei unverknüpfte Kandidaten + ein Nicht-Treffer
    foreach (['Tomatenmark 2-fach 4500g Dose', 'Bio Tomatenmark Tube 200g', 'Senf mittelscharf 1kg'] as $name) {
        $item = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id, 'designation' => $name,
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'gp_id' => null]);
    }

    $c = Livewire::test(DetailPanel::class, ['gpId' => $this->gp->id])
        ->call('laVorschlaege')
        ->assertSet('fehler', null);
    $kandidaten = collect($c->get('laKandidaten'));
    expect($kandidaten->pluck('designation')->all())->toContain('Tomatenmark 2-fach 4500g Dose', 'Bio Tomatenmark Tube 200g')
        ->and($kandidaten->pluck('designation'))->not->toContain('Senf mittelscharf 1kg');

    // Klick = verknüpfen → Structure trägt das GP, Kandidaten-Box schließt
    $erster = $kandidaten->first();
    $c->call('verknuepfe', $erster['id'])
        ->assertSet('fehler', null)
        ->assertSet('laKandidaten', null);
    expect((int) DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $erster['id'])->value('gp_id'))->toBe($this->gp->id);
});
