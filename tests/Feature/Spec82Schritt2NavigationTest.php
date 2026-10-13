<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Settings\FoodDna;
use Platform\FoodAlchemist\Livewire\Settings\Index as SettingsIndex;
use Platform\FoodAlchemist\Livewire\Settings\KundeDna;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\CanvasEntryAddTool;
use Platform\FoodAlchemist\Tools\CanvasGetTool;
use Platform\FoodAlchemist\Tools\CanvasPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 · Bau-Schritt 2: Navigation und Begriffe (§1, §5).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(CanvasService::class);
});

// ── Schritt 2 · Navigation und Begriffe ───────────────────────────────────────

it('Controlling hat den Reiter „Standorte & Freigaben“ nicht mehr; ein alter Link fällt auf „Lage“', function () {
    expect(\Platform\FoodAlchemist\Livewire\Controlling\Cockpit::TABS)->not->toHaveKey('standorte');

    Livewire::withQueryParams(['tab' => 'standorte', 'editor' => '0'])
        ->test(\Platform\FoodAlchemist\Livewire\Controlling\Cockpit::class)
        ->assertSet('tab', 'lage');
});

it('Einstellungen des Oberteams haben „Unter-Teams & Freigaben“ und zeigen den Editor', function () {
    expect(SettingsIndex::SEKTIONEN)->toHaveKey('unter-teams')
        ->and(SettingsIndex::SEKTIONEN['unter-teams']['label'])->toBe('Unter-Teams & Freigaben');

    Livewire::test(SettingsIndex::class, ['sektion' => 'unter-teams'])->assertOk()->assertSee('Unter-Teams & Freigaben');
    Livewire::test(\Platform\FoodAlchemist\Livewire\Settings\UnterTeams::class)->assertSeeHtml('data-settings-standort-inhalte');
});

it('Ein Unter-Team sieht die Sektion „Unter-Teams & Freigaben“ nicht (hat keine Unter-Teams)', function () {
    $this->actingAs($this->makeUser($this->childA, 'Kind-User'));

    $nav = Livewire::test(SettingsIndex::class)->viewData('sektionen');
    expect($nav)->not->toHaveKey('unter-teams');
    // Direktlink: Livewire macht aus abort(404) im mount() keine Exception, sondern den Status 404
    Livewire::test(SettingsIndex::class, ['sektion' => 'unter-teams'])->assertNotFound();
});

it('„Outlet“/„Betrieb“ heißt in der Beschriftung „Kalkulationsprofil“: Einstellungen, Leiste, Ausgabe', function () {
    expect(SettingsIndex::SEKTIONEN['betriebe']['label'])->toBe('Kalkulationsprofile');

    Livewire::test(\Platform\FoodAlchemist\Livewire\Settings\Betriebe::class)
        ->assertSee('Neues Kalkulationsprofil')
        ->assertDontSee('Neuer Betrieb')
        ->assertDontSee('Betriebe &');
});

it('Im Oberteam zeigt die Unter-Team-Karte das Kalkulationsprofil des Unter-Teams', function () {
    $outlet = \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Kantine Nord']);
    app(\Platform\FoodAlchemist\Services\StandortService::class)->betriebZuordnen($this->rootTeam, $this->childA->id, $outlet->id, $this->user);

    Livewire::test(\Platform\FoodAlchemist\Livewire\Controlling\Panels\StandortInhalte::class)
        ->assertSee('Kalkulationsprofil: Kantine Nord');
});
