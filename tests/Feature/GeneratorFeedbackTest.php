<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Livewire\Recipes\GeneratorModal;
use Platform\FoodAlchemist\Livewire\Verkauf\VkGeneratorModal;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Phase 0 — der KI-Generier-Knopf darf NIE stumm bleiben.
 *
 * Anlass (Dominique, demo): „ich drücke, nichts passiert" — kein Spinner, keine
 * Meldung, kein Fehler. Ursache: `starteLauf()` setzte den sichtbaren Zustand
 * ERST nach Cache::put + dispatch (beide an DB/Queue), ohne try/catch und ohne
 * Toast; und ein auf `pending` hängender Lauf (kein Worker) pollte endlos ohne
 * Aussage. Diese Tests sichern die drei Rückmelde-Kanäle: Start-Toast,
 * Guard-Fehler beim Dispatch, Watchdog-Hinweis, Fehler-Toast — plus das
 * Stufen-Label als Fortschritt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Queue::fake();
});

it('meldet den Start sofort (Toast) — der Klick ist nie stumm', function () {
    Livewire::test(GeneratorModal::class)
        ->set('description', 'Dunkle Rotwein-Schalotten-Reduktion')
        ->call('generieren')
        ->assertSet('laeuft', true)
        ->assertDispatched('fa-saved', type: 'success');

    Queue::assertPushed(GenerateRecipeJob::class);
});

it('VK-Fläche meldet den Start ebenso (geteilter Trait)', function () {
    Livewire::test(VkGeneratorModal::class)
        ->set('description', 'Geschmortes Rind mit Wurzelgemüse')
        ->call('generieren')
        ->assertSet('laeuft', true)
        ->assertDispatched('fa-saved', type: 'success');
});

it('sagt es, wenn die Infra beim Start kippt — statt stumm zu 500en', function () {
    // Cache/Queue/DB fällt beim Dispatch aus: früher brach die Action lautlos ab.
    Cache::shouldReceive('get')->andReturnNull();
    Cache::shouldReceive('put')->andThrow(new \RuntimeException('DB/Queue nicht erreichbar'));

    Livewire::test(GeneratorModal::class)
        ->set('description', 'Kalbsfond')
        ->call('generieren')
        ->assertSet('laeuft', false)
        ->assertSet('runId', null)
        ->assertSee('konnte nicht gestartet werden')
        ->assertDispatched('fa-saved', type: 'error');

    Queue::assertNothingPushed();   // Dispatch nie erreicht → kein halber Lauf
});

it('warnt der Watchdog, wenn der Lauf ungewöhnlich lange auf pending hängt (kein Worker)', function () {
    $comp = Livewire::test(GeneratorModal::class)
        ->set('description', 'Kalbsfond')
        ->call('generieren');

    $runId = $comp->get('runId');

    // Frisch gestartet → noch kein Hinweis, Spinner ruhig.
    Cache::put(GenerateRecipeJob::cacheKey($runId), [
        'status' => 'pending', 'gestartet_at' => now()->timestamp,
    ], now()->addMinutes(5));
    $comp->call('pruefeErgebnis')
        ->assertSet('laeuft', true)
        ->assertSet('hinweis', null);

    // Lange her → Watchdog schlägt an, aber KEIN Abbruch (weiter pollen).
    Cache::put(GenerateRecipeJob::cacheKey($runId), [
        'status' => 'pending', 'gestartet_at' => now()->subMinutes(3)->timestamp,
    ], now()->addMinutes(5));
    $comp->call('pruefeErgebnis')
        ->assertSet('laeuft', true)
        ->assertSee('ungewöhnlich lange');
});

it('wirft bei einem Fehler-Status zusätzlich einen Fehler-Toast', function () {
    $comp = Livewire::test(GeneratorModal::class)
        ->set('description', 'Irgendwas')
        ->call('generieren');

    Cache::put(GenerateRecipeJob::cacheKey($comp->get('runId')), [
        'status' => 'error', 'fehler' => 'KI-Provider ist deaktiviert.',
    ], now()->addMinutes(5));

    $comp->call('pruefeErgebnis')
        ->assertSet('laeuft', false)
        ->assertSet('fehler', 'KI-Provider ist deaktiviert.')
        ->assertDispatched('fa-saved', type: 'error');
});

it('zeigt eine gemeldete Job-Stufe als Fortschritt im Spinner', function () {
    $comp = Livewire::test(GeneratorModal::class)
        ->set('description', 'Kalbsfond')
        ->call('generieren');

    Cache::put(GenerateRecipeJob::cacheKey($comp->get('runId')), [
        'status' => 'pending', 'progress' => 'Rezept wird entworfen …', 'gestartet_at' => now()->timestamp,
    ], now()->addMinutes(5));

    $comp->call('pruefeErgebnis')
        ->assertSet('laeuft', true)
        ->assertSet('fortschritt', 'Rezept wird entworfen …')
        ->assertSee('Rezept wird entworfen');
});
