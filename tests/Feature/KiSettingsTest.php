<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Exceptions\KiDeaktiviertException;
use Platform\FoodAlchemist\Livewire\Settings\Ki;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M7-08: KI-Settings + Kill-Switch — DoD: Kill-Switch stoppt Autopilot
 * (Gateway wirft typisiert VOR dem Provider, kein Call, kein Log).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
});

it('DoD: Kill-Switch stoppt jeden KI-Call typisiert — VOR Provider und Log', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['ai_active' => false]);

    expect(fn () => app(AiGatewayService::class)->propose('recipe.description', ['b' => 1]))
        ->toThrow(KiDeaktiviertException::class, 'Kill-Switch');
    expect(\Illuminate\Support\Facades\DB::table('foodalchemist_ai_call_log')->count())->toBe(0);

    // Autopilot-Pfad (M6-05) degradiert sauber in den Panel-Fehler statt 500
    $vk = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'ks', 'name' => 'X', 'status' => 'draft', 'is_sales_recipe' => true,
    ]);
    expect(fn () => app(\Platform\FoodAlchemist\Services\SpeisenKlassenService::class)->classify($this->rootTeam, $vk->id))
        ->toThrow(KiDeaktiviertException::class);

    // wieder an → Call läuft + loggt
    app(TeamSettingsService::class)->update($this->rootTeam, ['ai_active' => true]);
    app(AiGatewayService::class)->propose('recipe.description', ['b' => 1]);
    expect(\Illuminate\Support\Facades\DB::table('foodalchemist_ai_call_log')->count())->toBe(1);
});

it('Settings-Sektion: Umschalten persistiert, Banner + Tier-Pills rendern', function () {
    $c = Livewire::test(Ki::class)
        ->assertSeeHtml('data-ki-kill-switch')
        ->assertSeeHtml('data-ki-tiers')
        ->call('umschalten')
        ->assertSeeHtml('data-ki-aus-banner');

    expect(app(TeamSettingsService::class)->kiAktiv($this->rootTeam->fresh()))->toBeFalse();

    $c->call('umschalten');
    expect(app(TeamSettingsService::class)->kiAktiv($this->rootTeam->fresh()))->toBeTrue();
});
