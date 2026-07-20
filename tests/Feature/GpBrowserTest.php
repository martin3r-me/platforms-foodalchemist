<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\Browser;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M3-01/02/03: GP-Browser-Neubau — Baum-Counts == SQL, Filter, Zeilen-Klick = Event
 * (Kontext-Erhalt: Auswahl in der URL, kein Seitenwechsel), Panel hört auf gp-selected.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    FoodAlchemistLookupWarengruppe::create(['team_id' => $this->rootTeam->id, 'code' => '01', 'name' => 'Gemüse']);
    FoodAlchemistLookupWarengruppe::create(['team_id' => $this->rootTeam->id, 'code' => '09', 'name' => 'Backwaren']);

    $mk = function (string $name, array $extra = []) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update($extra);

        return $gp->refresh();
    };
    $this->zander = $mk('Zanderfilet', ['commodity_group_code' => '01', 'sub_category' => 'Fisch', 'status' => 'approved']);
    $mk('Karotte', ['commodity_group_code' => '01', 'sub_category' => 'Wurzelgemüse', 'status' => 'approved']);
    $mk('Brotkonfekt', ['commodity_group_code' => '09', 'status' => 'tentative']);
    // Kind-Team ergänzt Eigenes — für Root unsichtbar (D1)
    $kindGp = $this->makeGp($this->childA, 'Kind-Spezial');
    $kindGp->update(['commodity_group_code' => '01']);
});

it('WG-Baum-Counts stimmen mit SQL überein und ignorieren den WG-Filter selbst (DoD M3-01)', function () {
    $svc = app(GpService::class);

    expect($svc->wgCounts($this->rootTeam))->toBe(['01' => 2, '09' => 1])
        // WG-Filter darf die Baum-Counts nicht beschneiden (sonst „verschwinden" Geschwister-Gruppen)
        ->and($svc->wgCounts($this->rootTeam, ['commodity_group' => '09']))->toBe(['01' => 2, '09' => 1])
        // andere Filter wirken auf den Baum
        ->and($svc->wgCounts($this->rootTeam, ['status' => 'tentative']))->toBe(['09' => 1]);

    // D1: Kind sieht Geerbtes + Eigenes
    expect($svc->wgCounts($this->childA))->toBe(['01' => 3, '09' => 1]);
});

it('Sub-Kategorie-Counts je WG (DoD M3-01)', function () {
    expect(app(GpService::class)->subKategorieCounts($this->rootTeam, '01'))
        ->toBe(['Fisch' => 1, 'Wurzelgemüse' => 1]);
});

it('paginateBrowser filtert nach WG, Sub-Kategorie, Status und Suche (DoD M3-02)', function () {
    $svc = app(GpService::class);

    expect($svc->paginateBrowser(['commodity_group' => '01'], $this->rootTeam)->total())->toBe(2)
        ->and($svc->paginateBrowser(['commodity_group' => '01', 'sub_category' => 'Fisch'], $this->rootTeam)->total())->toBe(1)
        ->and($svc->paginateBrowser(['status' => 'tentative'], $this->rootTeam)->total())->toBe(1)
        ->and($svc->paginateBrowser(['search' => 'zander'], $this->rootTeam)->total())->toBe(1)
        ->and($svc->paginateBrowser([], $this->rootTeam)->total())->toBe(3);
});

it('Browser: Zeilen-Klick setzt ?gp= und dispatcht gp-selected — kein Seitenwechsel (Kontext-Erhalt)', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    Livewire::test(Browser::class)
        ->assertSee('Zanderfilet')
        ->call('waehleGp', $this->zander->id)
        ->assertSet('gpId', $this->zander->id)
        ->assertDispatched('gp-selected', id: $this->zander->id);
});

it('Browser: WG-Wahl togglet und setzt Sub-Kategorie zurück', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    Livewire::test(Browser::class)
        ->call('waehleWg', '01')
        ->assertSet('commodity_group', '01')
        ->call('waehleSub', 'Fisch')
        ->assertSet('subKategorie', 'Fisch')
        ->call('waehleWg', '01') // erneuter Klick = abwählen
        ->assertSet('commodity_group', '')
        ->assertSet('subKategorie', '');
});

it('Browser: mount mit ?gp= aus der URL befüllt das Panel sofort (Kontext-Erhalt nach Reload)', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    Livewire::test(Browser::class, ['gpId' => $this->zander->id])
        ->assertDispatched('gp-selected', id: $this->zander->id);
});

it('Browser: Deeplink ?gp=&edit=1 (z.B. aus dem Convenience-Screen) öffnet den GP-Editor', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    Livewire::test(Browser::class, ['gpId' => $this->zander->id, 'editOeffnen' => true])
        ->assertDispatched('gp-selected', id: $this->zander->id)
        ->assertDispatched('gp-modal.oeffnen', id: $this->zander->id)
        ->assertSet('editOeffnen', false); // edit=1 wird nach dem Öffnen aus der URL geputzt
});

it('DetailPanel R9: Sektionen IMMER sichtbar — Allergene/Nährwerte/Verwendungen ohne Klapperei', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    $supplier = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $item = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => 'Zander TK',
    ]);
    \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'gp_id' => $this->zander->id,
    ]);
    \Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'allergen_fish' => 'enthalten',
    ]);

    Livewire::test(DetailPanel::class, ['gpId' => $this->zander->id])
        ->assertSee('HIGH')                                // R9: Konfidenz direkt sichtbar
        ->assertSee('aus 1/')
        ->assertSee('Nährwerte')
        ->assertSee('Verwendet in Rezepten')
        ->assertSeeHtml('data-lead-stern');                // ★ direkt klickbar
});

it('DetailPanel M3-07 (DoD): LA A sperren ⇒ B wird effektiver Lead — im Panel beweisbar', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    $supplier = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $mkLa = function (string $bez, float $preis) use ($supplier) {
        $la = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
            'designation' => $bez, 'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure::create([
            'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $this->zander->id,
        ]);
        \Platform\FoodAlchemist\Models\FoodAlchemistPrice::create([
            'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0',
        ]);

        return $la;
    };
    $a = $mkLa('LA A billig', 2.00);
    $b = $mkLa('LA B teurer', 4.00);

    $panel = Livewire::test(DetailPanel::class, ['gpId' => $this->zander->id])
        ->assertSee('LA A billig')
        ->assertSee('LA B teurer');

    $leads = app(\Platform\FoodAlchemist\Services\LeadLaService::class);
    expect($leads->effektiverLead($this->zander, $this->rootTeam)->id)->toBe($a->id);

    $panel->call('sperreToggle', $a->id, true)->assertHasNoErrors();
    expect($leads->effektiverLead($this->zander, $this->rootTeam)->id)->toBe($b->id);   // B rückt nach

    // Pin auf A trotz Sperre? Sperre gewinnt — Pin auf B fixiert B explizit
    $panel->call('pinToggle', $b->id, true);
    expect($leads->effektiverLead($this->zander, $this->rootTeam)->id)->toBe($b->id);

    // Kind-Team ohne Kurations-Recht: globale Aktion blockt mit D1-Hinweis
    $this->actingAs($this->makeUser($this->childA, 'Kind User'));
    Livewire::test(DetailPanel::class, ['gpId' => $this->zander->id])
        ->call('leadSetzen', $b->id)
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Kurations-Team'));
});

it('DetailPanel: zeigt Stammdaten nach gp-selected und respektiert D1-Sichtbarkeit (DoD M3-03)', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    Livewire::test(DetailPanel::class)
        ->assertSee('Grundprodukt in der Tabelle anklicken', escape: false)
        ->dispatch('gp-selected', id: $this->zander->id)
        ->assertSee('Zanderfilet')
        ->assertSee('Gemüse'); // Warengruppen-Name aufgelöst

    // D1-Leak-Probe: Root darf Kind-eigenes GP nicht sehen
    $kindGpId = \Platform\FoodAlchemist\Models\FoodAlchemistGp::where('name', 'Kind-Spezial')->firstOrFail()->id;
    Livewire::test(DetailPanel::class)
        ->dispatch('gp-selected', id: $kindGpId)
        ->assertDontSee('Kind-Spezial');
});
