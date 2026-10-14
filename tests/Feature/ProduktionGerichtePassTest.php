<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine as Line;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamSetting;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Services\Reife\RecipeReifeAdapter;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 83 B2 (Erweiterung): Gerichte gehen an den Pass. Routing: Zuteilung von Hand > recipe.default_station_id > bei Gerichten die
 * Team-Vorgabe "Posten für Gerichte" > keiner. Alles nur mit Posten des eigenen Teams (sichtbar + aktiv).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ProductionOrderService::class);
    $this->settings = app(TeamSettingsService::class);
    $this->user = $this->makeUser($this->rootTeam, 'Küchenchef');
    $this->actingAs($this->user);

    $this->pass = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'slug' => 'pass', 'name' => 'Pass']);
    $this->saucier = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'slug' => 'saucier', 'name' => 'Saucier']);
    $fremd = Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false]);
    $this->fremderPosten = FoodAlchemistProductionStation::create(['team_id' => $fremd->id, 'slug' => 'fremd', 'name' => 'Fremd']);

    $this->vorgabe = fn (?int $id) => FoodAlchemistTeamSetting::updateOrCreate(['team_id' => $this->rootTeam->id], ['gerichte_station_id' => $id]);
    $this->rezept = fn (string $key, bool $gericht, ?int $posten = null) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => strtoupper($key), 'status' => 'approved',
        'is_sales_recipe' => $gericht, 'yield_kg' => 2.0, 'work_time_min' => 20, 'default_station_id' => $posten, 'sales_net' => $gericht ? 3.0 : null, 'sales_unit_count' => $gericht ? 1 : null,
    ]);
    $this->auftrag = fn (array $rezepte) => $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Pass-Test', array_map(
        fn ($r) => ['source_ref' => 'r:'.$r->recipe_key, 'recipe_id' => $r->id, 'amount_kg' => 4.0], $rezepte));
    $this->zeile = fn ($order, $r) => Line::where('production_order_id', $order->id)->where('recipe_id', $r->id)->first();
});

it('★ ein Gericht ohne Rezept-Posten geht an den Pass (Team-Vorgabe); ein Basisrezept bekommt ihn nicht', function () {
    ($this->vorgabe)($this->pass->id);
    $gericht = ($this->rezept)('gericht', true);
    $basis = ($this->rezept)('fond', false);

    $order = ($this->auftrag)([$gericht, $basis]);

    expect((int) ($this->zeile)($order, $gericht)->station_id)->toBe($this->pass->id)
        ->and(($this->zeile)($order, $basis)->station_id)->toBeNull();
});

it('★ Reihenfolge: Hand > Rezept-Posten > Team-Vorgabe > keiner', function () {
    ($this->vorgabe)($this->pass->id);
    $mitPosten = ($this->rezept)('mit', true, $this->saucier->id);
    $ohne = ($this->rezept)('ohne', true);

    $order = ($this->auftrag)([$mitPosten, $ohne]);
    expect((int) ($this->zeile)($order, $mitPosten)->station_id)->toBe($this->saucier->id)     // Rezept-Posten schlägt die Vorgabe
        ->and((int) ($this->zeile)($order, $ohne)->station_id)->toBe($this->pass->id);

    $this->svc->assignLine($this->rootTeam, ($this->zeile)($order, $ohne)->id, ['station_id' => $this->saucier->id]);   // von Hand
    $this->svc->recomputeOrder($this->rootTeam, $order->refresh());
    expect((int) ($this->zeile)($order, $ohne)->station_id)->toBe($this->saucier->id);          // Hand überlebt den Neuaufbau

    ($this->vorgabe)(null);
    $neu = ($this->auftrag)([($this->rezept)('weiteres', true)]);
    expect(Line::where('production_order_id', $neu->id)->value('station_id'))->toBeNull();      // keine Vorgabe: keiner
});

it('★ nur Posten des eigenen Teams: ein fremder Rezept-Posten und eine fremde Vorgabe werden nicht übernommen', function () {
    ($this->vorgabe)($this->fremderPosten->id);                       // Vorgabe zeigt auf einen fremden Posten → gilt als nicht gesetzt
    $fremdPosten = ($this->rezept)('abo', true, $this->fremderPosten->id);

    $order = ($this->auftrag)([$fremdPosten]);

    expect(($this->zeile)($order, $fremdPosten)->station_id)->toBeNull()
        ->and($this->settings->gerichtePostenId($this->rootTeam))->toBeNull()
        ->and(collect($order->refresh()->warnungen)->implode(' '))->toContain('nicht zu diesem Team');
});

it('ein fremder Rezept-Posten fällt bei Gerichten auf die (eigene) Team-Vorgabe zurück — ohne Warnung', function () {
    ($this->vorgabe)($this->pass->id);
    $abo = ($this->rezept)('abo', true, $this->fremderPosten->id);

    $order = ($this->auftrag)([$abo]);

    expect((int) ($this->zeile)($order, $abo)->station_id)->toBe($this->pass->id)
        ->and(collect($order->refresh()->warnungen)->implode(' '))->not->toContain('nicht zu diesem Team');
});

it('Unter-Team erbt die Vorgabe vom Oberteam; ein inaktiver Posten zählt nicht', function () {
    ($this->vorgabe)($this->pass->id);

    expect($this->settings->gerichtePostenId($this->childA))->toBe($this->pass->id)
        ->and($this->settings->quelleTeamId($this->childA, 'gerichte_station_id'))->toBe($this->rootTeam->id);

    $this->pass->update(['is_inactive' => true]);
    expect($this->settings->gerichtePostenId($this->rootTeam))->toBeNull();
});

it('★ Reife-Ampel: die Lücke „Gericht ohne Posten" entfällt, wenn eine Team-Vorgabe gesetzt ist', function () {
    $gericht = ($this->rezept)('gericht', true);
    $luecken = fn () => collect(app(RecipeReifeAdapter::class)->messe($this->rootTeam, $gericht->id)['luecken'])->where('code', 'default_station_id')->count();

    expect($luecken())->toBe(1);
    ($this->vorgabe)($this->pass->id);
    expect($luecken())->toBe(0);
});

it('MCP: team_settings.PUT setzt die Vorgabe (nur sichtbare Posten), settings.GET liest sie', function () {
    $reg = app(ToolRegistry::class);
    $kontext = new ToolContext($this->user, $this->rootTeam);

    $ok = $reg->get('foodalchemist.team_settings.PUT')->execute(['settings' => ['gerichte_station_id' => $this->pass->id]], $kontext);
    expect($ok->success)->toBeTrue('PUT: '.($ok->error ?? ''));
    expect($reg->get('foodalchemist.settings.GET')->execute([], $kontext)->data['gerichte_station_id'])->toBe($this->pass->id);

    $fremd = $reg->get('foodalchemist.team_settings.PUT')->execute(['settings' => ['gerichte_station_id' => $this->fremderPosten->id]], $kontext);
    expect($fremd->success)->toBeFalse()->and($fremd->errorCode)->toBe('NOT_FOUND');

    $leer = $reg->get('foodalchemist.team_settings.PUT')->execute(['settings' => ['gerichte_station_id' => null]], $kontext);
    expect($leer->success)->toBeTrue()->and(DB::table('foodalchemist_team_settings')->where('team_id', $this->rootTeam->id)->value('gerichte_station_id'))->toBeNull();
});
