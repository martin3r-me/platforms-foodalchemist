<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Services\TeamContext;
use Platform\FoodAlchemist\Exceptions\KiDeaktiviertException;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Support\Curate;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 A-2 · KI-Dienste lesen das betrachtende Team (FaTeam) statt des UI-Teams. Vorher prüfte AiGatewayService Kill-Switch,
 * Budget und Call-Log nach `core.team.switch` am UI-Team — und die Abrechnung je Mandant (#452) lief auf das falsche Team.
 * §3.5 Test 1 (MCP nach team.switch), Test 9 (Core-Override im selben Request), Job ohne Login mit Team-Kontext.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id, 'foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();
    $this->a = Team::create(['name' => 'Mandant A', 'user_id' => 1, 'personal_team' => false]);
    $this->b = Team::create(['name' => 'Mandant B', 'user_id' => 1, 'personal_team' => false]);
    FoodAlchemistMandant::create(['team_id' => $this->a->id, 'status' => 'aktiv']);
    FoodAlchemistMandant::create(['team_id' => $this->b->id, 'status' => 'aktiv']);
    $this->user = $this->makeUser($this->a, 'Kurator A', 'owner');                 // UI-Team = A
    $this->b->users()->attach($this->user->id, ['role' => 'owner']);
    $this->letzte = fn () => DB::table('foodalchemist_ai_call_log')->orderByDesc('id')->first();
    TeamContext::clear();
});

afterEach(fn () => TeamContext::clear());

it('Test 1/9: nach team.switch (Core-Override) rechnen Call-Log und Abrechnung auf das MCP-Team, nicht aufs UI-Team', function () {
    $this->actingAs($this->user);
    TeamContext::setTeam($this->b);

    app(AiGatewayService::class)->propose('recipe.description', ['b' => 1]);

    $log = ($this->letzte)();
    expect((int) $log->team_id)->toBe($this->b->id)
        ->and((int) $log->abrechnung_team_id)->toBe($this->b->id);
});

it('Kill-Switch greift am betrachtenden Team: B aus stoppt den Call, A aus stoppt ihn nicht', function () {
    $this->actingAs($this->user);
    TeamContext::setTeam($this->b);

    app(TeamSettingsService::class)->update($this->a, ['ai_active' => false]);
    app(AiGatewayService::class)->propose('recipe.description', ['b' => 1]);                 // A aus, aber B betrachtet → läuft
    expect((int) ($this->letzte)()->team_id)->toBe($this->b->id);

    app(TeamSettingsService::class)->update($this->b, ['ai_active' => false]);
    expect(fn () => app(AiGatewayService::class)->propose('recipe.description', ['b' => 1]))
        ->toThrow(KiDeaktiviertException::class);
});

it('Job ohne Login, aber mit Team-Kontext: Kill-Switch greift und das Log trägt das Job-Team', function () {
    app(TeamSettingsService::class)->update($this->b, ['ai_active' => false]);

    expect(fn () => FaTeam::imTeam($this->b, fn () => app(AiGatewayService::class)->propose('recipe.description', ['b' => 1])))
        ->toThrow(KiDeaktiviertException::class);                                              // vorher: kein Team → Riegel übersprungen

    app(TeamSettingsService::class)->update($this->b, ['ai_active' => true]);
    FaTeam::imTeam($this->b, fn () => app(AiGatewayService::class)->propose('recipe.description', ['b' => 1]));
    expect((int) ($this->letzte)()->team_id)->toBe($this->b->id)
        ->and((int) ($this->letzte)()->abrechnung_team_id)->toBe($this->b->id);
});

it('Curate: Pflegerecht am betrachtenden Team — mit Override auf B darf der Nutzer B-Zeilen pflegen, A-Zeilen nicht', function () {
    $this->actingAs($this->user);
    $rezeptB = $this->makeRecipe($this->b, 'B-Rezept');
    $rezeptA = $this->makeRecipe($this->a, 'A-Rezept');

    expect(Curate::canCurate($this->user, $rezeptA))->toBeTrue()->and(Curate::canCurate($this->user, $rezeptB))->toBeFalse();

    TeamContext::setTeam($this->b);
    expect(Curate::canCurate($this->user, $rezeptB))->toBeTrue()->and(Curate::canCurate($this->user, $rezeptA))->toBeFalse();
});
