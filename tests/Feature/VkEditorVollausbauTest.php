<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M9-01: VK-Editor-Vollparität — neue Felder (Marketing/Eigenschaften/Plating/
 * Notizen) über die VK_FELDER-Whitelist inkl. Lineage-manual-Stempel; Rollen-
 * Spalte nur im VK-Kontext; Rollen-Sync über den Zutaten-Editor-Payload.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake']);

    $this->vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk-m9', 'name' => 'FIN: Hot Dog',
        'status' => 'draft', 'ist_verkaufsrezept' => true,
    ]);
    $this->g = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $gp = $this->makeGp($this->rootTeam, 'Wiener');
    DB::table('foodalchemist_recipe_ingredients')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->vk->id, 'gp_id' => $gp->id, 'raw_text' => 'Wiener', 'display_name' => 'Wiener',
        'menge' => 50, 'einheit_vocab_id' => $this->g->id, 'position' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->zeileId = (int) DB::getPdo()->lastInsertId();
});

it('M9-Felder speichern über die Whitelist; Plating/Marketing manuell ⇒ Lineage manual', function () {
    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->set('form.marketing_text', 'Knuspriger Klassiker.')
        ->set('form.plating_text', '## Aufbau\n1. Bun setzen.')
        ->set('form.arbeitszeit_min', 8)
        ->set('form.nebenkosten_eur', '1.50')
        ->set('form.funktion', 'Fingerfood')
        ->set('form.fertigungstiefe', 'teilfertig')
        ->set('form.notizen_manual', 'Catering-Notiz')
        ->call('speichern')
        ->assertSet('fehler', null);

    $r = $this->vk->fresh();
    expect($r->marketing_text)->toBe('Knuspriger Klassiker.')
        ->and($r->marketing_text_quelle)->toBe('manual')
        ->and($r->plating_quelle)->toBe('manual')
        ->and($r->arbeitszeit_min)->toBe(8)
        ->and((float) $r->nebenkosten_eur)->toBe(1.5)                 // M-K8-Pflege zurück (#379)
        ->and($r->funktion)->toBe('Fingerfood')
        ->and($r->fertigungstiefe)->toBe('teilfertig')
        ->and($r->notizen_manual)->toBe('Catering-Notiz');
});

it('Rollen-Spalte rendert NUR im VK-Kontext; Rollen-Wert geht durch den Sync (V-21)', function () {
    $html = Livewire::test(IngredientEditor::class, ['recipeId' => $this->vk->id, 'eingebettet' => true])->html();
    expect($html)->toContain('data-rolle-select');

    $basis = app(\Platform\FoodAlchemist\Services\RecipeService::class)->create($this->rootTeam, ['name' => 'Fond: Basis']);
    $htmlBasis = Livewire::test(IngredientEditor::class, ['recipeId' => $basis->id, 'eingebettet' => true])->html();
    expect($htmlBasis)->not->toContain('data-rolle-select');

    // Rolle über den Editor-Payload (rows enthalten rolle) — syncIngredients schreibt sie
    Livewire::test(IngredientEditor::class, ['recipeId' => $this->vk->id, 'eingebettet' => true])
        ->call('speichern', [[
            'id' => $this->zeileId, 'gp_id' => DB::table('foodalchemist_recipe_ingredients')->where('id', $this->zeileId)->value('gp_id'),
            'raw_text' => 'Wiener', 'menge' => '50', 'einheit_vocab_id' => $this->g->id, 'rolle' => 'komponente',
        ]])
        ->assertSet('fehler', null);
    expect(DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $this->vk->id)->whereNull('deleted_at')->value('rolle'))
        ->toBe('komponente');
});

it('VK-Editor rendert die neuen Sektionen (Deklaration, Nährwerte, Spezifikation, Plating, KPI-Leiste)', function () {
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->vk->id)->html();
    foreach (['data-deklaration', 'data-vk-naehrwerte-leer', 'data-vk-spezifikation', 'data-vk-plating-text', 'data-vk-editor-kpis', 'data-md-toolbar', 'data-ki-wording', 'data-ki-behaelter', 'data-ki-regeneration'] as $marker) {
        expect($html)->toContain($marker);
    }
});

it('✨-Fake-Pfade sind ehrlich (kein gültiger Wert ⇒ kiFehler, Form unverändert)', function () {
    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->call('ki', 'vehikel')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'echter Provider'))
        ->assertSet('form.servier_vehikel_vocab_id', null);
});

it('✨ Behälter übernimmt validierte Vokabular-IDs in die Form (Mock-Gateway)', function () {
    $warmId = (int) DB::table('foodalchemist_vocab_behaelter')->insertGetId([
        'uuid' => (string) \Illuminate\Support\Str::uuid7(), 'team_id' => $this->rootTeam->id,
        'slug' => 'gn_11_65', 'name' => 'GN 1/1 65mm', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->mock(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class, function ($mock) use ($warmId) {
        $mock->shouldReceive('propose')->andReturn(new \Platform\FoodAlchemist\Services\Ai\AiProposal(
            ['behaelter_warm_id' => $warmId, 'behaelter_warm_anzahl' => 2, 'behaelter_kalt_id' => 999999], 0.9,
        ));
    });

    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->call('ki', 'behaelter')
        ->assertSet('fehler', null)
        ->assertSet('form.behaelter_warm_vocab_id', $warmId)
        ->assertSet('form.behaelter_warm_anzahl', 2)
        ->assertSet('form.behaelter_kalt_vocab_id', null);            // ungültige ID fliegt
});
