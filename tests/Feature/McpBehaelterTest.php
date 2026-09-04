<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeRegeneration;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\BehaelterBedarfGetTool;
use Platform\FoodAlchemist\Tools\BehaelterKatalogGetTool;
use Platform\FoodAlchemist\Tools\RecipeContainerDeleteTool;
use Platform\FoodAlchemist\Tools\RecipeContainerPutTool;
use Platform\FoodAlchemist\Tools\RecipeRegenerationGetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 51 · MCP-Lockstep: Behälter und Kaskade sind über Tools steuerbar UND prüfbar.
 *
 * Der Punkt der GET-Tools ist nicht Bequemlichkeit: ohne sie sähe ein MCP-Aufruf nur die
 * gespeicherten Zeilen und hielte die geerbten für nicht vorhanden — und der Behälter-Bedarf
 * liesse sich nur prüfen, indem man einen Produktionsauftrag anlegt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->ctx = new ToolContext(user: Auth::user(), team: $this->rootTeam);

    $this->artisan('foodalchemist:behaelter-katalog --apply');
    $this->gn65 = (int) DB::table('foodalchemist_vocab_containers')->where('slug', 'gn_11_65mm')->value('id');
    $this->eimer = (int) DB::table('foodalchemist_vocab_containers')->where('slug', 'eimer_10_l')->value('id');

    $this->sauce = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'm1', 'name' => 'Sauce: Pfeffer',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 10,
    ]);
});

it('setzt und liest den Behälter je Zweck über MCP', function () {
    $put = app(RecipeContainerPutTool::class)->execute([
        'recipe_id' => $this->sauce->id, 'zweck' => 'abfuellen',
        'felder' => ['container_vocab_id' => $this->eimer, 'referenz_menge_kg' => 9, 'skalierung' => 'tiefer_fuellbar'],
    ], $this->ctx);

    expect($put->success)->toBeTrue();

    $bedarf = app(BehaelterBedarfGetTool::class)->execute(
        ['recipe_id' => $this->sauce->id, 'menge_kg' => 40], $this->ctx
    );

    $ab = $bedarf->data['zwecke']['abfuellen'];

    expect($bedarf->success)->toBeTrue()
        ->and($ab['berechenbar'])->toBeTrue()
        ->and($ab['varianten'][0]['anzahl'])->toBe(5)          // 40 kg zu 9 kg je Eimer
        ->and($ab['varianten'][0]['konfidenz'])->toBe('hoch')
        ->and($ab['kurz'])->toContain('5× Eimer 10 l');
});

it('ohne Referenzmenge greift die Dichteklasse — und sagt, dass sie geschätzt ist', function () {
    $this->sauce->forceFill(['dichteklasse' => 'fluessig'])->save();

    app(RecipeContainerPutTool::class)->execute([
        'recipe_id' => $this->sauce->id, 'zweck' => 'abfuellen',
        'felder' => ['container_vocab_id' => $this->eimer],     // KEINE Referenzmenge
    ], $this->ctx);

    $ab = app(BehaelterBedarfGetTool::class)
        ->execute(['recipe_id' => $this->sauce->id, 'menge_kg' => 40], $this->ctx)
        ->data['zwecke']['abfuellen'];

    // 10 l × 0,90 Nutzfaktor × 1,0 kg/l = 9 kg — dieselbe Zahl, aber ehrlich als Schätzung markiert.
    expect($ab['varianten'][0]['kg_je_behaelter'])->toBe(9.0)
        ->and($ab['varianten'][0]['konfidenz'])->toBe('mittel')
        ->and($ab['kurz'])->toContain('geschätzt');
});

it('ohne Referenz UND ohne Dichteklasse wird nicht geraten', function () {
    app(RecipeContainerPutTool::class)->execute([
        'recipe_id' => $this->sauce->id, 'zweck' => 'abfuellen',
        'felder' => ['container_vocab_id' => $this->eimer],
    ], $this->ctx);

    $ab = app(BehaelterBedarfGetTool::class)
        ->execute(['recipe_id' => $this->sauce->id, 'menge_kg' => 40], $this->ctx)
        ->data['zwecke']['abfuellen'];

    // Eimer haben kein kapazitaet_kg im Katalog — also gibt es keinen Fallback.
    expect($ab['berechenbar'])->toBeFalse()
        ->and($ab['grund'])->toContain('Dichteklasse');
});

it('ein Eimer wird fürs Regenerieren abgelehnt — mit Grund, nicht mit einer Zahl', function () {
    app(RecipeContainerPutTool::class)->execute([
        'recipe_id' => $this->sauce->id, 'zweck' => 'regenerieren',
        'felder' => ['container_vocab_id' => $this->eimer, 'referenz_menge_kg' => 9],
    ], $this->ctx);

    $re = app(BehaelterBedarfGetTool::class)
        ->execute(['recipe_id' => $this->sauce->id, 'menge_kg' => 40], $this->ctx)
        ->data['zwecke']['regenerieren'];

    // Der Seed gibt Eimer nur fuer abfuellen + transport frei — Kunststoff gehoert nicht in den Ofen.
    expect($re['berechenbar'])->toBeFalse()
        ->and($re['grund'])->toContain('regenerieren');
});

it('der Katalog zeigt, was bemessbar ist — und was nicht', function () {
    DB::table('foodalchemist_vocab_containers')->insert([
        'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id,
        'slug' => 'blind', 'name' => 'Irgendein Behälter', 'sort_order' => 900,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $out = app(BehaelterKatalogGetTool::class)->execute(['familie' => 'GN', 'zweck' => 'regenerieren'], $this->ctx);
    $gn = collect($out->data['behaelter'])->firstWhere('name', 'GN 1/1 65mm');

    expect($gn['volumen_l'])->toBe(8.8)
        ->and($gn['nutzvolumen_l'])->toBe(7.48)                  // 8,8 × 0,85
        ->and($gn['bemessbar'])->toBeTrue()
        ->and(collect($out->data['behaelter'])->pluck('name'))->not->toContain('Irgendein Behälter');

    $alle = app(BehaelterKatalogGetTool::class)->execute([], $this->ctx);
    expect($alle->data['ohne_bemessungsgrundlage'])->toBe(1)
        ->and($alle->data['hinweis'])->toContain('weder Maße noch Volumen');
});

it('die Kaskade ist über MCP lesbar — samt Herkunft und Lücken', function () {
    FoodAlchemistRecipeRegeneration::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->sauce->id,
        'component_label' => 'Sauce: Pfeffer', 'temp_c' => 75, 'sort_order' => 0,
    ]);

    $teller = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'm2', 'name' => 'Teller: Steak',
        'status' => 'approved', 'is_sales_recipe' => true, 'yield_kg' => 0.3,
    ]);
    FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $teller->id,
        'referenced_recipe_id' => $this->sauce->id, 'raw_text' => 'Sauce', 'quantity' => '80',
        'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1,
    ]);

    $out = app(RecipeRegenerationGetTool::class)->execute(['recipe_id' => $teller->id], $this->ctx);
    $zeile = $out->data['komponenten'][0];

    // Ohne dieses Tool saehe ein MCP-Aufruf nur gespeicherte Zeilen — und die geerbte gaebe es
    // fuer ihn nicht.
    expect($out->success)->toBeTrue()
        ->and($zeile['herkunft'])->toBe('geerbt')
        ->and($zeile['temp_c'])->toBe(75)
        ->and($zeile['von_recipe_name'])->toBe('Sauce: Pfeffer')
        ->and($out->data['luecken'])->toBe(0);
});

it('DELETE nimmt den Behälter zurück, der Bedarf verschwindet mit ihm', function () {
    app(RecipeContainerPutTool::class)->execute([
        'recipe_id' => $this->sauce->id, 'zweck' => 'abfuellen',
        'felder' => ['container_vocab_id' => $this->eimer, 'referenz_menge_kg' => 9],
    ], $this->ctx);

    app(RecipeContainerDeleteTool::class)->execute(
        ['recipe_id' => $this->sauce->id, 'zweck' => 'abfuellen'], $this->ctx
    );

    $out = app(BehaelterBedarfGetTool::class)->execute(['recipe_id' => $this->sauce->id], $this->ctx);

    expect($out->data['zwecke'])->toBe([])
        ->and($out->data['hinweis'])->toContain('kein Behälter hinterlegt');
});

it('weist einen unbekannten Zweck ab, statt still nichts zu tun', function () {
    $out = app(RecipeContainerPutTool::class)->execute([
        'recipe_id' => $this->sauce->id, 'zweck' => 'warmhalten',
        'felder' => ['container_vocab_id' => $this->eimer],
    ], $this->ctx);

    expect($out->success)->toBeFalse()->and($out->errorCode)->toBe('VALIDATION_ERROR');
});

it('ein fremdes Rezept bleibt unerreichbar', function () {
    $fremd = FoodAlchemistRecipe::create([
        'team_id' => $this->childB->id, 'recipe_key' => 'm9', 'name' => 'Fremd',
        'status' => 'approved', 'is_sales_recipe' => false,
    ]);

    $out = app(RecipeContainerPutTool::class)->execute([
        'recipe_id' => $fremd->id, 'zweck' => 'abfuellen',
        'felder' => ['container_vocab_id' => $this->eimer],
    ], $this->ctx);

    expect($out->success)->toBeFalse();
});

it('ein Träger gilt nicht als »ohne Bemessungsgrundlage« — er wird über Plätze bemessen', function () {
    $box = collect(app(BehaelterKatalogGetTool::class)->execute(['nur_traeger' => true], $this->ctx)->data['behaelter'])
        ->firstWhere('name', 'Thermobox 600x400 (200 mm)');

    // Er hat bewusst kein Fuellvolumen — er wird nie befuellt. Die Steckplaetze fehlen aber
    // wirklich (sie haengen an Innenhoehe UND Behaeltertiefe), und genau das sagt der Grund.
    expect($box['ist_traeger'])->toBeTrue()
        ->and($box['volumen_l'])->toBeNull()
        ->and($box['bemessbar'])->toBeFalse()
        ->and($box['grund'])->toContain('Steckplätze');

    $alle = app(BehaelterKatalogGetTool::class)->execute([], $this->ctx)->data;
    expect($alle['ohne_bemessungsgrundlage'])->toBe(0);      // ohne die Blind-Zeile aus dem anderen Fall
});
