<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\Browser;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G (Spec 53), Restliste — Bulk-Anreichern im Rezept-Browser. Startet
 * einen Hintergrund-Batch (BulkEnrichService::starte) und kehrt sofort
 * zurück — die eigentliche Fortschrittsanzeige (`wire:poll.2s`) hält die
 * "kein Poll im Ruhezustand"-Regel bereits ein (`@if($run->status === 'running')`,
 * Zeile data-bulk-progress) — kein Code-Fix nötig, nur die sofortige
 * Klick-Rückmeldung fehlte.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'bulk_test', 'name' => 'Bulk-Test-Rezept', 'status' => 'approved',
    ]);
});

it('Rezept-Browser: „Bulk anreichern" hat data-ki-action + wire:target, sobald eine Zeile markiert ist', function () {
    $t = Livewire::test(Browser::class)->set('auswahl.'.$this->rezept->id, true);

    expect($t->html())->toContain('data-ki-action="bulkAnreichern"')
        ->toContain('wire:target="bulkAnreichern"');
});
