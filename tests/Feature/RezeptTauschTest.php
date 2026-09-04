<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\MatchMethod;
use Platform\FoodAlchemist\Livewire\Recipes\DetailPanel;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * „Basisrezept in allen Verwendungen tauschen" (Dominique 2026-09-04) — Pendant zum
 * GP-Tausch, gleichzeitig im Detail-Panel und im Editor (RecipeModal). Umgehängt wird
 * NUR in eigenen Eltern (D1); Zyklen und Selbstreferenz bleiben harte Invarianten.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    // Sub-Rezept-Zeile: makeIngredient legt die Zeile an, die FK zeigt danach aufs Rezept.
    $this->subZeile = function ($parent, $sub, string $menge = '300', int $pos = 1) {
        $z = $this->makeIngredient($parent, $sub->name, null, $menge, $pos);
        $z->update(['referenced_recipe_id' => $sub->id]);

        return $z->refresh();
    };
});

it('Detail-Panel: hängt alle Verwendungen aufs Ziel um und markiert die Provenienz', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $alt = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');
    $neu = $this->makeRecipe($this->rootTeam, 'Jus: Kalb dunkel');
    $teller = $this->makeRecipe($this->rootTeam, 'Kalbsrücken mit Jus', ['is_sales_recipe' => true]);
    $sauce = $this->makeRecipe($this->rootTeam, 'Sauce: Rahm');
    $z1 = ($this->subZeile)($teller, $alt);
    $z2 = ($this->subZeile)($sauce, $alt);

    Livewire::test(DetailPanel::class)
        ->call('zeige', $alt->id)
        ->set('tauschSuche', 'dunkel')
        ->call('rezeptErsetzen', $neu->id)
        ->assertSet('fehlerTausch', null)
        ->assertSet('tauschSuche', '')
        ->assertSet('hinweisTausch', fn ($h) => is_string($h) && str_contains($h, 'umgehängt'))
        ->assertDispatched('recipe-gespeichert');

    expect((int) $z1->refresh()->referenced_recipe_id)->toBe($neu->id)
        ->and((int) $z2->refresh()->referenced_recipe_id)->toBe($neu->id)
        ->and($z1->match_method)->toBe(MatchMethod::OverrideSubrecipe)
        ->and((float) $z1->quantity)->toBe(300.0);           // Menge/Einheit bleiben unberührt
});

it('Editor: derselbe Tausch läuft im Verwaltungs-Reiter des RecipeModal', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $alt = $this->makeRecipe($this->rootTeam, 'Fond: Geflügel');
    $neu = $this->makeRecipe($this->rootTeam, 'Fond: Geflügel klar');
    $suppe = $this->makeRecipe($this->rootTeam, 'Suppe: Geflügel');
    $zeile = ($this->subZeile)($suppe, $alt);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $alt->id)
        ->assertSeeHtml("tab === 'verwaltung'")
        ->set('tauschSuche', 'klar')
        ->call('rezeptErsetzen', $neu->id)
        ->assertSet('fehlerTausch', null)
        ->assertSet('hinweisTausch', fn ($h) => is_string($h) && str_contains($h, 'umgehängt'));

    expect((int) $zeile->refresh()->referenced_recipe_id)->toBe($neu->id);
});

it('weist den Tausch auf sich selbst ab', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $alt = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');
    $teller = $this->makeRecipe($this->rootTeam, 'Kalbsrücken');
    ($this->subZeile)($teller, $alt);

    Livewire::test(DetailPanel::class)
        ->call('zeige', $alt->id)
        ->call('rezeptErsetzen', $alt->id)
        ->assertSet('fehlerTausch', fn ($f) => is_string($f) && str_contains($f, 'identisch'));
});

it('weist einen Tausch ab, der einen Zyklus erzeugen würde', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $alt = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');
    $ziel = $this->makeRecipe($this->rootTeam, 'Jus: Kalb Reduktion');
    $teller = $this->makeRecipe($this->rootTeam, 'Kalbsrücken');
    ($this->subZeile)($teller, $alt);
    ($this->subZeile)($ziel, $teller);                       // Ziel enthält den Eltern-Teller

    Livewire::test(DetailPanel::class)
        ->call('zeige', $alt->id)
        ->call('rezeptErsetzen', $ziel->id)
        ->assertSet('fehlerTausch', fn ($f) => is_string($f) && str_contains($f, 'Zyklus'));

    expect(FoodAlchemistRecipeIngredient::where('recipe_id', $teller->id)
        ->where('referenced_recipe_id', $alt->id)->exists())->toBeTrue();
});

it('lässt geerbte Eltern-Rezepte unberührt und meldet sie (D1)', function () {
    $this->actingAs($this->makeUser($this->childA));
    $alt = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');            // geerbt, aber sichtbar
    $neu = $this->makeRecipe($this->childA, 'Jus: Kalb eigen');
    $eigen = $this->makeRecipe($this->childA, 'Kalbsrücken (Kind A)');
    $master = $this->makeRecipe($this->rootTeam, 'Kalbsrücken (Master)');
    $zEigen = ($this->subZeile)($eigen, $alt);
    $zMaster = ($this->subZeile)($master, $alt);

    Livewire::test(DetailPanel::class)
        ->call('zeige', $alt->id)
        ->call('rezeptErsetzen', $neu->id)
        ->assertSet('fehlerTausch', null)
        ->assertSet('hinweisTausch', fn ($h) => is_string($h) && str_contains($h, 'geerbt'));

    expect((int) $zEigen->refresh()->referenced_recipe_id)->toBe($neu->id)
        ->and((int) $zMaster->refresh()->referenced_recipe_id)->toBe($alt->id);
});

it('meldet Eltern, in denen das Ziel schon steckte (zwei Zeilen danach)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $alt = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');
    $neu = $this->makeRecipe($this->rootTeam, 'Jus: Kalb dunkel');
    $teller = $this->makeRecipe($this->rootTeam, 'Kalbsrücken');
    ($this->subZeile)($teller, $alt, '300', 1);
    ($this->subZeile)($teller, $neu, '100', 2);

    Livewire::test(DetailPanel::class)
        ->call('zeige', $alt->id)
        ->call('rezeptErsetzen', $neu->id)
        ->assertSet('hinweisTausch', fn ($h) => is_string($h) && str_contains($h, 'schon enthalten'));

    expect(FoodAlchemistRecipeIngredient::where('recipe_id', $teller->id)
        ->where('referenced_recipe_id', $neu->id)->count())->toBe(2);
});

it('Bilanz trennt eigene von geerbten Verwendungen', function () {
    $this->actingAs($this->makeUser($this->childA));
    $alt = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');
    $eigen = $this->makeRecipe($this->childA, 'Kalbsrücken (Kind A)');
    $master = $this->makeRecipe($this->rootTeam, 'Kalbsrücken (Master)');
    ($this->subZeile)($eigen, $alt);
    ($this->subZeile)($master, $alt);

    $bilanz = app(RecipeService::class)->verwendungsBilanz($this->childA, $alt->id);

    expect($bilanz)->toMatchArray(['zeilen' => 1, 'rezepte' => 1, 'fremd_zeilen' => 1, 'fremd_rezepte' => 1]);
});
