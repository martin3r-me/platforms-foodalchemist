<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\DetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SpeisenKlassenService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M6-05: ai_classify_speisen_klasse + ai_verteile_rollen — GL-07-Lebenszyklus
 * (Fake-Roundtrip → Accept schreibt Fachwert + Lineage; reject unberührt;
 * null = ehrlicher Nicht-Treffer ohne Schreibversuch — D-6 §7.5).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->svc = app(SpeisenKlassenService::class);

    $hg = FoodAlchemistDishMainGroup::create(['code' => 'HG', 'label' => 'Hauptgang']);
    $this->class = FoodAlchemistDishClass::create(['dish_main_group_id' => $hg->id, 'code' => 'HG_FLEISCH', 'label' => 'Fleisch', 'diet_form' => 'fleisch']);

    $this->vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk', 'name' => 'HG: Filet | Jus', 'status' => 'draft',
        'is_sales_recipe' => true,
    ]);
    $g = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $gp = $this->makeGp($this->rootTeam, 'Rinderfilet');
    DB::table('foodalchemist_recipe_ingredients')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->vk->id, 'gp_id' => $gp->id, 'raw_text' => 'Rinderfilet', 'display_name' => 'Rinderfilet',
        'quantity' => 500, 'unit_vocab_id' => $g->id, 'position' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->zeileId = (int) DB::getPdo()->lastInsertId();
});

/**
 * Weg-B-Befund 1 (2026-10-12): Modell A kennt vier flache Diät-Klassen OHNE Hauptgruppe. Der frühere
 * INNER JOIN auf die Hauptgruppe verwarf sie alle — recipe_klasse.POST antwortete mit
 * „Die mitgegebene Taxonomie ist leer" und klasse_id null, obwohl die UI die Klassen anbot.
 */
it('Taxonomie: flache Klassen ohne Hauptgruppe stehen drin, Klassen inaktiver Hauptgruppen nicht', function () {
    $flach = FoodAlchemistDishClass::create(['dish_main_group_id' => null, 'code' => 'VEGAN', 'label' => 'Vegan', 'diet_form' => 'vegan']);
    $tot = FoodAlchemistDishMainGroup::create(['code' => 'ALC', 'label' => 'Alkohol', 'is_inactive' => true]);
    $inaktiv = FoodAlchemistDishClass::create(['dish_main_group_id' => $tot->id, 'code' => 'ALC_X', 'label' => 'Bier', 'diet_form' => 'neutral']);

    $taxonomie = SpeisenKlassenService::taxonomie($this->rootTeam);

    expect($taxonomie)->toHaveKey($flach->id)
        ->and($taxonomie[$flach->id])->toBe('Vegan (vegan)')                 // kein „null / "-Präfix
        ->and($taxonomie)->toHaveKey($this->class->id)
        ->and($taxonomie[$this->class->id])->toBe('HG / Fleisch (fleisch)')
        ->and($taxonomie)->not->toHaveKey($inaktiv->id);

    // Roundtrip: die KI darf die flache Klasse wählen (FakeProvider echo't den Kontext).
    $this->vk->update(['dish_class_id' => $flach->id]);
    $vorschlag = $this->svc->classify($this->rootTeam, $this->vk->id);
    expect($vorschlag['klasse_id'])->toBe($flach->id)
        ->and($vorschlag['klasse_name'])->toBe('Vegan');
});

it('classify: Fake-Roundtrip liefert validierten Vorschlag; Accept schreibt Klasse + Lineage-Trio', function () {
    $this->vk->update(['dish_class_id' => $this->class->id]);   // Kontext fürs Echo

    $vorschlag = $this->svc->classify($this->rootTeam, $this->vk->id);
    expect($vorschlag['klasse_id'])->toBe($this->class->id)
        ->and($vorschlag['klasse_name'])->toBe('Fleisch')
        ->and($vorschlag['confidence'])->toBe(0.87);

    $this->vk->update(['dish_class_id' => null]);
    $this->svc->acceptKlasse($this->rootTeam, $this->vk->id, $vorschlag['klasse_id'], $vorschlag['confidence'], $vorschlag['reasoning']);

    $this->vk->refresh();
    expect($this->vk->dish_class_id)->toBe($this->class->id)
        ->and($this->vk->dish_class_source)->toBe('ki')
        ->and((float) $this->vk->dish_class_ai_confidence)->toBe(0.87);
});

it('classify: ungültige/fehlende KI-Id ⇒ ehrlicher Nicht-Treffer; Panel macht KEINEN Schreibversuch', function () {
    $vorschlag = $this->svc->classify($this->rootTeam, $this->vk->id);  // Kontext-Echo: speisen_klasse_id null
    expect($vorschlag['klasse_id'])->toBeNull();

    Livewire::test(DetailPanel::class, ['recipeId' => $this->vk->id])
        ->call('ai_klassifizieren')
        ->call('accept_klasse');                                      // null ⇒ no-op

    expect($this->vk->fresh()->dish_class_id)->toBeNull();
});

it('Override-First: manuell gepflegte Klasse blockt den Accept typisiert', function () {
    $this->vk->update(['dish_class_source' => 'manual']);

    expect(fn () => $this->svc->acceptKlasse($this->rootTeam, $this->vk->id, $this->class->id, 0.9, null))
        ->toThrow(RuntimeException::class, 'manuell');
});

it('verteileRollen: Echo-Vorschlag validiert gegen Vokabular + Zeilen; Accept schreibt zeilenweise', function () {
    DB::table('foodalchemist_recipe_ingredients')->where('id', $this->zeileId)->update(['role' => 'protein']);  // Kontext-Echo

    $vorschlag = $this->svc->verteileRollen($this->rootTeam, $this->vk->id);
    expect($vorschlag['rollen'])->toBe([$this->zeileId => 'protein']);

    DB::table('foodalchemist_recipe_ingredients')->where('id', $this->zeileId)->update(['role' => null]);
    $n = $this->svc->acceptRollen($this->rootTeam, $this->vk->id, [$this->zeileId => 'sauce', 999999 => 'beilage', $this->zeileId + 1000 => 'quatsch']);

    expect($n)->toBe(1)                                               // fremde/ungültige Zeilen fallen raus
        ->and(DB::table('foodalchemist_recipe_ingredients')->where('id', $this->zeileId)->value('role'))->toBe('sauce');
});

it('Panel-E2E: Klassifizieren → Übernehmen über die Komponente (DoD Fake-Roundtrip + Accept)', function () {
    $this->vk->update(['dish_class_id' => $this->class->id]);   // Kontext fürs Echo
    $panel = Livewire::test(DetailPanel::class, ['recipeId' => $this->vk->id])
        ->call('ai_klassifizieren')
        ->assertSeeHtml('data-klasse-vorschlag');

    $this->vk->update(['dish_class_id' => null]);
    $panel->call('accept_klasse')->assertDispatched('recipe-gespeichert');

    expect($this->vk->fresh()->dish_class_source)->toBe('ki');

    // reject lässt Fachdaten unberührt
    $panel->call('ai_rollen')->call('reject_rollen')->assertSet('rollenVorschlag', null);
});
