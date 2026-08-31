<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Herstellkosten;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\FixkostenService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — Fixkosten je Betrieb: der Betrieb-Wähler in der Herstellkosten-Sektion scopet die
 * Fixkosten-Liste. Betriebs-Zeilen ersetzen pro Block die Team-Zeilen (Per-Block-Replace),
 * Blöcke ohne eigene Zeile erben das Team. Fremder Betrieb → Team-Standard (Tenancy-Guard).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->childA);
    $this->actingAs($this->user);
    $this->fix = app(FixkostenService::class);
    $this->betrieb = FoodAlchemistOutlet::create(['team_id' => $this->childA->id, 'name' => 'Kantine Süd']);
    // Team-Fixkosten: Block gemeinkosten = 1000 €/Monat.
    $this->fix->create($this->childA, ['label' => 'Miete', 'amount' => 1000, 'periode' => 'monatlich', 'block_key' => 'gemeinkosten']);
});

it('Team-Standard zeigt Team-Fixkosten; Betrieb-Scope ohne eigene Zeilen = Σ 0 (KEINE Vererbung)', function () {
    $c = Livewire::test(Herstellkosten::class);
    expect($c->get('fixListe'))->toHaveCount(1);   // Team-Miete

    $c->set('outletId', $this->betrieb->id);
    expect($c->get('fixListe'))->toHaveCount(0);    // keine eigenen Betriebs-Zeilen
    // Keine Vererbung: der Betrieb zählt NUR eigene Zeilen → gemeinkosten-Block nicht vorhanden (0).
    expect($this->fix->summeJeBlock($this->childA, $this->betrieb->fresh()))->toBe([]);
});

it('fixHinzu mit gewähltem Betrieb legt eine Betriebs-Zeile an; ersetzt den Block, Team bleibt unberührt', function () {
    $c = Livewire::test(Herstellkosten::class)
        ->set('outletId', $this->betrieb->id)
        ->set('neuFix', ['label' => 'Miete Süd', 'amount' => '2000', 'periode' => 'monatlich', 'block_key' => 'gemeinkosten'])
        ->call('fixHinzu');

    expect($c->get('fixListe'))->toHaveCount(1);   // jetzt die eigene Betriebs-Zeile
    // Betrieb ersetzt den Block: 2000; Team-Standard bleibt 1000.
    expect($this->fix->summeJeBlock($this->childA, $this->betrieb->fresh())['gemeinkosten'])->toBe(2000.0)
        ->and($this->fix->summeJeBlock($this->childA, null)['gemeinkosten'])->toBe(1000.0);
});

it('fremdes Betrieb (childB) wird nicht als Scope akzeptiert → Team-Standard', function () {
    $betriebB = FoodAlchemistOutlet::create(['team_id' => $this->childB->id, 'name' => 'Fremd']);
    $c = Livewire::test(Herstellkosten::class)->set('outletId', $betriebB->id);
    // fixOutlet() findet den fremden Betrieb nicht (team-scope) → Team-Zeilen sichtbar.
    expect($c->get('fixListe'))->toHaveCount(1);
});
