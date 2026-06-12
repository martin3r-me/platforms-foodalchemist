<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M8-05: Team-Onboarding — DoD: neues Kind-Team sieht den Eltern-Katalog
 * SOFORT (D1-Kette); Startpaket = team-eigene D2-Snapshots (editierbar,
 * Eltern-Original unberührt); Modul via modulables freigeschaltet.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    // Test-Stub hat keine modulables-Migration — minimal nachziehen (Core-Schema)
    if (! \Illuminate\Support\Facades\Schema::hasTable('modulables')) {
        \Illuminate\Support\Facades\Schema::create('modulables', function ($table) {
            $table->id();
            $table->unsignedBigInteger('module_id');
            $table->string('modulable_type');
            $table->unsignedBigInteger('modulable_id');
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('team_id')->nullable();
            $table->timestamps();
        });
    }
    DB::table('modules')->insertOrIgnore([
        'key' => 'foodalchemist', 'title' => 'Food Alchemist', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('DoD: Onboarding legt Kind an, schaltet Modul frei, Katalog sofort sichtbar, Snapshots team-eigen', function () {
    $gp = $this->makeGp($this->rootTeam, 'Katalog-GP');
    \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $start = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'starter', 'name' => 'Fond: Starter', 'status' => 'approved', 'yield_kg' => 1]);

    $this->artisan('foodalchemist:team-onboarding', [
        '--parent' => $this->rootTeam->id, '--name' => 'Neuer Betrieb', '--user' => 1,
        '--startpaket' => (string) $start->id,
    ])->assertSuccessful();

    $kind = Team::where('name', 'Neuer Betrieb')->first();
    expect($kind)->not->toBeNull()
        ->and((int) $kind->parent_team_id)->toBe($this->rootTeam->id)
        ->and(DB::table('modulables')->where('modulable_id', $kind->id)->where('enabled', 1)->exists())->toBeTrue();

    // Katalog sofort sichtbar (D1-Kette) — GP + Eltern-Rezept
    expect(app(GpService::class)->paginate([], $kind, 50)->pluck('id'))->toContain($gp->id)
        ->and(app(RecipeService::class)->paginateBrowser([], $kind, 50)->pluck('id'))->toContain($start->id);

    // Startpaket: team-eigener Snapshot (editierbar), Original unberührt beim Eltern-Team
    $snapshot = FoodAlchemistRecipe::where('team_id', $kind->id)->first();
    expect($snapshot)->not->toBeNull()
        ->and($snapshot->id)->not->toBe($start->id)
        ->and($snapshot->isOwnedBy($kind))->toBeTrue()
        ->and($start->fresh()->team_id)->toBe($this->rootTeam->id);
});

it('dry-run schreibt nichts', function () {
    $vorher = Team::count();
    $this->artisan('foodalchemist:team-onboarding', [
        '--parent' => $this->rootTeam->id, '--name' => 'Probe', '--dry-run' => true,
    ])->assertSuccessful();

    expect(Team::count())->toBe($vorher);
});
