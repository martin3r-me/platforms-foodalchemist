<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Lager\Index as LagerIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryBatch;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryMovement;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\EigenproduktionService;
use Platform\FoodAlchemist\Services\EtikettService;
use Platform\FoodAlchemist\Services\InventurService;
use Platform\FoodAlchemist\Services\LagerBewegungService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 69 · Eigenproduktion im Lager: Chargen einlagern (Lagerart, Haltbarkeit, Bewertung), FIFO-Entnahme,
 * Ablaufwarnung, Inventur-Abgleich, Etikett je Charge, Controlling (Verderb), MCP, Oberfläche.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(EigenproduktionService::class);
    $t = $this->rootTeam->id;
    // Fond: 10 kg Ertrag, EK 20 € → 0,002 €/g; TK 90 Tage, gekühlt 4 Tage, üblich TK
    $this->fond = FoodAlchemistRecipe::create(['team_id' => $t, 'recipe_key' => 'fond', 'name' => 'Fond: Kalbsfond', 'status' => 'approved', 'is_sales_recipe' => false,
        'yield_kg' => 10.0, 'ek_total_eur' => 20.0, 'storage_type' => 'tiefgekuehlt', 'shelf_life_chilled_days' => 4, 'shelf_life_frozen_days' => 90]);
    // Gericht: 10 Portionen, EK gesamt 30 € → 3 €/Portion
    $this->lasagne = FoodAlchemistRecipe::create(['team_id' => $t, 'recipe_key' => 'lasagne', 'name' => '[HG] Lasagne', 'status' => 'approved', 'is_sales_recipe' => true,
        'sales_unit_count' => 10, 'ek_total_eur' => 30.0, 'shelf_life_chilled_days' => 2]);
    $this->lager = FoodAlchemistInventoryLocation::create(['team_id' => $t, 'name' => 'TK-Raum', 'type' => 'warehouse', 'is_default' => true, 'is_active' => true]);
});

it('Einlagern: Charge mit Nummer, TK-Datum + Haltbarkeit aus dem Rezept, Bestand und Wert', function () {
    $b = $this->svc->einlagern($this->rootTeam, ['recipe_id' => $this->fond->id, 'menge' => '8', 'produziert_am' => '2026-10-01'], $this->user->id);
    expect($b->charge)->toBe('C261001-01')
        ->and($b->storage_type)->toBe('tiefgekuehlt')
        ->and($b->frozen_at->toDateString())->toBe('2026-10-01')
        ->and($b->best_before->toDateString())->toBe('2026-12-30')
        ->and((float) $b->qty_rest)->toBe(8000.0)->and($b->base_unit)->toBe('g')
        ->and((float) $b->price_per_base)->toBe(0.002);
    expect((float) FoodAlchemistInventoryStock::where('recipe_id', $this->fond->id)->value('qty_base'))->toBe(8000.0)
        ->and((float) FoodAlchemistInventoryMovement::where('batch_id', $b->id)->value('value_eur'))->toBe(16.0);

    $b2 = $this->svc->einlagern($this->rootTeam, ['recipe_id' => $this->fond->id, 'menge' => 2, 'produziert_am' => '2026-10-01', 'lagerart' => 'gekuehlt']);
    expect($b2->charge)->toBe('C261001-02')->and($b2->best_before->toDateString())->toBe('2026-10-05');

    $p = $this->svc->einlagern($this->rootTeam, ['recipe_id' => $this->lasagne->id, 'menge' => 12]);
    expect($p->base_unit)->toBe('Port')->and((float) $p->price_per_base)->toBe(3.0)->and($p->storage_type)->toBe('gekuehlt');

    expect(fn () => $this->svc->einlagern($this->rootTeam, ['recipe_id' => $this->fond->id, 'menge' => 0]))->toThrow(\RuntimeException::class);
});

it('Entnahme FIFO nach Haltbarkeit, Verderb zählt im Controlling, zu viel abgelehnt', function () {
    $alt = $this->svc->einlagern($this->rootTeam, ['recipe_id' => $this->fond->id, 'menge' => 3, 'produziert_am' => '2026-09-01']);
    $neu = $this->svc->einlagern($this->rootTeam, ['recipe_id' => $this->fond->id, 'menge' => 5, 'produziert_am' => '2026-10-01']);

    $r = $this->svc->entnehmen($this->rootTeam, ['recipe_id' => $this->fond->id, 'menge' => '4', 'grund' => 'verbrauch']);
    expect(array_column($r, 'charge'))->toBe([$alt->charge, $neu->charge])
        ->and($alt->refresh()->closed_at)->not->toBeNull()
        ->and((float) $neu->refresh()->qty_rest)->toBe(4000.0)
        ->and((float) FoodAlchemistInventoryStock::where('recipe_id', $this->fond->id)->value('qty_base'))->toBe(4000.0);

    $this->svc->entnehmen($this->rootTeam, ['batch_id' => $neu->id, 'menge' => '1', 'grund' => 'verderb']);
    $wert = app(LagerBewegungService::class)->abgaengeWert($this->rootTeam, now()->subDay()->toDateString(), now()->toDateString());
    expect($wert['je_grund']['verderb'])->toBe(2.0);   // 1000 g × 0,002

    expect(fn () => $this->svc->entnehmen($this->rootTeam, ['recipe_id' => $this->fond->id, 'menge' => '9']))->toThrow(\RuntimeException::class, 'Nur 3 kg')
        ->and(fn () => $this->svc->entnehmen($this->childA, ['batch_id' => $neu->id, 'menge' => '1']))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('Ablaufend + Lager je Rezept für die Produktion', function () {
    $bald = $this->svc->einlagern($this->rootTeam, ['recipe_id' => $this->lasagne->id, 'menge' => 5, 'produziert_am' => now()->toDateString()]);   // +2 Tage
    $this->svc->einlagern($this->rootTeam, ['recipe_id' => $this->fond->id, 'menge' => 2]);
    expect($this->svc->ablaufend($this->rootTeam)->pluck('id')->all())->toBe([$bald->id]);
    $l = $this->svc->lagerJeRezept($this->rootTeam, [$this->fond->id, $this->lasagne->id]);
    expect($l[$this->fond->id])->toMatchArray(['menge' => 2000.0, 'base' => 'g', 'chargen' => 1])
        ->and($l[$this->lasagne->id]['base'])->toBe('Port');
});

it('Inventur zählt Rezept-Bestand mit und gleicht die Chargen ab', function () {
    $alt = $this->svc->einlagern($this->rootTeam, ['recipe_id' => $this->fond->id, 'menge' => 3, 'produziert_am' => '2026-09-01']);
    $neu = $this->svc->einlagern($this->rootTeam, ['recipe_id' => $this->fond->id, 'menge' => 5, 'produziert_am' => '2026-10-01']);
    $inv = app(InventurService::class);
    $c = $inv->anlegen($this->rootTeam, $this->lager->id, '2026-10-05');
    $zeile = $c->lines()->where('recipe_id', $this->fond->id)->first();
    expect($zeile)->not->toBeNull()->and((float) $zeile->qty_expected)->toBe(8000.0)->and((float) $zeile->price_per_base)->toBe(0.002);

    $inv->zaehlen($this->rootTeam, $zeile->id, '6');      // 2 kg weniger → älteste Charge zuerst
    $inv->buchen($this->rootTeam, $c->id);
    expect((float) $alt->refresh()->qty_rest)->toBe(1000.0)->and((float) $neu->refresh()->qty_rest)->toBe(5000.0)
        ->and((float) FoodAlchemistInventoryStock::where('recipe_id', $this->fond->id)->value('qty_base'))->toBe(6000.0);
    expect(collect($inv->bestand($this->rootTeam))->firstWhere('name', 'Fond: Kalbsfond')['wert'])->toBe(12.0);
});

it('Etikett je Charge übernimmt Charge, Daten und Menge', function () {
    $b = $this->svc->einlagern($this->rootTeam, ['recipe_id' => $this->fond->id, 'menge' => 8, 'produziert_am' => '2026-10-01']);
    $d = app(EtikettService::class)->daten($this->rootTeam, 'charge', $b->id);
    expect($d['bezeichnung'])->toBe('Kalbsfond')->and($d['charge'])->toBe('C261001-01')
        ->and($d['datum']['verbrauchen_bis']->toDateString())->toBe('2026-12-30')
        ->and($d['datum']['eingefroren_am']->toDateString())->toBe('2026-10-01')
        ->and($d['menge'])->toBe('8 kg')->and($d['lagerung'])->toBe('tiefgekuehlt');
    $this->get(app(EtikettService::class)->druckUrl('charge', $b->id))->assertOk()->assertSee('C261001-01');
});

it('MCP: eigenproduktion.POST → inventory_batches.GET → eigenproduktion.ENTNAHME', function () {
    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $p = $reg->get('foodalchemist.eigenproduktion.POST')->execute(['recipe_id' => $this->fond->id, 'menge' => 8, 'produziert_am' => '2026-10-01'], $ctx);
    expect($p->success)->toBeTrue()->and($p->data['charge'])->toBe('C261001-01')->and($p->data['etikett_url'])->toContain('quelle=charge');

    $g = $reg->get('foodalchemist.inventory_batches.GET')->execute(['recipe_id' => $this->fond->id], $ctx);
    expect($g->data['chargen'][0])->toMatchArray(['rest' => 8.0, 'einheit' => 'kg', 'verbrauchen_bis' => '2026-12-30', 'wert_eur' => 16.0]);

    $e = $reg->get('foodalchemist.eigenproduktion.ENTNAHME')->execute(['recipe_id' => $this->fond->id, 'menge' => 2], $ctx);
    expect($e->success)->toBeTrue()->and($e->data['entnommen'][0]['menge_basis'])->toBe(2000.0);
    expect($reg->get('foodalchemist.eigenproduktion.ENTNAHME')->execute(['recipe_id' => $this->fond->id, 'menge' => 99], $ctx)->success)->toBeFalse();
});

it('Oberfläche: aus der Produktion vorbelegt einlagern, Chargen-Liste, Entnehmen', function () {
    $lw = Livewire::withQueryParams(['reiter' => 'eigenproduktion', 'einlagern_rezept' => $this->fond->id, 'einlagern_menge' => '4'])
        ->test(LagerIndex::class)
        ->assertSet('einlagern.recipe_id', $this->fond->id)->assertSet('einlagern.menge', '4')->assertSet('einlagern.lagerart', 'tiefgekuehlt')
        ->call('einlagernSpeichern')->assertSet('fehler', null)->assertSee('Eingelagert: Charge')
        ->assertSee('Kalbsfond')->assertSee('Etiketten für die neue Charge drucken');
    $b = FoodAlchemistInventoryBatch::first();
    $lw->set("entnahme.{$b->id}.menge", '1,5')->set("entnahme.{$b->id}.grund", 'verbrauch')->call('entnehmen', $b->id)->assertSet('fehler', null);
    expect((float) $b->refresh()->qty_rest)->toBe(2500.0);
});
