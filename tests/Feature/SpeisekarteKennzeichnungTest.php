<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Speisekarte Stufe B — LMIV-Kennzeichnung (Allergen-/Zusatzstoff-Fußnoten, ALL-MAXIMAL)
 * + Brutto-Preis + nur-verwendete Legende im Dokument-Datensatz.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->karten = app(SpeisekarteService::class);
});

if (! function_exists('skGericht')) {
    function skGericht(int $teamId, string $key, string $name, array $felder = []): FoodAlchemistRecipe
    {
        $r = FoodAlchemistRecipe::create([
            'team_id' => $teamId, 'recipe_key' => $key, 'name' => $name, 'status' => 'approved',
            'is_sales_recipe' => true, 'sales_net' => 24.00, 'ek_total_eur' => 8.00,
        ]);
        $r->forceFill(array_merge(['allergens_confidence' => 'high'], $felder))->save();

        return $r->refresh();
    }
}

it('Stufe B: Position bekommt Allergen-Code, Spuren als *, Zusatzstoff-Nummer', function () {
    $gluten = skGericht($this->rootTeam->id, 'skb1', 'Schnitzel', ['allergen_gluten' => 'enthalten', 'additive_with_dye' => 3]);
    $spuren = skGericht($this->rootTeam->id, 'skb2', 'Salat', ['allergen_tree_nuts' => 'spuren']);

    $karte = $this->karten->create($this->rootTeam, ['name' => 'Karte']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge']);
    $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $gluten->id]);
    $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $spuren->id]);

    $dok = $this->karten->dokumentDaten($this->rootTeam, $karte->refresh());
    $positionen = $dok['rubriken'][0]['positionen'];

    // Gluten = Allergen A (erster EU-Eintrag), Zusatzstoff with_dye = Nummer.
    expect($positionen[0]['codes'])->toContain('A');
    expect(collect($positionen[0]['codes'])->contains(fn ($c) => is_numeric($c)))->toBeTrue();
    // Nuss-Spuren → Code mit Stern
    expect(collect($positionen[1]['codes'])->contains(fn ($c) => str_ends_with($c, '*')))->toBeTrue();
});

it('Stufe B: Legende enthält nur tatsächlich vorkommende Codes', function () {
    $gluten = skGericht($this->rootTeam->id, 'skb3', 'Pasta', ['allergen_gluten' => 'enthalten']);
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id);
    $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $gluten->id]);

    $dok = $this->karten->dokumentDaten($this->rootTeam, $karte->refresh());
    $labels = collect($dok['legende']['allergene'])->pluck('label');
    expect($labels->contains(fn ($l) => str_contains(mb_strtolower($l), 'gluten')))->toBeTrue()
        ->and(count($dok['legende']['allergene']))->toBe(1); // NUR Gluten, nicht alle 14
});

it('Stufe B: Brutto-Preis = Netto × (1 + MwSt)', function () {
    $g = skGericht($this->rootTeam->id, 'skb4', 'Filet'); // sales_net 24,00
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K', 'preis_anzeige_brutto' => true]);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id);
    $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $g->id]);

    $dok = $this->karten->dokumentDaten($this->rootTeam, $karte->refresh());
    $pos = $dok['rubriken'][0]['positionen'][0];
    expect($pos['vk_netto'])->toBe(24.0)
        ->and($pos['vk_brutto'])->toBe(round(24.0 * (1 + $dok['mwstSatz'] / 100), 2));
});
