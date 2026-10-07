<?php

use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\Crm\Models\CrmCompany;
use Platform\FoodAlchemist\Livewire\Settings\KundeDna;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 42 F3 — Kunde-DNA als Einstellungen-Sektion. Der Autoren-Canvas (owner_type=crm_company)
 * zog aus dem entfernten Foodbook-DNA-Tab hierher; Firma wählen → geteiltes Board.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->firma = CrmCompany::create(['team_id' => $this->rootTeam->id, 'name' => 'Hotel Adler', 'is_active' => true]);
    $fremdTeam = Team::create(['name' => 'Fremder Kunde', 'user_id' => 1, 'personal_team' => false]);
    $this->fremd = CrmCompany::create(['team_id' => $fremdTeam->id, 'name' => 'Adler Catering (fremd)', 'is_active' => true]);
});

it('rendert die Kunde-DNA-Sektion (Picker vor Firmen-Wahl, noch kein Board)', function () {
    Livewire::test(KundeDna::class)
        ->assertOk()
        ->assertSet('companyId', null);
});

it('firmaWaehlen initialisiert den crm_company-Canvas + zeigt das Board; firmaLoesen setzt zurück', function () {
    $id = (int) $this->firma->id;
    $c = Livewire::test(KundeDna::class)
        ->call('firmaWaehlen', $id)
        ->assertSet('companyId', $id)
        ->assertSet('companyName', 'Hotel Adler')
        ->assertSet('firmaSuche', '')
        ->assertSee('Hotel Adler');

    // canvasInit → canvasLaden → canvasFor (firstOrCreate): der Kunde-DNA-Canvas existiert jetzt.
    $canvas = app(CanvasService::class)->find('kunde_dna', 'crm_company', $id);
    expect($canvas)->not->toBeNull()
        ->and($canvas->owner_type)->toBe('crm_company')
        ->and((int) $canvas->owner_id)->toBe($id);

    $c->call('firmaLoesen')->assertSet('companyId', null)->assertSet('companyName', null);
});

it('Kunde-DNA ist als Einstellungen-Sektion registriert', function () {
    expect(\Platform\FoodAlchemist\Livewire\Settings\Index::SEKTIONEN)->toHaveKey('kunde-dna');
});

/* Einstieg aus dem CRM (2026-10-07): ?firma=<id> öffnet die Firma direkt, nur Firmen des eigenen Haupt-Teams. */
it('?firma= öffnet die eigene Firma direkt, Name aus der DB', function () {
    Livewire::withQueryParams(['firma' => $this->firma->id])
        ->test(KundeDna::class)
        ->assertSet('companyId', (int) $this->firma->id)
        ->assertSet('companyName', 'Hotel Adler')
        ->assertSee('Hotel Adler');
});

it('?firma= mit fremder Firma öffnet nichts und legt keinen Canvas an', function () {
    Livewire::withQueryParams(['firma' => $this->fremd->id])
        ->test(KundeDna::class)
        ->assertSet('companyId', null)
        ->assertSee('gibt es in deiner Kundenverwaltung nicht');

    expect(app(CanvasService::class)->find('kunde_dna', 'crm_company', (int) $this->fremd->id))->toBeNull();
});

it('firmaWaehlen mit fremder ID (manipulierter Klick) wird abgelehnt', function () {
    Livewire::test(KundeDna::class)
        ->call('firmaWaehlen', (int) $this->fremd->id)
        ->assertSet('companyId', null)
        ->assertSet('firmaFehler', 'Diese Firma gibt es in deiner Kundenverwaltung nicht.');
});
