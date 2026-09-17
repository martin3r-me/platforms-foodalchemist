<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Livewire\ReviewQueue;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G (Spec 53), Restliste — dieselbe Umstellung wie im Recipe-/VK-Modal
 * ({@see \tests\Feature\KiFeedbackRecipeModalTest}), für den KI-Fix-Knopf
 * in der Signal-Zeile (_signal-row.blade.php) und den Copilot-Batch-Lauf
 * (review-queue.blade.php „KI-Befunde sammeln"). `toggleKiPanel` selbst
 * ist kein KI-Call (öffnet nur das Panel) — nicht im Scope.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $this->signals = app(SignalService::class);
});

it('review-queue: „KI-Befunde sammeln" (befundeLaufen) hat data-ki-action + wire:target', function () {
    $html = Livewire::test(ReviewQueue::class)->html();

    expect($html)->toContain('data-ki-action="befundeLaufen"')
        ->toContain('wire:target="befundeLaufen"');
});

it('Signal-Zeile: „Entwurf erzeugen" (kiFixAusfuehren) hat data-ki-action + wire:target', function () {
    $sig = $this->signals->erzeuge($this->rootTeam, SignalTyp::MargeUnterZiel, SignalSeverity::Warnung, 'Marge unter Ziel');

    $t = Livewire::test(ReviewQueue::class)->call('toggleKiPanel', $sig->id);

    expect($t->html())->toContain('data-ki-action="kiFixAusfuehren('.$sig->id.')"')
        ->toContain('wire:target="kiFixAusfuehren"');
});
