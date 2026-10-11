<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Services\RecipeGenerationContextService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Services\RecipeKomponentenPlanService;
use Platform\FoodAlchemist\Services\RecipeReviseService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Support\ZeilenFunktion;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Rollen-Paket (Dominique 11.10.): keine feste Rolle am Basisrezept, dafür die FUNKTION als Feld der Zeile (Vokabular
 * = Regel rezeptzeile.funktion). Vorher stand die Plan-Funktion nur als Text in der Notiz („Komponente laut Plan
 * (basis)“). Dazu die Nutzungs-Historie eines Bestandsbausteins dort, wo über den Bestand entschieden wird (Weg A).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->g = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'g'],
        ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->gp = fn (string $name) => FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'zf|' . bin2hex(random_bytes(4)),
        'name' => $name, 'status' => 'approved', 'is_platzhalter' => false]);
});

it('Vokabular aus der Regel: Aliase führen auf den Wert, Fremdes wird null; ohne Regel gilt die Konstante', function () {
    expect(ZeilenFunktion::kanonisch('Säure'))->toBe('saeure')
        ->and(ZeilenFunktion::kanonisch('würze'))->toBe('wuerze')
        ->and(ZeilenFunktion::kanonisch('Crunch'))->toBe('textur')        // Alias nur in der Regel, nicht in der Konstante
        ->and(ZeilenFunktion::kanonisch('komponente'))->toBeNull()
        ->and(ZeilenFunktion::werte())->toContain('textur');

    \Platform\FoodAlchemist\Models\FoodAlchemistRule::where('schluessel', ZeilenFunktion::SCHLUESSEL)->update(['aktiv' => false]);
    \Platform\FoodAlchemist\Services\Regeln\RegelBuch::vergessen();
    expect(ZeilenFunktion::werte())->toBe(RecipeKomponentenPlanService::FUNKTIONEN)
        ->and(ZeilenFunktion::kanonisch('saeure'))->toBe('saeure');
});

it('Plan-Funktion landet im Feld der Zeile, nicht nur in der Notiz', function () {
    $zeilen = RecipeKomponentenPlanService::einmischen([], [['name' => 'Gel: Limette', 'funktion' => 'säure', 'menge' => 40, 'einheit' => 'g']]);
    expect($zeilen[0]['funktion'])->toBe('saeure')->and($zeilen[0]['note'])->toBe('Komponente laut Plan');

    $res = app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'Test', [
        'plan_komponenten' => [['name' => 'Gel: Limette', 'funktion' => 'saeure', 'menge' => 40, 'einheit' => 'g', 'bestand' => null]],
    ], ['name' => 'Püree: Sellerie', 'zutaten' => [['text' => 'Knollensellerie: frisch', 'quantity' => 500, 'unit' => 'g', 'funktion' => 'Basis']]]);
    $nachText = $res['recipe']->ingredients()->get()->keyBy('display_name');

    expect($nachText['Gel: Limette']->funktion)->toBe('saeure')
        ->and($nachText['Knollensellerie: frisch']->funktion)->toBe('basis');
});

it('Generator: ungültige Funktion wird null; Speichern ohne das Feld löscht keine Funktion', function () {
    $res = app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'Test', [],
        ['name' => 'Püree: Sellerie', 'zutaten' => [['text' => 'Knollensellerie: frisch', 'quantity' => 500, 'unit' => 'g', 'funktion' => 'komponente']]]);
    $z = $res['recipe']->ingredients()->first();
    expect($z->funktion)->toBeNull();

    $z->update(['funktion' => 'basis']);
    app(RecipeService::class)->syncIngredients($this->rootTeam, $res['recipe']->id, [[
        'id' => $z->id, 'gp_id' => $z->gp_id, 'display_name' => 'Knollensellerie: frisch', 'raw_text' => 'Knollensellerie: frisch',
        'quantity' => '600', 'unit_vocab_id' => $this->g->id]]);
    expect($z->fresh()->funktion)->toBe('basis')->and((float) $z->fresh()->quantity)->toBe(600.0);
});

it('Überarbeiten: die KI ergänzt eine fehlende Funktion, eine vorhandene bleibt ohne KI-Wert', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Püree: Sellerie', ['status' => 'draft']);
    $a = $this->makeIngredient($r, 'Knollensellerie', ($this->gp)('Knollensellerie: frisch'), '500', 1);
    $b = $this->makeIngredient($r, 'Butter', ($this->gp)('Butter: frisch'), '50', 2);
    $a->update(['funktion' => 'basis']);

    $zeilen = app(RecipeReviseService::class)->syncZeilen($r->fresh('ingredients'), [
        ['id' => $a->id, 'text' => 'Knollensellerie', 'quantity' => 500, 'einheit_slug' => 'g'],
        ['id' => $b->id, 'text' => 'Butter', 'quantity' => 50, 'einheit_slug' => 'g', 'funktion' => 'fett'],
    ]);

    expect(array_column($zeilen, 'funktion'))->toBe(['basis', 'fett']);
});

it('Überarbeiten: KI liefert abweichende Funktion für eine bestehende Zeile → die alte bleibt; bei getauschter Zutat gilt der KI-Wert', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Püree: Sellerie', ['status' => 'draft']);
    $a = $this->makeIngredient($r, 'Knollensellerie', ($this->gp)('Knollensellerie: frisch'), '500', 1);
    $b = $this->makeIngredient($r, 'Schalotten', ($this->gp)('Schalotten: frisch, ganz'), '50', 2);
    $a->update(['funktion' => 'basis']);
    $b->update(['funktion' => 'aroma']);

    $zeilen = app(RecipeReviseService::class)->syncZeilen($r->fresh(['ingredients.gp']), [
        ['id' => $a->id, 'text' => 'Knollensellerie: frisch', 'quantity' => 500, 'einheit_slug' => 'g', 'funktion' => 'textur'],
        ['id' => $b->id, 'text' => 'Lauch: frisch', 'quantity' => 50, 'einheit_slug' => 'g', 'funktion' => 'basis'],
        ['text' => 'Butter', 'quantity' => 30, 'einheit_slug' => 'g', 'funktion' => 'fett'],
    ]);

    expect(array_column($zeilen, 'funktion'))->toBe(['basis', 'basis', 'fett']);
});

it('Nutzungs-Historie: am Bestandstreffer, in abgelehnt und im KI-Kontext der Generator-Zeile', function () {
    $jus = $this->makeRecipe($this->rootTeam, 'Jus: Rind');
    $entwurf = $this->makeRecipe($this->rootTeam, 'Jus: Kalb', ['status' => 'draft']);
    foreach ([['aroma_treiber', $jus], ['aroma_treiber', $jus], ['komponente', $jus], ['aroma_treiber', $entwurf]] as $i => [$rolle, $sub]) {
        $gericht = $this->makeRecipe($this->rootTeam, '[HG] Gericht ' . $i, ['status' => 'draft', 'is_sales_recipe' => true]);
        $this->makeIngredient($gericht, $sub->name)->update(['referenced_recipe_id' => $sub->id, 'role' => $rolle]);
    }
    $plan = app(RecipeKomponentenPlanService::class);
    $ab = [];

    $treffer = $plan->bestandFuer($this->rootTeam, 'Jus: Rind', [], $ab);
    $plan->bestandFuer($this->rootTeam, 'Jus: Kalb', [], $ab);
    $ctx = app(RecipeGenerationContextService::class)->build($this->rootTeam, 'Rinderfilet-Sauce', [
        'plan_komponenten' => [['name' => 'Jus: Rind', 'menge' => 100, 'einheit' => 'g', 'bestand' => $treffer]],
    ], false);

    expect($treffer['verwendung'])->toBe('in 3 Gerichten: 2× aroma_treiber, 1× komponente')
        ->and(collect($ab)->firstWhere('recipe_id', $entwurf->id)['verwendung'] ?? null)->toBe('in 1 Gericht: 1× aroma_treiber')
        ->and($ctx['prompt']['zusammenstellung']['komponenten'][0]['verwendung'] ?? null)->toBe('in 3 Gerichten: 2× aroma_treiber, 1× komponente')
        ->and($ctx['prompt']['funktionen'] ?? [])->toContain('saeure');
});

it('Doppel-GP mit unterschiedlicher Funktion ist kein Befund', function () {
    $butter = ($this->gp)('Butter: frisch');
    $r = $this->makeRecipe($this->rootTeam, 'Sauce: Beurre blanc', ['status' => 'draft']);
    $this->makeIngredient($r, 'Butter', $butter, '30', 1)->update(['funktion' => 'fett']);
    $this->makeIngredient($r, 'Butter', $butter, '150', 2)->update(['funktion' => 'bindung']);

    expect(collect(app(RecipeConformanceAdapter::class)->deterministischeBefunde($this->rootTeam, (int) $r->id))
        ->where('paragraph', 'Zeilen'))->toHaveCount(0);
});

it('MCP im Lockstep: recipes.GET liefert funktion, recipe_ingredients.PUT schreibt sie', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Püree: Sellerie', ['status' => 'draft']);
    $z = $this->makeIngredient($r, 'Knollensellerie', ($this->gp)('Knollensellerie: frisch'), '500', 1);
    $kontext = new ToolContext($this->user, $this->rootTeam);
    $registry = app(ToolRegistry::class);

    $put = $registry->get('foodalchemist.recipe_ingredients.PUT')->execute(['recipe_id' => $r->id, 'zutaten' => [
        ['id' => $z->id, 'name' => 'Knollensellerie', 'gp_id' => $z->gp_id, 'quantity' => 500, 'unit' => 'g', 'funktion' => 'Basis']]], $kontext);
    $get = $registry->get('foodalchemist.recipes.GET')->execute(['id' => $r->id], $kontext);

    expect($put->success)->toBeTrue()
        ->and($z->fresh()->funktion)->toBe('basis')
        ->and($get->data['zutaten'][0]['funktion'] ?? null)->toBe('basis');
});

it('recipes.INSTANTIATE: Vorlagen-Funktion wird kopiert, eine Funktion in der Bindung ersetzt sie', function () {
    $platzhalter = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'zf|ph', 'name' => '[Fett]', 'status' => 'approved', 'is_platzhalter' => true]);
    $tpl = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'tpl_' . bin2hex(random_bytes(4)),
        'name' => 'Grundpüree', 'status' => 'draft', 'yield_kg' => 1.0, 'is_sales_recipe' => false, 'is_template' => true]);
    $this->makeIngredient($tpl, 'Kartoffel', ($this->gp)('Kartoffel: frisch'), '800', 1)->update(['funktion' => 'basis']);
    $slot = $this->makeIngredient($tpl, '[Fett]', $platzhalter, '100', 2);
    $slot->update(['funktion' => 'fett']);
    $butter = ($this->gp)('Butter: frisch');

    $res = app(ToolRegistry::class)->get('foodalchemist.recipes.INSTANTIATE')->execute(['template_id' => $tpl->id, 'name' => 'Grundpüree: Butter',
        'bindings' => [['ingredient_id' => $slot->id, 'gp_id' => $butter->id, 'funktion' => 'Bindung']]], new ToolContext($this->user, $this->rootTeam));
    $zeilen = FoodAlchemistRecipe::find($res->data['id'])->ingredients()->orderBy('position')->pluck('funktion')->all();

    expect($res->success)->toBeTrue()->and($zeilen)->toBe(['basis', 'bindung']);
});
