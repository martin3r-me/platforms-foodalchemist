<?php

use Livewire\Livewire;
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
});

it('rendert die Kunde-DNA-Sektion (Picker vor Firmen-Wahl, noch kein Board)', function () {
    Livewire::test(KundeDna::class)
        ->assertOk()
        ->assertSet('companyId', null);
});

it('firmaWaehlen initialisiert den crm_company-Canvas + zeigt das Board; firmaLoesen setzt zurück', function () {
    $c = Livewire::test(KundeDna::class)
        ->call('firmaWaehlen', 4242, 'Hotel Adler')
        ->assertSet('companyId', 4242)
        ->assertSet('companyName', 'Hotel Adler')
        ->assertSet('firmaSuche', '')
        ->assertSee('Hotel Adler');

    // canvasInit → canvasLaden → canvasFor (firstOrCreate): der Kunde-DNA-Canvas existiert jetzt.
    $canvas = app(CanvasService::class)->find('kunde_dna', 'crm_company', 4242);
    expect($canvas)->not->toBeNull()
        ->and($canvas->owner_type)->toBe('crm_company')
        ->and((int) $canvas->owner_id)->toBe(4242);

    $c->call('firmaLoesen')->assertSet('companyId', null)->assertSet('companyName', null);
});

it('Kunde-DNA ist als Einstellungen-Sektion registriert', function () {
    expect(\Platform\FoodAlchemist\Livewire\Settings\Index::SEKTIONEN)->toHaveKey('kunde-dna');
});
