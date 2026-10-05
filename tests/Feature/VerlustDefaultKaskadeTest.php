<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Settings\Kalkulation as KalkulationSettings;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\SettingsGetTool;
use Platform\FoodAlchemist\Tools\TeamSettingsPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Verlust-Defaults je Warengruppe (GL-02) — wirken sie wirklich?
 *
 * Befund 2026-10-05: Speichern rechnete nur Darreichungen/Pakete/Konzepte/Angebote neu, die
 * Rezept-Ausbeute blieb stehen (1,0 statt 0,5 kg); Kind-Teams rechneten mit 0 %. Dieser Test
 * hält beides fest: Speichern rechnet die Rezepte des Teams UND der erbenden Kind-Teams neu,
 * und die Maps sind org-vererbt (eigene Map gewinnt komplett).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(TeamSettingsService::class);

    // 1 kg einer Zutat aus WG $wg — Ausbeute in kg ist direkt der Verlustfaktor.
    $this->rezept = function ($team, string $wg, string $key) {
        $gp = $this->makeGp($team, 'GP-' . $key);
        $gp->update(['commodity_group_code' => $wg]);
        $r = FoodAlchemistRecipe::create([
            'team_id' => $team->id, 'recipe_key' => 'verlust-' . $key, 'name' => 'Verlust ' . $key, 'status' => 'draft',
        ]);
        $this->makeIngredient($r, 'Zutat', $gp, '1000', 1);
        app(RecipeRecomputeService::class)->recomputePipeline($r->id);

        return $r;
    };
    $this->ausbeute = fn ($r) => (float) $r->fresh()->yield_kg;
});

it('Kaskade: WG-Wert vor global, Zutat vor GP vor Team, ohne alles 0', function () {
    $this->svc->update($this->rootTeam, ['trimming_loss_defaults' => ['01' => 20, '*' => 5], 'cooking_loss_defaults' => ['*' => 10]]);

    $wg01 = ($this->rezept)($this->rootTeam, '01', 'a');
    $wg04 = ($this->rezept)($this->rootTeam, '04', 'b');
    expect(($this->ausbeute)($wg01))->toBe(0.72)       // 1 × 0,80 × 0,90
        ->and(($this->ausbeute)($wg04))->toBe(0.855);  // 1 × 0,95 × 0,90

    $wg01->ingredients()->first()->gp->update(['trimming_loss_default_pct' => 50]);   // GP schlägt Team
    app(RecipeRecomputeService::class)->recomputePipeline($wg01->id);
    expect(($this->ausbeute)($wg01))->toBe(0.45);

    $wg01->ingredients()->first()->update(['trimming_loss_pct' => 0]);                 // Zutat schlägt GP
    app(RecipeRecomputeService::class)->recomputePipeline($wg01->id);
    expect(($this->ausbeute)($wg01))->toBe(0.9);

    $ohne = ($this->rezept)($this->childB, '01', 'c');                                 // childB ohne eigene Map, erbt
    $this->svc->update($this->rootTeam, ['trimming_loss_defaults' => null, 'cooking_loss_defaults' => null]);
    app(RecipeRecomputeService::class)->recomputePipeline($ohne->id);
    expect(($this->ausbeute)($ohne))->toBe(1.0);
});

it('Vererbung: Kind ohne eigene Map erbt, eigene Map gewinnt komplett', function () {
    $this->svc->update($this->rootTeam, ['trimming_loss_defaults' => ['01' => 20, '*' => 5]]);

    expect($this->svc->putzverlustDefault($this->childA, '01'))->toBe(20.0)
        ->and($this->svc->putzverlustDefault($this->childA, '04'))->toBe(5.0);

    $this->svc->update($this->childA, ['trimming_loss_defaults' => ['04' => 30]]);
    expect($this->svc->putzverlustDefault($this->childA, '04'))->toBe(30.0)
        ->and($this->svc->putzverlustDefault($this->childA, '01'))->toBeNull()   // keine schlüsselweise Mischung
        ->and($this->svc->putzverlustDefault($this->childB, '01'))->toBe(20.0);  // Geschwister erbt weiter
});

it('UI-Speichern rechnet die Rezepte neu — auch die der erbenden Kind-Teams', function () {
    $eigen = ($this->rezept)($this->rootTeam, '01', 'd');
    $kind = ($this->rezept)($this->childA, '01', 'e');
    expect(($this->ausbeute)($eigen))->toBe(1.0)->and(($this->ausbeute)($kind))->toBe(1.0);

    Livewire::test(KalkulationSettings::class)
        ->set('putzverlust.01', '50')
        ->call('speichern')
        ->assertSet('meldung', fn ($m) => str_contains($m, 'neu gerechnet'));

    expect(($this->ausbeute)($eigen))->toBe(0.5)     // der Fall, der vorher stehen blieb
        ->and(($this->ausbeute)($kind))->toBe(0.5);
});

it('reine MwSt-Änderung rechnet keine Rezepte neu', function () {
    $this->svc->update($this->rootTeam, ['trimming_loss_defaults' => ['01' => 20]]);
    $r = ($this->rezept)($this->rootTeam, '01', 'f');
    $vorher = $r->fresh()->updated_at;
    $this->travel(5)->minutes();

    Livewire::test(KalkulationSettings::class)
        ->set('mwst.regulaer', '19')
        ->call('speichern')
        ->assertSet('meldung', 'Gespeichert — Preise neu gerechnet.');

    expect($r->fresh()->updated_at->equalTo($vorher))->toBeTrue();
});

it('Kind-Team sieht die geerbten Werte als Hinweis', function () {
    // Global-Zeile statt WG-Zeile: das Fixture legt keine Warengruppen-Lookups an.
    $this->svc->update($this->rootTeam, ['trimming_loss_defaults' => ['*' => 7]]);
    $this->actingAs($this->makeUser($this->childA, 'Kind'));

    Livewire::test(KalkulationSettings::class)
        ->assertSee('Geerbt von ' . $this->rootTeam->name)
        ->assertSeeHtml('wire:model="putzverlust.*" placeholder="7"');
});

it('MCP team_settings.PUT rechnet bei Verlust-Maps die Rezepte neu, settings.GET zeigt die Quelle', function () {
    $r = ($this->rezept)($this->childA, '01', 'g');
    $put = app(TeamSettingsPutTool::class)->execute(
        ['settings' => ['trimming_loss_defaults' => ['01' => 25]]],
        new ToolContext($this->makeUser($this->rootTeam, 'Mcp'), $this->rootTeam),
    );

    expect($put->success)->toBeTrue()
        ->and($put->data['rezepte_neu_berechnet'])->toBeGreaterThanOrEqual(1)
        ->and(($this->ausbeute)($r))->toBe(0.75);

    $get = app(SettingsGetTool::class)->execute([], new ToolContext($this->makeUser($this->childA, 'McpKind'), $this->childA));
    expect($get->data['trimming_loss_defaults'])->toBe(['01' => 25])
        ->and($get->data['trimming_loss_defaults_von_team_id'])->toBe($this->rootTeam->id);
});
