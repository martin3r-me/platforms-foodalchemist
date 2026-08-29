<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Fix (2026-08-29): *.SEARCH mit LEERER Query gab bisher 0 Treffer zurück (Falle: „nichts vorhanden",
 * obwohl Daten da sind — real beobachtet mit foodbooks.SEARCH auf demo). Jetzt: leere Query = alles
 * sichtbare listen (gedeckelt), via='list'. Betrifft foodbooks/suppliers/lab_notes (SEARCH ohne bzw.
 * ohne verlässliches LIST-Pendant).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->run = fn (string $n, array $a) => $this->registry->get($n)->execute($a, $this->kontext);

    app(FoodbookService::class)->create($this->rootTeam, ['label' => 'Foodbook 2027']);
    FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs Culinar']);
});

it('foodbooks.SEARCH: leere Query listet alle (via=list), Nicht-Treffer bleibt 0', function () {
    $all = ($this->run)('foodalchemist.foodbooks.SEARCH', ['q' => '']);
    expect($all->success)->toBeTrue()
        ->and($all->data['total'])->toBeGreaterThanOrEqual(1)
        ->and(collect($all->data['foodbooks'])->pluck('label')->all())->toContain('Foodbook 2027')
        ->and($all->data['foodbooks'][0]['via'])->toBe('list');

    // „Broich" traf nicht, weil das Foodbook „Foodbook 2027" heißt — korrektes 0 bei echtem Stichwort.
    expect(($this->run)('foodalchemist.foodbooks.SEARCH', ['q' => 'Broich-zzz'])->data['total'])->toBe(0);
});

it('suppliers.SEARCH: leere Query listet alle (kein LIST-Pendant), via=list', function () {
    $all = ($this->run)('foodalchemist.suppliers.SEARCH', ['q' => '']);
    expect($all->success)->toBeTrue()
        ->and($all->data['total'])->toBeGreaterThanOrEqual(1)
        ->and(collect($all->data['suppliers'])->pluck('name')->all())->toContain('Chefs Culinar')
        ->and($all->data['suppliers'][0]['via'])->toBe('list');

    expect(($this->run)('foodalchemist.suppliers.SEARCH', ['q' => 'zzz-nomatch'])->data['total'])->toBe(0);
});
