<?php

use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #4 (Bug-Runde 2026-08), Teil 1 „Vorhandenes überschreiben": der bewusste „Alles anreichern"-
 * Klick (refresh) soll auch schon GEFÜLLTE, nicht-manuelle Textfelder neu erzeugen (nach
 * Zutatenänderung) — vorher füllte er via `luecken()` nur leere Felder. Manuelle Pflege bleibt
 * geschützt. Getestet wird die deterministische Gate-Logik am voll kontrollierten `description`-Feld.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $this->svc = app(BulkEnrichService::class);
});

it('#4: Refresh frischt gefülltes NICHT-manuelles Feld auf, Gap-Pass lässt es liegen', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Refresh-Test', [
        'status' => 'draft', 'description' => 'alter KI-Text', 'description_source' => 'ki',
    ])->fresh();

    expect($this->svc->zuAktualisieren($r, ['description']))->toContain('description')  // Refresh: mit
        ->and($this->svc->luecken($r, ['description']))->not->toContain('description');   // Gap-Pass: nicht
});

it('#4: manuell gepflegtes Feld bleibt in BEIDEN Gates geschützt (Override-First)', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Manual-Schutz', [
        'status' => 'draft', 'description' => 'von Hand geschrieben', 'description_source' => 'manual',
    ])->fresh();

    expect($this->svc->zuAktualisieren($r, ['description']))->not->toContain('description')
        ->and($this->svc->luecken($r, ['description']))->not->toContain('description');
});

it('#4: leeres Feld nehmen BEIDE Gates mit', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Leer', [
        'status' => 'draft', 'description' => null, 'description_source' => null,
    ])->fresh();

    expect($this->svc->zuAktualisieren($r, ['description']))->toContain('description')
        ->and($this->svc->luecken($r, ['description']))->toContain('description');
});
