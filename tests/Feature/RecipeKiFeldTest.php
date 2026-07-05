<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\Browser;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M4-11/12: KI-Anreicherung (3 Felder Fake-Roundtrip, GL-07) + Workflow
 * (Template, Bulk-Status, Status-Wechsel).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->svc = app(RecipeService::class);
    $this->rezept = $this->svc->create($this->rootTeam, ['name' => 'Fond: Test']);
});

it('M4-11 (DoD 1/3): beschreibung — Fake-Roundtrip ändert Feld + Lineage, Override-First greift', function () {
    $modal = Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->rezept->id)
        ->set('form.description', 'Klarer Fond auf Gemüsebasis.')   // Kontext fürs Echo
        ->call('ai_beschreibung')
        ->call('accept_beschreibung');

    $this->rezept->refresh();
    expect($this->rezept->description)->toBe('Klarer Fond auf Gemüsebasis.')
        ->and($this->rezept->description_source)->toBe('ki')
        ->and((float) $this->rezept->description_ai_confidence)->toBe(0.87);

    // Override-First: manuell gepflegt blockt accept
    $this->rezept->update(['description_source' => 'manual']);
    $modal->call('ai_beschreibung')->call('accept_beschreibung')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'manuell'));

    $modal->set('fehler', null)->call('clear_beschreibung');
    expect($this->rezept->fresh()->description)->toBeNull();
});

it('M4-11 (DoD 2/3): kategorie — Fake-Roundtrip setzt kategorie_id + Lineage-Trio', function () {
    $hg = FoodAlchemistRecipeMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'FO', 'label' => 'Fonds']);
    $kat = FoodAlchemistRecipeCategory::create(['team_id' => $this->rootTeam->id, 'main_group_id' => $hg->id, 'code' => 'KLA', 'label' => 'Klare Fonds']);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->rezept->id)
        ->set('form.category_id', $kat->id)                          // Kontext fürs Echo
        ->call('ai_kategorie')
        ->call('accept_kategorie')
        ->assertSet('fehler', null);

    $this->rezept->refresh();
    expect($this->rezept->category_id)->toBe($kat->id)
        ->and($this->rezept->category_source)->toBe('ki')
        ->and((float) $this->rezept->category_ai_confidence)->toBe(0.87);
});

it('M4-11 (DoD 3/3): garverlust — Vorschlag geclampt, Save schreibt quelle=ki', function () {
    $g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $gp = $this->makeGp($this->rootTeam, 'Karotte');

    $editor = Livewire::test(IngredientEditor::class)->call('oeffnen', $this->rezept->id);

    // Fake echo't den Kontext: verluste kommt zurück wie reingegeben — Clamp auf 0–60
    $antwort = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)
        ->propose('recipe.garverlust', ['zutaten' => [0 => '500 g Karotte'], 'verluste' => [0 => 95]]);
    expect($antwort->werte['verluste'][0])->toBe(95);                 // Echo roh — Clamp passiert in der Komponente

    $editor->call('speichern', [[
        'id' => null, 'gp_id' => $gp->id, 'raw_text' => '500 g Karotte', 'quantity' => '500',
        'unit_vocab_id' => $g->id, 'cooking_loss_pct' => '12', 'cooking_loss_source' => 'ki',
    ]])->assertSet('fehler', null);

    $zutat = $this->rezept->ingredients()->first();
    expect((float) $zutat->cooking_loss_pct)->toBe(12.0)
        ->and($zutat->cooking_loss_source)->toBe('ki');
});

it('M4-12: Template-Toggle, Status-Workflow und Bulk-Status (D1: nur eigene)', function () {
    $zweites = $this->svc->create($this->rootTeam, ['name' => 'Fond: Zwei']);
    $fremd = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create([
        'team_id' => $this->childA->id, 'recipe_key' => 'kind_rezept', 'name' => 'Kind-Rezept', 'status' => 'draft',
    ]);

    $this->svc->setTemplate($this->rootTeam, $this->rezept->id);
    expect($this->rezept->fresh()->is_template)->toBeTrue();

    $this->svc->setStatus($this->rootTeam, $this->rezept->id, 'review');
    expect($this->rezept->fresh()->status->value)->toBe('review');

    $n = $this->svc->bulkStatus($this->rootTeam, [$this->rezept->id, $zweites->id, $fremd->id], 'approved');
    expect($n)->toBe(2)                                               // Kind-Rezept bleibt unberührt (D1)
        ->and($this->rezept->fresh()->status->value)->toBe('approved')
        ->and($zweites->fresh()->status->value)->toBe('approved')
        ->and($fremd->fresh()->status->value)->toBe('draft');
});

it('M4-12: Browser-Bulk-Leiste setzt Status über die Auswahl', function () {
    $zweites = $this->svc->create($this->rootTeam, ['name' => 'Fond: Zwei']);

    Livewire::test(Browser::class)
        ->set('auswahl', [$this->rezept->id => true, $zweites->id => true])
        ->call('bulkStatus', 'review')
        ->assertSet('auswahl', []);

    expect($this->rezept->fresh()->status->value)->toBe('review')
        ->and($zweites->fresh()->status->value)->toBe('review');
});
