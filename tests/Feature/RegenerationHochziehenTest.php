<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeRegeneration;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 51 Etappe G — Bestand auf die Kaskade heben, ohne Konflikte wegzurunden.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->nr = 0;

    $this->rezept = fn (string $name, bool $vk = false) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g'.(++$this->nr),
        'name' => $name, 'status' => 'approved', 'is_sales_recipe' => $vk,
    ]);

    $this->hinein = fn ($ziel, $sub) => FoodAlchemistRecipeIngredient::create([
        'team_id' => $ziel->team_id, 'recipe_id' => $ziel->id, 'referenced_recipe_id' => $sub->id,
        'raw_text' => $sub->name, 'quantity' => '100',
        'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1,
    ]);

    $this->alteZeile = fn ($gericht, string $label, array $werte = []) => FoodAlchemistRecipeRegeneration::create(
        array_merge(['team_id' => $this->rootTeam->id, 'recipe_id' => $gericht->id,
            'component_label' => $label, 'ingredient_id' => null, 'sort_order' => 0], $werte)
    );
});

it('zieht eine übereinstimmende Komponente hoch und räumt die Gericht-Zeilen weg', function () {
    $ratatouille = ($this->rezept)('Ratatouille');
    foreach (['Teller A', 'Teller B', 'Teller C'] as $name) {
        $g = ($this->rezept)($name, true);
        ($this->hinein)($g, $ratatouille);
        ($this->alteZeile)($g, 'Ratatouille', ['temp_c' => 150, 'duration_min' => 10]);
    }

    $this->artisan('foodalchemist:regeneration-hochziehen --apply')->assertExitCode(0);

    $default = DB::table('foodalchemist_recipe_regenerations')
        ->where('recipe_id', $ratatouille->id)->whereNull('deleted_at')->first();

    expect($default)->not->toBeNull()
        ->and((int) $default->temp_c)->toBe(150)
        ->and($default->ingredient_id)->toBeNull()
        ->and($default->source)->toBe('migration');

    // Drei handgetippte Zeilen wurden zu einem gepflegten Default.
    expect(DB::table('foodalchemist_recipe_regenerations AS rr')
        ->join('foodalchemist_recipes AS r', 'r.id', '=', 'rr.recipe_id')
        ->whereNull('rr.deleted_at')->where('r.is_sales_recipe', true)->count())->toBe(0);
});

it('widersprüchliche Angaben werden NICHT vereinheitlicht, sondern zu Overrides', function () {
    $ratatouille = ($this->rezept)('Ratatouille');
    foreach ([150, 150, 180] as $i => $temp) {
        $g = ($this->rezept)('Teller '.$i, true);
        ($this->hinein)($g, $ratatouille);
        ($this->alteZeile)($g, 'Ratatouille', ['temp_c' => $temp]);
    }

    $this->artisan('foodalchemist:regeneration-hochziehen --apply')->assertExitCode(0);

    // »Erster Schreiber gewinnt« waere eine stille, sortierungsabhaengige Entscheidung.
    expect(DB::table('foodalchemist_recipe_regenerations')
        ->where('recipe_id', $ratatouille->id)->whereNull('deleted_at')->count())->toBe(0);

    $amGericht = DB::table('foodalchemist_recipe_regenerations AS rr')
        ->join('foodalchemist_recipes AS r', 'r.id', '=', 'rr.recipe_id')
        ->whereNull('rr.deleted_at')->where('r.is_sales_recipe', true)->get(['rr.ingredient_id']);

    expect($amGericht)->toHaveCount(3)
        ->and($amGericht->pluck('ingredient_id')->filter())->toHaveCount(3);   // alle jetzt gebunden
});

it('»Gesamt« bleibt stehen — das ist Rang 0, kein Fehlschlag', function () {
    $lasagne = ($this->rezept)('Lasagne', true);
    ($this->alteZeile)($lasagne, 'Gesamt', ['temp_c' => 170, 'duration_min' => 25]);

    $this->artisan('foodalchemist:regeneration-hochziehen --apply')->assertExitCode(0);

    $zeile = DB::table('foodalchemist_recipe_regenerations')
        ->where('recipe_id', $lasagne->id)->whereNull('deleted_at')->first();

    expect($zeile)->not->toBeNull()
        ->and($zeile->ingredient_id)->toBeNull()
        ->and((int) $zeile->temp_c)->toBe(170);
});

it('Dry-Run ist der Default und ändert nichts', function () {
    $ratatouille = ($this->rezept)('Ratatouille');
    $g = ($this->rezept)('Teller', true);
    ($this->hinein)($g, $ratatouille);
    ($this->alteZeile)($g, 'Ratatouille', ['temp_c' => 150]);

    $this->artisan('foodalchemist:regeneration-hochziehen')->assertExitCode(0);

    expect(DB::table('foodalchemist_recipe_regenerations')
        ->where('recipe_id', $ratatouille->id)->count())->toBe(0);
});

it('hebt die Behälter-Skalare auf die Zweck-Achse — ohne die alte Handzahl', function () {
    $gn = DB::table('foodalchemist_vocab_containers')->insertGetId([
        'uuid' => (string) \Illuminate\Support\Str::uuid7(), 'team_id' => $this->rootTeam->id,
        'slug' => 'gn_11_65mm', 'name' => 'GN 1/1 65mm', 'sort_order' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $gericht = ($this->rezept)('Teller: Ragout', true);
    $gericht->forceFill(['container_warm_vocab_id' => $gn, 'container_warm_count' => 7])->save();

    $this->artisan('foodalchemist:regeneration-hochziehen --apply')->assertExitCode(0);

    $zeile = DB::table('foodalchemist_recipe_containers')->where('recipe_id', $gericht->id)->first();

    // warm → regenerieren: aus einer Temperatur-Achse wird eine Prozess-Achse.
    expect($zeile->zweck)->toBe('regenerieren')
        ->and((int) $zeile->container_vocab_id)->toBe($gn)
        // Die 7 war nie an eine Menge gebunden — sie wird ab jetzt gerechnet, nicht uebernommen.
        ->and($zeile->referenz_menge_kg)->toBeNull();
});

it('läuft zweimal ohne Schaden', function () {
    $ratatouille = ($this->rezept)('Ratatouille');
    $g = ($this->rezept)('Teller', true);
    ($this->hinein)($g, $ratatouille);
    ($this->alteZeile)($g, 'Ratatouille', ['temp_c' => 150]);

    $this->artisan('foodalchemist:regeneration-hochziehen --apply')->assertExitCode(0);
    $this->artisan('foodalchemist:regeneration-hochziehen --apply')->assertExitCode(0);

    expect(DB::table('foodalchemist_recipe_regenerations')
        ->where('recipe_id', $ratatouille->id)->whereNull('deleted_at')->count())->toBe(1);
});

it('trifft eine Komponente auch mit Zustands-Zusatz im Label', function () {
    // Echtdaten-Befund (demo): sechs von acht Review-Faellen waren Labels wie
    // »Ananas-Carpaccio (TK)« gegen die Komponente »Ananas-Carpaccio«. Der Zusatz beschreibt
    // den Zustand, nicht ein anderes Produkt.
    $sorbet = ($this->rezept)('Kokosnusssorbet');
    $dessert = ($this->rezept)('Dessert: Ananas', true);
    ($this->hinein)($dessert, $sorbet);
    ($this->alteZeile)($dessert, 'Kokosnusssorbet (TK)', ['temp_c' => -18]);

    $this->artisan('foodalchemist:regeneration-hochziehen --apply')->assertExitCode(0);

    $default = DB::table('foodalchemist_recipe_regenerations')
        ->where('recipe_id', $sorbet->id)->whereNull('deleted_at')->first();

    expect($default)->not->toBeNull()->and((int) $default->temp_c)->toBe(-18);
});

it('bindet eine Grundprodukt-Zeile als Override, statt sie in die Review zu schieben', function () {
    // »Erbse Microgreens: frisch, geerntet« — ein GP kann keinen eigenen Default tragen (Rang 3
    // leitet ihn aus dem Zustand ab), aber die vorhandene Zeile ist eine getroffene Entscheidung.
    $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'gp_key' => 'g|kresse',
        'name' => 'Erbse Microgreens: frisch, geerntet', 'condition' => 'frisch',
    ]);

    $teller = ($this->rezept)('Teller: Amuse', true);
    $zutat = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $teller->id, 'gp_id' => $gp->id,
        'raw_text' => $gp->name, 'quantity' => '5',
        'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1,
    ]);
    ($this->alteZeile)($teller, 'Erbse Microgreens: frisch, geerntet', ['duration_min' => 0]);

    $this->artisan('foodalchemist:regeneration-hochziehen --apply')->assertExitCode(0);

    $zeile = DB::table('foodalchemist_recipe_regenerations')
        ->where('recipe_id', $teller->id)->whereNull('deleted_at')->first();

    expect((int) $zeile->ingredient_id)->toBe((int) $zutat->id);   // gebunden, nicht verwaist
});

it('der Doppelpunkt der GP-Benennung ist kein Unterschied', function () {
    // Echtdaten: das Label hiess »Himbeeren frisch«, das Grundprodukt »Himbeeren: frisch« —
    // Regelwerk §6 setzt »Name: Eigenschaft«, die handgetippten Labels nicht.
    $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'gp_key' => 'g|himb',
        'name' => 'Himbeeren: frisch', 'condition' => 'frisch',
    ]);
    $teller = ($this->rezept)('Dessert: Beeren', true);
    $zutat = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $teller->id, 'gp_id' => $gp->id,
        'raw_text' => 'Himbeeren', 'quantity' => '25',
        'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1,
    ]);
    ($this->alteZeile)($teller, 'Himbeeren frisch', ['duration_min' => 0]);

    $this->artisan('foodalchemist:regeneration-hochziehen --apply')->assertExitCode(0);

    expect((int) DB::table('foodalchemist_recipe_regenerations')
        ->where('recipe_id', $teller->id)->whereNull('deleted_at')->value('ingredient_id'))->toBe((int) $zutat->id);
});

it('bindet NICHT, wenn ein Sammel-Grundprodukt mehrere Komponenten abdeckt', function () {
    // Echtdaten: vier Labels (Carpaccio, Mousse, Schnee, Knusper) gegen EIN Grundprodukt
    // »Ananas-Dessertkomponenten: frisch, Mousse, Schnee, Knusper«. Token-Enthaltensein wuerde
    // hier binden — und damit vier verschiedene Entscheidungen auf eine Zeile werfen.
    $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'gp_key' => 'g|sammel',
        'name' => 'Ananas-Dessertkomponenten: frisch, Mousse, Schnee, Knusper', 'condition' => 'TK',
    ]);
    $teller = ($this->rezept)('Dessert: Ananas', true);
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $teller->id, 'gp_id' => $gp->id,
        'raw_text' => 'Desserts Base Ananas', 'quantity' => '35',
        'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1,
    ]);
    ($this->alteZeile)($teller, 'Estragonschnee', ['duration_min' => 0]);

    $this->artisan('foodalchemist:regeneration-hochziehen --apply')->assertExitCode(0);

    // Unveraendert, ungebunden — gehoert einem Menschen vorgelegt.
    expect(DB::table('foodalchemist_recipe_regenerations')
        ->where('recipe_id', $teller->id)->whereNull('deleted_at')->value('ingredient_id'))->toBeNull();
});
