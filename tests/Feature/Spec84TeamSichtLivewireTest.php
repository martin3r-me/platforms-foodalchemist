<?php

use Illuminate\Support\Facades\Route;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\TeamKontextFehltException;
use Platform\FoodAlchemist\Http\Middleware\FaBereichMiddleware;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\Scopes\TeamSicht;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 B3 Stufe 2 · Livewire-Aktionen sind ein strenger FA-Eingang — nicht über eigenen Code, sondern weil der Provider
 * FaBereichMiddleware an die GANZE `web`-Gruppe hängt und die Livewire-Update-Route über `web` läuft (weder Host noch Core setzen eine
 * eigene Update-Route, geprüft 17.10.). Fehlt das Team, gibt es TeamKontextFehltException statt einer leeren Liste — nur mit Schalter.
 * Diese Tests halten fest, dass das so bleibt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    TeamSicht::zuruecksetzen();
    $this->a = Team::create(['name' => 'Mandant A', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    FoodAlchemistFoodbook::create(['team_id' => $this->a->id, 'label' => 'Sommer']);
    Route::middleware('web')->post('/_spec84/web-aktion', fn () => (string) FoodAlchemistFoodbook::count())->name('spec84-test-web');
    app('router')->getRoutes()->refreshNameLookups();
    $this->ohneTeam = $this->makeUser($this->a, 'Ohne Team');
    $this->ohneTeam->forceFill(['current_team_id' => null])->save();
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
    $this->withoutExceptionHandling();
});

it('die echte Livewire-Update-Route trägt FaBereichMiddleware (über die web-Gruppe)', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => str_ends_with((string) $r->getName(), 'livewire.update'));
    expect($route)->not->toBeNull()
        ->and(app('router')->gatherRouteMiddleware($route))->toContain(FaBereichMiddleware::class);
});

it('Stufe 2: web-Aktion mit Nutzer ohne Team → TeamKontextFehltException (Foodbook)', function () {
    config(['foodalchemist.team_sicht.stufe' => 2]);
    expect(fn () => $this->actingAs($this->ohneTeam)->post('/_spec84/web-aktion'))->toThrow(TeamKontextFehltException::class);
});

it('mit Team: filtert normal', function () {
    config(['foodalchemist.team_sicht.stufe' => 2]);
    expect($this->actingAs($this->makeUser($this->a, 'Mit Team'))->post('/_spec84/web-aktion')->getContent())->toBe('1');
});

it('Stufe 1 betrifft das Foodbook noch nicht, Stufe 0 gar nichts', function () {
    foreach ([0, 1] as $stufe) {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);
        expect($this->actingAs($this->ohneTeam)->post('/_spec84/web-aktion')->getContent())->toBe('1');
    }
});
