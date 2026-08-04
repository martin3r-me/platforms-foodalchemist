<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Betriebe;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\Crm\Models\CrmCompany;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 33 · P2 — beide Zuordnungsachsen an allen drei Ausgabeformen.
 *
 * Der erste Entwurf teilte die Achsen auf (Foodbook = Kunde, Karte/Plan = Standort). Das war
 * falsch: ein Foodbook kann an einem Standort hängen, und eine Karte oder ein Plan kann für
 * einen Kunden gemacht sein — Betreibermodell. Beide Achsen, überall, beide optional.
 *
 * Beim Bauen kam heraus, dass die Outlet-Achse bis dahin **tot** war: die Tabelle existiert seit
 * Spec 19, das Model verspricht „team-pflegbar über die Einstellungen", aber diese Oberfläche
 * wurde nie gebaut — 0 Datensätze im Dev-Bestand, und `outlet_id` an der Speisekarte hatte
 * nicht einmal ein Eingabefeld.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);

    $this->betrieb = FoodAlchemistOutlet::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Kantine Nord',
    ]);
    $this->kunde = CrmCompany::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Klinikum West', 'is_active' => true,
    ]);
});

it('nimmt an allen drei Formen Betrieb UND Kunde an', function () {
    $fb = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'FB']);
    app(FoodbookService::class)->update($this->rootTeam, (int) $fb->id, [
        'outlet_id' => $this->betrieb->id, 'crm_company_id' => $this->kunde->id,
    ]);

    $karte = app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'Karte']);
    app(SpeisekarteService::class)->update($this->rootTeam, (int) $karte->id, [
        'outlet_id' => $this->betrieb->id, 'crm_company_id' => $this->kunde->id,
    ]);

    $plan = app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'Plan']);
    app(SpeiseplanService::class)->update($this->rootTeam, (int) $plan->id, [
        'outlet_id' => $this->betrieb->id, 'crm_company_id' => $this->kunde->id,
    ]);

    foreach ([$fb->refresh(), $karte->refresh(), $plan->refresh()] as $ausgabe) {
        $ausgabe->load('crmCompany');
        expect((int) $ausgabe->outlet_id)->toBe((int) $this->betrieb->id)
            ->and((int) $ausgabe->crm_company_id)->toBe((int) $this->kunde->id)
            ->and($ausgabe->outlet->name)->toBe('Kantine Nord')
            ->and($ausgabe->hatZuordnung())->toBeTrue()
            ->and($ausgabe->kundeLabel())->toBe('Klinikum West');
    }
});

it('lässt beide Achsen leer — eine freistehende Ausgabe bleibt anlegbar', function () {
    $karte = app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'Freistehend']);

    expect($karte->outlet_id)->toBeNull()
        ->and($karte->hatZuordnung())->toBeFalse()
        ->and($karte->kundeLabel())->toBeNull();
});

it('erkennt eine Zuordnung auch, wenn nur eine der beiden Achsen gesetzt ist', function () {
    $nurBetrieb = app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'A', 'outlet_id' => $this->betrieb->id]);
    $nurKunde = app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'B', 'crm_company_id' => $this->kunde->id]);

    expect($nurBetrieb->hatZuordnung())->toBeTrue()
        ->and($nurKunde->hatZuordnung())->toBeTrue();
});

// ── Pflege der Betriebe ──────────────────────────────────────────────────────

it('legt Betriebe an und verhindert doppelte Namen', function () {
    Livewire::test(Betriebe::class)
        ->set('neuName', 'Bistro Süd')->call('anlegen')
        ->assertSet('fehler', null);

    expect(FoodAlchemistOutlet::where('team_id', $this->rootTeam->id)->count())->toBe(2);

    // Unique ist (team_id, name) — ohne Vorprüfung liefe der Nutzer in einen SQL-Fehler.
    Livewire::test(Betriebe::class)
        ->set('neuName', 'Bistro Süd')->call('anlegen')
        ->assertSet('fehler', 'Es gibt bereits einen Betrieb mit diesem Namen.');

    expect(FoodAlchemistOutlet::where('team_id', $this->rootTeam->id)->count())->toBe(2);
});

it('schaltet inaktiv statt zu löschen', function () {
    // An einem Betrieb hängen Ausgaben und Kapitel — eine Löschung würde die Zuordnungen
    // stillschweigend kappen.
    Livewire::test(Betriebe::class)->call('aktivUmschalten', $this->betrieb->id);

    expect($this->betrieb->refresh()->is_inactive)->toBeTrue()
        ->and(FoodAlchemistOutlet::find($this->betrieb->id))->not->toBeNull();
});

it('nimmt nur echtes Hex als Farbe an', function () {
    Livewire::test(Betriebe::class)
        ->call('edit', $this->betrieb->id)
        ->set('form.color', 'rot')->call('speichern');
    expect($this->betrieb->refresh()->color)->toBeNull();

    Livewire::test(Betriebe::class)
        ->call('edit', $this->betrieb->id)
        ->set('form.color', '#123abc')->call('speichern');
    expect($this->betrieb->refresh()->color)->toBe('#123abc');
});

it('zeigt, wo ein Betrieb schon benutzt wird', function () {
    app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'K1', 'outlet_id' => $this->betrieb->id]);
    app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'P1', 'outlet_id' => $this->betrieb->id]);

    Livewire::test(Betriebe::class)->assertViewHas('nutzung', fn ($n) => ($n[$this->betrieb->id]['Speisekarten'] ?? 0) === 1
        && ($n[$this->betrieb->id]['Speisepläne'] ?? 0) === 1);
});

it('fasst keinen fremden Betrieb an', function () {
    $fremd = FoodAlchemistOutlet::create(['team_id' => $this->childB->id, 'name' => 'Fremd']);

    Livewire::test(Betriebe::class)
        ->call('edit', $fremd->id)->assertSet('editId', null)
        ->call('aktivUmschalten', $fremd->id);

    expect($fremd->refresh()->is_inactive)->toBeFalse()
        ->and($fremd->name)->toBe('Fremd');
});

it('zeigt keine fremden Betriebe in der Liste', function () {
    FoodAlchemistOutlet::create(['team_id' => $this->childB->id, 'name' => 'Geheimer Betrieb']);

    Livewire::test(Betriebe::class)
        ->assertDontSee('Geheimer Betrieb')
        ->assertSee('Kantine Nord');
});
