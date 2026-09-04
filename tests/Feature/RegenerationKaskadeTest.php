<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeRegeneration;
use Platform\FoodAlchemist\Services\RegenerationCascadeService as Kaskade;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 51 — die Regeneration gehört an die Komponente, das Gericht erbt sie.
 *
 * Vorher stand sie NUR am Gericht: dieselbe Angabe in jedem Gericht neu getippt, mit
 * garantierter Drift. Diese Tests halten fest, was die Kaskade leisten muss — und was sie
 * bewusst NICHT tut (Sub-Sub-Komponenten zeigen, Lücken verstecken, TK raten).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->k = new Kaskade;
    $this->nr = 0;

    $this->rezept = function (string $name, bool $vk = false) {
        return FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id,
            'recipe_key' => 'k'.(++$this->nr),
            'name' => $name,
            'status' => 'approved',
            'is_sales_recipe' => $vk,
        ]);
    };

    $this->hinein = function (FoodAlchemistRecipe $ziel, ?FoodAlchemistRecipe $sub = null, ?FoodAlchemistGp $gp = null) {
        return FoodAlchemistRecipeIngredient::create([
            'team_id' => $ziel->team_id, 'recipe_id' => $ziel->id,
            'referenced_recipe_id' => $sub?->id, 'gp_id' => $gp?->id,
            'raw_text' => $sub?->name ?? $gp?->name ?? 'x',
            'quantity' => '100', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id,
            'position' => 1,
        ]);
    };

    $this->regen = function (FoodAlchemistRecipe $r, array $werte = [], ?int $ingredientId = null) {
        return FoodAlchemistRecipeRegeneration::create(array_merge([
            'team_id' => $r->team_id, 'recipe_id' => $r->id,
            'component_label' => $r->name, 'ingredient_id' => $ingredientId,
            'temp_c' => 160, 'duration_min' => 12, 'sort_order' => 0,
        ], $werte));
    };
});

it('zwei Gerichte erben denselben Default — und folgen, wenn er sich ändert', function () {
    $komponente = ($this->rezept)('Ragout: Rind');
    ($this->regen)($komponente, ['temp_c' => 160, 'duration_min' => 12]);

    $a = ($this->rezept)('Teller: Rind mit Kloss', true);
    $b = ($this->rezept)('Teller: Rind mit Nudeln', true);
    ($this->hinein)($a, $komponente);
    ($this->hinein)($b, $komponente);

    foreach ([$a, $b] as $gericht) {
        $zeile = $this->k->fuerRezept($gericht)['komponenten'][0];
        expect($zeile['herkunft'])->toBe(Kaskade::HERKUNFT_GEERBT)
            ->and($zeile['temp_c'])->toBe(160)
            ->and($zeile['von_recipe_name'])->toBe('Ragout: Rind');
    }

    // Einmal an der Komponente ändern — beide Teller folgen, ohne dass jemand sie anfasst.
    FoodAlchemistRecipeRegeneration::where('recipe_id', $komponente->id)->update(['temp_c' => 140]);

    foreach ([$a, $b] as $gericht) {
        expect($this->k->fuerRezept($gericht)['komponenten'][0]['temp_c'])->toBe(140);
    }
});

it('ein Override gilt nur für SEIN Gericht — das andere folgt weiter dem Default', function () {
    $komponente = ($this->rezept)('Ratatouille');
    ($this->regen)($komponente, ['temp_c' => 150]);

    $warm = ($this->rezept)('Teller: Beilage warm', true);
    $kalt = ($this->rezept)('Teller: Antipasto kalt', true);
    $zutatWarm = ($this->hinein)($warm, $komponente);
    ($this->hinein)($kalt, $komponente);

    // Dasselbe Ratatouille, hier bewusst anders — ein legitimer Override, kein Fehler.
    ($this->regen)($warm, ['temp_c' => 180, 'duration_min' => 6], $zutatWarm->id);

    $ausWarm = $this->k->fuerRezept($warm)['komponenten'][0];
    $ausKalt = $this->k->fuerRezept($kalt)['komponenten'][0];

    expect($ausWarm['herkunft'])->toBe(Kaskade::HERKUNFT_OVERRIDE)
        ->and($ausWarm['temp_c'])->toBe(180)
        ->and($ausKalt['herkunft'])->toBe(Kaskade::HERKUNFT_GEERBT)
        ->and($ausKalt['temp_c'])->toBe(150);
});

it('der Fond im Ragout taucht am Gericht NICHT auf — Tiefe 1', function () {
    $fond = ($this->rezept)('Fond: Braun');
    ($this->regen)($fond, ['temp_c' => 90]);

    $ragout = ($this->rezept)('Ragout: Rind');
    ($this->hinein)($ragout, $fond);
    ($this->regen)($ragout, ['temp_c' => 160]);

    $teller = ($this->rezept)('Teller: Rind', true);
    ($this->hinein)($teller, $ragout);

    $labels = collect($this->k->fuerRezept($teller)['komponenten'])->pluck('label');

    // Ein Fond wird produziert und gelagert, nicht am Pass gewärmt.
    expect($labels)->toContain('Ragout: Rind')->and($labels)->not->toContain('Fond: Braun');
});

it('die Gesamt-Zeile steht NEBEN den Komponenten, nicht statt ihrer', function () {
    $bechamel = ($this->rezept)('Béchamel');
    ($this->regen)($bechamel, ['temp_c' => 70]);

    $lasagne = ($this->rezept)('Lasagne', true);
    ($this->hinein)($lasagne, $bechamel);
    ($this->regen)($lasagne, ['temp_c' => 170, 'duration_min' => 25]);   // ingredient_id NULL = Gesamt

    $out = $this->k->fuerRezept($lasagne);

    expect($out['gesamt'])->toHaveCount(1)
        ->and($out['gesamt'][0]['herkunft'])->toBe(Kaskade::HERKUNFT_GESAMT)
        ->and($out['gesamt'][0]['temp_c'])->toBe(170)
        ->and($out['komponenten'])->toHaveCount(1)
        ->and($out['komponenten'][0]['herkunft'])->toBe(Kaskade::HERKUNFT_GEERBT);
});

it('am Basisrezept bedeutet dieselbe Zeile »das bin ich«', function () {
    $komponente = ($this->rezept)('Pickles: Buchenpilze');
    ($this->regen)($komponente, ['device_vocab_id' => null, 'duration_min' => 0]);

    $out = $this->k->fuerRezept($komponente);

    expect($out['gesamt'])->toHaveCount(1)
        ->and($out['gesamt'][0]['ist_kalt'])->toBeTrue()
        ->and($out['komponenten'])->toBe([]);
});

it('ein frisches Grundprodukt folgt der Regel, ein TK-Produkt meldet eine Lücke', function () {
    $kraut = FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'gp_key' => 'g|kresse', 'name' => 'Kresse: frisch', 'condition' => 'frisch',
    ]);
    $erbse = FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'gp_key' => 'g|erbse', 'name' => 'Erbse: TK', 'condition' => 'TK',
    ]);

    $teller = ($this->rezept)('Teller: Gemischt', true);
    ($this->hinein)($teller, null, $kraut);
    ($this->hinein)($teller, null, $erbse);

    $out = $this->k->fuerRezept($teller);
    $nach = collect($out['komponenten'])->keyBy('label');

    expect($nach['Kresse: frisch']['herkunft'])->toBe(Kaskade::HERKUNFT_REGEL)
        ->and($nach['Kresse: frisch']['ist_kalt'])->toBeTrue()
        // TK steht bewusst NICHT in der Regel-Tabelle: ob aufgetaut, regeneriert oder direkt
        // verarbeitet wird, haengt am Produkt. Ein Default waere geraten.
        ->and($nach['Erbse: TK']['herkunft'])->toBe(Kaskade::HERKUNFT_FEHLT)
        ->and($out['luecken'])->toBe(1);
});

it('eine entfernte Zutat hinterlässt einen gemeldeten Waisen, keinen Absturz', function () {
    $komponente = ($this->rezept)('Sauce: Pfeffer');
    $teller = ($this->rezept)('Teller: Steak', true);
    $zutat = ($this->hinein)($teller, $komponente);
    ($this->regen)($teller, ['temp_c' => 75], $zutat->id);

    // syncIngredients SOFT-löscht — nullOnDelete greift dabei nicht.
    $zutat->delete();

    $out = $this->k->fuerRezept($teller->fresh());

    expect($out['komponenten'])->toBe([])
        ->and($out['verwaist'])->toHaveCount(1)
        ->and($out['verwaist'][0]['ingredient_id'])->toBe((int) $zutat->id);
});
