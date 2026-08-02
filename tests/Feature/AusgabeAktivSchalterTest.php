<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\AusgabeStatus;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as FoodbookEditor;
use Platform\FoodAlchemist\Livewire\Speisekarte\Index as SpeisekarteEditor;
use Platform\FoodAlchemist\Livewire\Speiseplan\Editor as SpeiseplanEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 33 · P5 — der Aktiv/Inaktiv-Schalter an allen drei Ausgabeformen.
 *
 * Der Schalter ist das Gegenstück zum Gültigkeitsfenster: das Fenster bremst automatisch (und
 * ändert nichts), der Schalter ist der bewusste Griff, um etwas kurzfristig vom Netz zu nehmen —
 * **ohne** es zu archivieren. Ohne ihn gäbe es nur die Wahl zwischen „läuft" und „abgeschlossen".
 *
 * Geschrieben wird in allen drei Fällen über den jeweils eigenen Service, damit Team-Guard,
 * Status-Normalisierung und Audit dort bleiben, wo sie hingehören.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
});

it('nimmt ein laufendes Foodbook vom Netz und wieder zurück', function () {
    $fb = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'FB', 'status' => 'aktiv']);

    $lw = Livewire::test(FoodbookEditor::class)->call('waehle', $fb->id)->call('aktivUmschalten');
    expect($fb->refresh()->statusWert())->toBe(AusgabeStatus::Inaktiv)
        ->and($fb->laeuftAm())->toBeFalse();

    $lw->call('aktivUmschalten');
    expect($fb->refresh()->statusWert())->toBe(AusgabeStatus::Aktiv);
});

it('schaltet die Speisekarte um', function () {
    $karte = app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'K', 'status' => 'aktiv']);

    Livewire::test(SpeisekarteEditor::class)->call('waehle', $karte->id)->call('aktivUmschalten');

    expect($karte->refresh()->statusWert())->toBe(AusgabeStatus::Inaktiv);
});

it('schaltet den Speiseplan um', function () {
    $plan = app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'P', 'status' => 'aktiv']);

    Livewire::test(SpeiseplanEditor::class)->call('oeffnenBearbeiten', $plan->id)->call('aktivUmschalten');

    expect($plan->refresh()->statusWert())->toBe(AusgabeStatus::Inaktiv);
});

it('hebt einen Entwurf direkt auf aktiv', function () {
    // Aus jedem nicht-laufenden Zustand führt der Schalter nach `aktiv` — er ist kein
    // Zweierschalter zwischen genau zwei Werten, sondern „an" bzw. „aus".
    $karte = app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'K', 'status' => 'entwurf']);

    Livewire::test(SpeisekarteEditor::class)->call('waehle', $karte->id)->call('aktivUmschalten');

    expect($karte->refresh()->statusWert())->toBe(AusgabeStatus::Aktiv);
});

it('speichert beide Zuordnungsachsen aus dem Editor', function () {
    $betrieb = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Kantine Nord']);
    $karte = app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'K']);

    Livewire::test(SpeisekarteEditor::class)
        ->call('waehle', $karte->id)
        ->set('outletId', $betrieb->id)
        ->set('kunde', 'Klinikum West')
        ->call('speichern');

    $karte->refresh();
    expect((int) $karte->outlet_id)->toBe((int) $betrieb->id)
        ->and($karte->customer)->toBe('Klinikum West');
});

it('fasst keine fremde Ausgabe an', function () {
    $fremd = app(SpeisekarteService::class)->create($this->childB, ['name' => 'Fremd', 'status' => 'aktiv']);

    Livewire::test(SpeisekarteEditor::class)->call('waehle', $fremd->id)->call('aktivUmschalten');

    expect($fremd->refresh()->statusWert())->toBe(AusgabeStatus::Aktiv);
});

it('bietet keinen toten Betriebs-Auswahlkasten an, wenn keiner gepflegt ist', function () {
    // Ohne gepflegte Betriebe wäre ein leeres Select eine Sackgasse — das Bauteil nennt
    // stattdessen den Weg in die Einstellungen.
    $karte = app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'K']);

    Livewire::test(SpeisekarteEditor::class)->call('waehle', $karte->id)
        ->assertSee('Noch kein Betrieb angelegt')
        ->assertDontSeeHtml('data-ausgabe-outlet');

    FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Kantine Nord']);

    Livewire::test(SpeisekarteEditor::class)->call('waehle', $karte->id)
        ->assertSeeHtml('data-ausgabe-outlet');
});

it('zeigt beim Speiseplan den abgeleiteten Zeitraum statt eines toten Datumsfelds', function () {
    $plan = app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'P']);

    Livewire::test(SpeiseplanEditor::class)->call('oeffnenBearbeiten', $plan->id)
        ->assertSee('Noch keine Einträge')
        ->assertViewHas('fensterHinweis', fn ($h) => str_contains((string) $h, 'ersten und letzten Plantag'));
});
