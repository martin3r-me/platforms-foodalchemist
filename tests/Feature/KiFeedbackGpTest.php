<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G (Spec 53) — dieselbe Umstellung wie im Recipe-/VK-Modal
 * ({@see \tests\Feature\KiFeedbackRecipeModalTest}), fürs GP-Detail-Panel + GP-Modal.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $this->gp = $this->makeGp($this->rootTeam, 'Zander: TK, Filet');
    $this->gp->update(['status' => 'approved']);
});

it('Detail-Panel: „KI-Vorschlag" (laVorschlaege) hat data-ki-action + wire:target', function () {
    $html = Livewire::test(DetailPanel::class, ['gpId' => $this->gp->id])->html();

    expect($html)->toContain('data-ki-action="laVorschlaege"')
        ->toContain('wire:target="laVorschlaege"');
});

it('Detail-Panel: „per KI schätzen" (kiAllergene) hat data-ki-action + wire:target', function () {
    $html = Livewire::test(DetailPanel::class, ['gpId' => $this->gp->id])->html();

    expect($html)->toContain('data-ki-action="kiAllergene"')
        ->toContain('wire:target="kiAllergene"');
});

it('GP-Modal: „Alles anreichern" hat data-ki-action + wire:target', function () {
    $html = Livewire::test(GpModal::class)->call('oeffnen', $this->gp->id)->html();

    expect($html)->toContain('data-ki-action="allesAnreichern"')
        ->toContain('wire:target="allesAnreichern"');
});

it('GP-Modal: „KI schätzen" (formenKiSchaetzen) hat data-ki-action + wire:target', function () {
    $html = Livewire::test(GpModal::class)->call('oeffnen', $this->gp->id)->html();

    expect($html)->toContain('data-ki-action="formenKiSchaetzen"')
        ->toContain('wire:target="formenKiSchaetzen"');
});
