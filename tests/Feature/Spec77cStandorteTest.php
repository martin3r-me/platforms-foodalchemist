<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Lager\Index as LagerIndex;
use Platform\FoodAlchemist\Livewire\Settings\Betriebe;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistPurchaseTransaction;
use Platform\FoodAlchemist\Services\ActiveOutletContext;
use Platform\FoodAlchemist\Services\InventurService;
use Platform\FoodAlchemist\Services\PurchaseJournalService;
use Platform\FoodAlchemist\Services\StandortService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\Support\SeedsWareneingang;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsWareneingang::class);

/**
 * Spec 77c · Standorte = Unter-Teams. Oberteam ordnet jedem Standort einen Betrieb fest zu
 * (Betriebs-Brille im Standort fest), liest über die Team-Brille eigen | alle | ein Standort —
 * nur lesend; geschrieben wird immer im besitzenden Team. Geschwister sehen einander nicht.
 */
beforeEach(function () {
    $this->seedWareneingang();
    $this->standorte = app(StandortService::class);
    $this->reg = app(ToolRegistry::class);
    $this->nord = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Kantine Nord']);
    // Bestellung im Standort A (eigene Bestellung des Unter-Teams)
    $this->bestellungA = $this->orders->addManualLine($this->childA, $this->la['Mehl']->id, 4, null, null, now()->addDays(5)->toDateString())->order()->first();
});

it('Betrieb zuordnen: nur Admin des Oberteams, nur eigene Unter-Teams und aktive Betriebe; im Standort fest', function () {
    expect(fn () => $this->standorte->betriebZuordnen($this->rootTeam, $this->childA->id, $this->nord->id, $this->koch))->toThrow(FaRechtFehltException::class);
    expect(fn () => $this->standorte->betriebZuordnen($this->childA, $this->childB->id, $this->nord->id, $this->inhaber))->toThrow(\RuntimeException::class);
    $fremd = FoodAlchemistOutlet::create(['team_id' => $this->childB->id, 'name' => 'Fremd']);
    expect(fn () => $this->standorte->betriebZuordnen($this->rootTeam, $this->childA->id, $fremd->id, $this->inhaber))->toThrow(\RuntimeException::class, 'Betrieb nicht gefunden');

    $this->standorte->betriebZuordnen($this->rootTeam, $this->childA->id, $this->nord->id, $this->inhaber);
    $ctx = app(ActiveOutletContext::class);
    expect($ctx->current($this->childA)?->id)->toBe($this->nord->id)
        ->and($ctx->set($this->childA, null)?->id)->toBe($this->nord->id);   // fest — keine Wahl
    expect(collect($this->standorte->unterTeams($this->rootTeam))->firstWhere('id', $this->childA->id)['betrieb'])->toBe('Kantine Nord');

    // Standard = erbt komplett, Betriebs-Brille wieder frei
    $this->standorte->betriebZuordnen($this->rootTeam, $this->childA->id, null, $this->inhaber);
    expect($ctx->current($this->childA))->toBeNull();
});

it('Team-Brille: eigen zeigt nur Oberteam, alle mischt, ein Standort filtert; Geschwister sehen einander nicht', function () {
    $this->actingAs($this->inhaber);
    $ids = fn () => $this->orders->listForTeam($this->rootTeam)->pluck('id')->all();
    expect($ids())->not->toContain($this->bestellungA->id);

    $this->standorte->setzeBrille($this->rootTeam, 'alle');
    expect($ids())->toContain($this->bestellungA->id)->toContain($this->order1->id);

    $this->standorte->setzeBrille($this->rootTeam, 'team', $this->childB->id);
    expect($ids())->not->toContain($this->bestellungA->id)->not->toContain($this->order1->id);

    expect(fn () => $this->standorte->setzeBrille($this->childA, 'team', $this->childB->id))->toThrow(\RuntimeException::class);
    expect($this->standorte->leseTeamIds($this->childB))->toBe([(int) $this->childB->id]);

    // Brille bleibt je Benutzer gespeichert (neue Session)
    session()->flush();
    expect($this->standorte->brille($this->rootTeam)['modus'])->toBe('team');
});

it('nur lesend: Oberteam liest den Beleg des Standorts, schreibt ihn aber nicht', function () {
    $this->actingAs($this->inhaber);
    $this->standorte->setzeBrille($this->rootTeam, 'alle');
    expect($this->orders->detail($this->rootTeam, $this->bestellungA->id))->not->toBeNull();
    expect(fn () => $this->orders->setStatus($this->rootTeam, $this->bestellungA->id, OrderStatus::Cancelled))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);   // Schreiben nur im besitzenden Team
    expect($this->bestellungA->refresh()->status)->toBe(OrderStatus::Draft);
});

it('Lager und Einkauf konsolidiert bei Brille „alle" — mit Standort und Preis des besitzenden Teams', function () {
    $ortA = FoodAlchemistInventoryLocation::create(['team_id' => $this->childA->id, 'name' => 'Lager A', 'type' => 'warehouse', 'is_default' => true, 'is_active' => true]);
    FoodAlchemistInventoryStock::create(['team_id' => $this->childA->id, 'inventory_location_id' => $ortA->id, 'gp_id' => $this->gp['Zucker']->id, 'qty_base' => 2000, 'base_unit' => 'g']);
    FoodAlchemistInventoryStock::create(['team_id' => $this->rootTeam->id, 'inventory_location_id' => $this->lager->id, 'gp_id' => $this->gp['Mehl']->id, 'qty_base' => 1000, 'base_unit' => 'g']);
    foreach ([[$this->rootTeam, 100.0], [$this->childA, 40.0]] as [$t, $summe]) {
        FoodAlchemistPurchaseTransaction::create(['team_id' => $t->id, 'supplier_id' => $this->chefs->id, 'designation_raw' => 'Mehl', 'unit_code' => 'kg',
            'qty' => 1, 'unit_price' => $summe, 'line_total' => $summe, 'purchased_at' => now()->toDateString(), 'source' => 'necta_import']);
    }
    $this->actingAs($this->inhaber);
    $inv = app(InventurService::class);
    $journal = app(PurchaseJournalService::class);

    expect(array_column($inv->bestand($this->rootTeam), 'name'))->toBe(['Mehl']);
    expect($journal->spend($this->rootTeam))->toBe(100.0);

    $this->standorte->setzeBrille($this->rootTeam, 'alle');
    $rows = collect($inv->bestand($this->rootTeam))->keyBy('name');
    expect($rows->keys()->sort()->values()->all())->toBe(['Mehl', 'Zucker'])
        ->and($rows['Zucker']['standort'])->toBe('Kind A')->and($rows['Zucker']['eigen'])->toBeFalse();
    expect($journal->spend($this->rootTeam))->toBe(140.0);

    // Controlling nebeneinander: je Standort mit dessen eigenen Zahlen, Summe bleibt oben
    $je = collect($this->standorte->jeStandort($this->rootTeam, fn ($t) => $journal->spend($t)))->pluck('wert', 'standort')->all();
    expect($je)->toBe(['Root (Katalog-Besitzer)' => 100.0, 'Kind A' => 40.0, 'Kind B' => 0.0])
        ->and($journal->spend($this->rootTeam))->toBe(140.0);
    Livewire::test(\Platform\FoodAlchemist\Livewire\Controlling\Panels\Abweichung::class)
        ->set('von', now()->subDay()->toDateString())->set('bis', now()->toDateString())
        ->assertSeeHtml('data-ctrl-abw-standort="'.$this->childA->id.'"');

    // Ohne angemeldeten Benutzer (Jobs, Signale) gilt immer „eigen"
    auth()->logout();
    expect($journal->spend($this->rootTeam))->toBe(100.0);
});

it('Oberfläche: Standort-Spalte nur bei Brille „alle"; Betrieb je Standort in den Einstellungen', function () {
    $ortA = FoodAlchemistInventoryLocation::create(['team_id' => $this->childA->id, 'name' => 'Lager A', 'type' => 'warehouse', 'is_default' => true, 'is_active' => true]);
    FoodAlchemistInventoryStock::create(['team_id' => $this->childA->id, 'inventory_location_id' => $ortA->id, 'gp_id' => $this->gp['Zucker']->id, 'qty_base' => 2000, 'base_unit' => 'g']);
    $this->actingAs($this->inhaber);

    Livewire::test(LagerIndex::class)->assertDontSeeHtml('data-standort');
    $this->standorte->setzeBrille($this->rootTeam, 'alle');
    Livewire::test(LagerIndex::class)->assertSeeHtml('data-standort')->assertSee('Kind A');
    // Wareneingang, Rechnungen, Bestellungen, Produktion rendern mit Standort-Spalte (Blade-Fallen @php)
    $this->orders->setStatus($this->childA, $this->bestellungA->id, \Platform\FoodAlchemist\Enums\OrderStatus::Sent);
    Livewire::test(\Platform\FoodAlchemist\Livewire\Wareneingang\Index::class)->assertSeeHtml('data-standort')->assertSee('Kind A');
    Livewire::test(\Platform\FoodAlchemist\Livewire\Wareneingang\Rechnungen::class)->assertOk();
    Livewire::test(\Platform\FoodAlchemist\Livewire\Orders\Index::class)->assertSeeHtml('data-standort');
    Livewire::test(\Platform\FoodAlchemist\Livewire\Produktion\Browser::class)->assertOk();

    Livewire::test(Betriebe::class)
        ->assertSeeHtml('data-standort-betrieb="'.$this->childA->id.'"')
        ->call('standortBetriebSetzen', $this->childA->id, (string) $this->nord->id)
        ->assertSet('fehler', null);
    expect($this->standorte->zugeordneterBetrieb($this->childA)?->id)->toBe($this->nord->id);
});

it('MCP: standorte.GET und SET_BRILLE für jede Rolle, PUT nur Admin des Oberteams', function () {
    $leser = $this->makeUser($this->rootTeam, 'Leser', 'viewer');
    $get = $this->reg->get('foodalchemist.standorte.GET')->execute([], new ToolContext($leser, $this->rootTeam));
    expect($get->success)->toBeTrue()->and(collect($get->data['standorte'])->pluck('name')->all())->toBe(['Kind A', 'Kind B']);

    $b = $this->reg->get('foodalchemist.standorte.SET_BRILLE')->execute(['modus' => 'alle'], new ToolContext($leser, $this->rootTeam));
    expect($b->success)->toBeTrue()->and($b->data['lese_team_ids'])->toContain((int) $this->childA->id);

    $put = fn ($u) => $this->reg->get('foodalchemist.standorte.PUT')->execute(['unter_team_id' => $this->childA->id, 'outlet_id' => $this->nord->id], new ToolContext($u, $this->rootTeam));
    expect($put($this->koch)->errorCode)->toBe('FORBIDDEN');
    expect($put($this->inhaber)->success)->toBeTrue();
});
