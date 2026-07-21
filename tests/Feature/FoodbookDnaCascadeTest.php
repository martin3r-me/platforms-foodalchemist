<?php

use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Phase 2: 3-Ebenen-DNA (Team → Kunde → Foodbook). Prüft das kunde_dna-Template
 * und dass cascadeKontext die Kunde-Ebene (owner_type=crm_company) in den KI-Kontext zieht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(CanvasService::class);
});

it('kunde_dna-Template existiert mit den Kern-Feldern', function () {
    $tpl = $this->svc->template('kunde_dna');
    $keys = collect($tpl['felder'])->pluck('key');
    expect($keys)->toContain('marke_positionierung', 'kommunikation_ton', 'default_schreibstil_id', 'erwartungen_nogos', 'preis_erwartung');
});

it('cascadeKontext zieht die Kunde-Ebene, wenn eine crm_company_id übergeben wird', function () {
    $companyId = 4242;
    $canvas = $this->svc->canvasFor($this->rootTeam, 'kunde_dna', 'crm_company', $companyId);
    $this->svc->setSkalar($canvas, 'kommunikation_ton', 'Locker, du-Ansprache, keine Superlative.');

    $mit = $this->svc->cascadeKontext($this->rootTeam, null, null, null, $companyId);
    expect($mit)->toHaveKey('marken_kontext')
        ->and($mit['marken_kontext'])->toContain('Locker, du-Ansprache');

    // Ohne crm_company_id darf die Kunde-Ebene NICHT auftauchen (Rückwärtskompatibilität).
    $ohne = $this->svc->cascadeKontext($this->rootTeam, null, null, null, null);
    expect($ohne['marken_kontext'] ?? '')->not->toContain('Locker, du-Ansprache');
});
