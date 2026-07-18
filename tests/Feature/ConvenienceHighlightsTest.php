<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Convenience\Index as ConvenienceIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\ConvenienceHighlightService;
use Platform\FoodAlchemist\Tools\ConvenienceHighlightsGetTool;
use Platform\FoodAlchemist\Tools\ConvenienceHighlightsPutTool;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 06·H2 — Convenience-Highlights: Auto-Score + Pin/Exclude (Service, Command,
 * MCP-Tools, Kuratierungs-Screen). Soft-Regel: nur Convenience-getaggte GPs pinbar.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->conv = $this->makeGp($this->rootTeam, 'TK-Kartoffelgratin');
    $this->conv->update(['status' => 'approved', 'tag_is_convenience' => true]);

    $this->nichtConv = $this->makeGp($this->rootTeam, 'Frische Kartoffel');
    $this->nichtConv->update(['status' => 'approved', 'tag_is_convenience' => false]);

    $this->svc = app(ConvenienceHighlightService::class);
});

it('scored nur Convenience-GPs', function () {
    $namen = $this->svc->suggest($this->rootTeam)->pluck('name')->all();
    expect($namen)->toContain('TK-Kartoffelgratin')->not->toContain('Frische Kartoffel');
});

it('pinnt nur Convenience-getaggte GPs', function () {
    $this->svc->pin($this->conv, 1);
    expect($this->conv->refresh()->is_convenience_highlight)->toBeTrue();
    expect($this->conv->highlight_rank)->toBe(1);

    expect(fn () => $this->svc->pin($this->nichtConv))->toThrow(RuntimeException::class);
});

it('exclude entfernt das Highlight', function () {
    $this->svc->pin($this->conv, 2);
    $this->svc->exclude($this->conv);
    expect($this->conv->refresh()->is_convenience_highlight)->toBeFalse();
    expect($this->conv->highlight_rank)->toBeNull();
});

it('current listet gepinnte nach Rang', function () {
    $b = $this->makeGp($this->rootTeam, 'TK-Rösti');
    $b->update(['status' => 'approved', 'tag_is_convenience' => true]);
    $this->svc->pin($this->conv, 2);
    $this->svc->pin($b, 1);
    expect($this->svc->current($this->rootTeam)->pluck('name')->all())->toBe(['TK-Rösti', 'TK-Kartoffelgratin']);
});

it('Command --suggest + --pin', function () {
    $this->artisan('foodalchemist:convenience-highlights', ['--team' => $this->rootTeam->id, '--suggest' => true])->assertSuccessful();
    $this->artisan('foodalchemist:convenience-highlights', ['--pin' => $this->conv->id, '--rank' => 3])->assertSuccessful();
    expect($this->conv->refresh()->is_convenience_highlight)->toBeTrue();
});

it('MCP GET/PUT respektieren Ownership + Soft-Regel', function () {
    $ctx = new ToolContext($this->makeUser($this->rootTeam), $this->rootTeam);

    $put = (new ConvenienceHighlightsPutTool())->execute(['gp_id' => $this->conv->id, 'action' => 'pin', 'rank' => 1], $ctx);
    expect($put->success)->toBeTrue();
    expect($this->conv->refresh()->is_convenience_highlight)->toBeTrue();

    $nein = (new ConvenienceHighlightsPutTool())->execute(['gp_id' => $this->nichtConv->id, 'action' => 'pin'], $ctx);
    expect($nein->success)->toBeFalse(); // Soft-Regel greift

    $get = (new ConvenienceHighlightsGetTool())->execute(['mode' => 'current'], $ctx);
    expect($get->success)->toBeTrue();
    expect(collect($get->data['items'])->pluck('gp_id'))->toContain($this->conv->id);
});

it('Kuratierungs-Screen rendert + pinnt', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);

    Livewire::test(ConvenienceIndex::class)
        ->assertOk()
        ->assertSee('TK-Kartoffelgratin')
        ->call('pin', $this->conv->id)
        ->assertOk();

    expect($this->conv->refresh()->is_convenience_highlight)->toBeTrue();
});
