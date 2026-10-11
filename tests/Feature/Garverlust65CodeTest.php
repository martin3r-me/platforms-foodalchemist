<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * §6.5 im Code statt im KI-Urteil (Kuratorin 11.10.). Die Prüf-KI sah cooking_loss_pct nie und meldete „Einkochverlust
 * fehlt“ bei jedem Rezept — demo 3880 hatte 16 von 17 Zeilen mit Verlust, die 17. war Salz. Ohne Verlust waren auf demo
 * fast nur Zeilen, die zu Recht keinen haben (Salz, Pfeffer, Butter zum Montieren, Konzentrat, Unterrezepte).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->ml = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'ml'], ['display_de' => 'ml', 'dimension' => 'volume']);
    $this->zeile = function (FoodAlchemistRecipe $r, string $text, string $menge, int $pos, ?float $verlust, bool $fluessig = false) {
        $z = $this->makeIngredient($r, $text, $this->makeGp($this->rootTeam, $text), $menge, $pos);
        $z->update(['cooking_loss_pct' => $verlust, 'cooking_loss_source' => $verlust !== null ? 'ki' : null]
            + ($fluessig ? ['unit_vocab_id' => $this->ml->id] : []));

        return $z;
    };
    $this->befunde = fn (FoodAlchemistRecipe $r) => collect(app(RecipeConformanceAdapter::class)->deterministischeBefunde($this->rootTeam, (int) $r->id))
        ->where('paragraph', '§6.5')->values();
});

it('Jus mit Verlusten (Salz ohne, wie 3880) → kein §6.5', function () {
    $jus = $this->makeRecipe($this->rootTeam, 'Jus: Rind', ['status' => 'draft']);
    ($this->zeile)($jus, 'Rinderknochen: frisch', '3500', 1, 100.0);
    ($this->zeile)($jus, 'Rotwein: konserviert', '1200', 2, 75.0, true);
    ($this->zeile)($jus, 'Leitungswasser: frisch', '5000', 3, 80.0, true);
    ($this->zeile)($jus, 'Salz: trocken', '18', 4, null);

    expect(($this->befunde)($jus))->toHaveCount(0);
});

it('Reduktion: eine Flüssigkeit ohne Verlust → genau ein harter Befund mit dieser Zeile', function () {
    $red = $this->makeRecipe($this->rootTeam, 'Reduktion: Ginger Beer', ['status' => 'draft']);
    ($this->zeile)($red, 'Ginger Beer: konserviert', '1000', 1, 70.0, true);
    ($this->zeile)($red, 'Limettensaft: frisch', '50', 2, null, true);
    ($this->zeile)($red, 'Salz: trocken', '2', 3, null);

    $b = ($this->befunde)($red);
    expect($b)->toHaveCount(1)
        ->and($b[0]['schweregrad'])->toBe('hart')
        ->and($b[0]['feld'])->toBe('zutat:Limettensaft: frisch');
});

it('kein einziger Verlust im Fond → ein Befund am Rezept; Zukauf/teilfertig und Gerichte nie', function () {
    $fond = $this->makeRecipe($this->rootTeam, 'Fond: Kalb', ['status' => 'draft']);
    ($this->zeile)($fond, 'Kalbsknochen: frisch', '3000', 1, null);
    ($this->zeile)($fond, 'Zwiebeln: frisch, ganz', '500', 2, null);
    $hf = $this->makeRecipe($this->rootTeam, 'Brühe: Gemüse (HF)', ['status' => 'draft', 'production_depth' => 'teilfertig']);
    ($this->zeile)($hf, 'Wasser: frisch', '1000', 1, null, true);

    $b = ($this->befunde)($fond);
    expect($b)->toHaveCount(1)->and($b[0]['feld'])->toBe('zutaten')
        ->and(($this->befunde)($hf))->toHaveCount(0);
});

it('Prüfauftrag zeigt der KI den Garverlust je Zeile; die KI-Meldung zu §6.5 ist eine bekannte Ausnahme', function () {
    $jus = $this->makeRecipe($this->rootTeam, 'Jus: Rind', ['status' => 'draft']);
    ($this->zeile)($jus, 'Rotwein: konserviert', '1200', 1, 75.0, true);
    $a = app(RecipeConformanceAdapter::class);

    $zutaten = $a->pruefauftrag($this->rootTeam, (int) $jus->id)['kontext']['zutaten'];
    expect($zutaten[0]['garverlust_pct'])->toBe(75.0)->and($zutaten[0]['garverlust_quelle'])->toBe('ki')
        ->and($a->istBekannteAusnahme(['paragraph' => '§6.5', 'feld' => 'zutaten', 'begruendung' => 'Einkochverlust fehlt']))->toBeTrue()
        ->and($a->istBekannteAusnahme(['paragraph' => '§6.1', 'feld' => 'name', 'begruendung' => 'x']))->toBeFalse();
});
