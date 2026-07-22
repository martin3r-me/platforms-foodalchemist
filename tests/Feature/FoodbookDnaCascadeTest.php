<?php

use Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\FoodbookService;
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

it('Phase 4: cascadeKontext injiziert den Sprach-Duktus des Default-Schreibstils (Tonalität wirkt)', function () {
    $stil = \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::create([
        'team_id' => $this->rootTeam->id,
        'slug' => 'locker-du',
        'name' => 'Locker (Du)',
        'sprach_duktus' => 'Du-Ansprache, sinnliche Verben, keine Superlative.',
        'is_inactive' => false,
        'sort_order' => 0,
    ]);
    $companyId = 5150;
    $canvas = $this->svc->canvasFor($this->rootTeam, 'kunde_dna', 'crm_company', $companyId);
    $this->svc->setSkalar($canvas, 'default_schreibstil_id', (string) $stil->id);

    $ctx = $this->svc->cascadeKontext($this->rootTeam, null, null, null, $companyId);
    // Nicht nur der Stil-NAME, sondern der Sprach-Duktus (Prompt-Material) muss drin sein.
    expect($ctx['marken_kontext'] ?? '')->toContain('Sprach-Duktus')
        ->and($ctx['marken_kontext'])->toContain('sinnliche Verben');
});

it('Foodbook-Override: foodbook.writing_style_id führt über den Kunde-Default-Schreibstil', function () {
    $kundenStil = FoodAlchemistWritingStyle::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'kunde-stil', 'name' => 'Kunde-Stil',
        'sprach_duktus' => 'KUNDE-DUKTUS-MARKER', 'is_inactive' => false, 'sort_order' => 0,
    ]);
    $foodbookStil = FoodAlchemistWritingStyle::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'foodbook-stil', 'name' => 'Foodbook-Stil',
        'sprach_duktus' => 'FOODBOOK-DUKTUS-MARKER', 'is_inactive' => false, 'sort_order' => 1,
    ]);

    $companyId = 6161;
    $kundeCanvas = $this->svc->canvasFor($this->rootTeam, 'kunde_dna', 'crm_company', $companyId);
    $this->svc->setSkalar($kundeCanvas, 'default_schreibstil_id', (string) $kundenStil->id);

    $fbSvc = app(FoodbookService::class);
    $fb = $fbSvc->create($this->rootTeam, ['label' => 'Adler-Gala']);
    $fbSvc->update($this->rootTeam, $fb->id, ['writing_style_id' => $foodbookStil->id]);

    $mk = $this->svc->cascadeKontext($this->rootTeam, null, $fb->id, null, $companyId)['marken_kontext'] ?? '';
    // Beide Ebenen drin, Foodbook-Override als LETZTER Block (führt).
    expect($mk)->toContain('KUNDE-DUKTUS-MARKER')
        ->and($mk)->toContain('FOODBOOK-DUKTUS-MARKER')
        ->and($mk)->toContain('führt — überschreibt')
        ->and(strpos($mk, 'FOODBOOK-DUKTUS-MARKER'))->toBeGreaterThan(strpos($mk, 'KUNDE-DUKTUS-MARKER'));

    // Override zurücknehmen → kein Foodbook-Tonalitäts-Block mehr.
    $fbSvc->update($this->rootTeam, $fb->id, ['writing_style_id' => null]);
    $mk2 = $this->svc->cascadeKontext($this->rootTeam, null, $fb->id, null, $companyId)['marken_kontext'] ?? '';
    expect($mk2)->not->toContain('FOODBOOK-DUKTUS-MARKER')
        ->and($mk2)->toContain('KUNDE-DUKTUS-MARKER');
});
