<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M4-06: Rezept-CRUD — recipe_key §1.7/§1.8, duplicate inkl. Zutaten,
 * delete-Block bei Eltern-Refs, Modal-Roundtrip mit Recompute-Trigger (A-3).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(RecipeService::class);
    $this->hg = FoodAlchemistRecipeMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'SOR', 'label' => 'Sorbets']);
    $this->kat = FoodAlchemistRecipeCategory::create(['team_id' => $this->rootTeam->id, 'main_group_id' => $this->hg->id, 'code' => 'FRU', 'label' => 'Frucht']);
});

it('§1.7: recipe_key = slug(name) mit ae/oe/ue/ss; §1.8: Kategorie-Diskriminator + _2-Suffix', function () {
    expect($this->svc->rezeptKey('Sorbet: Birne'))->toBe('sorbet_birne')
        ->and($this->svc->rezeptKey('Schaumsauce: Beurre Blanc'))->toBe('schaumsauce_beurre_blanc')
        ->and($this->svc->rezeptKey('Püree: Süßkartoffel'))->toBe('pueree_suesskartoffel');

    $erstes = $this->svc->create($this->rootTeam, ['name' => 'Sorbet: Birne']);
    expect($erstes->recipe_key)->toBe('sorbet_birne')
        ->and($erstes->status->value)->toBe('draft');

    // Kollision + Kategorie ⇒ Diskriminator
    $zweites = $this->svc->create($this->rootTeam, ['name' => 'Sorbet: Birne', 'category_id' => $this->kat->id]);
    expect($zweites->recipe_key)->toBe('sorbet_birne_frucht');

    // identisches Duplikat ohne Kategorie ⇒ _2
    $drittes = $this->svc->create($this->rootTeam, ['name' => 'Sorbet: Birne']);
    expect($drittes->recipe_key)->toBe('sorbet_birne_2');
});

it('duplicate kopiert Zutaten und rechnet die Kopie durch; delete blockt bei Eltern-Referenz', function () {
    $g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $original = $this->svc->create($this->rootTeam, ['name' => 'Fond: Gemüse']);
    FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $original->id, 'position' => 1,
        'raw_text' => '500 g Karotten', 'quantity' => 500, 'unit_vocab_id' => $g->id, 'match_method' => 'manual',
        'gp_id' => $this->makeGp($this->rootTeam, 'Karotte')->id,
    ]);

    $kopie = $this->svc->duplicate($this->rootTeam, $original->id, 'Fond: Gemüse hell');
    expect($kopie->ingredients()->count())->toBe(1)
        ->and((float) $kopie->fresh()->yield_kg)->toBe(0.5)              // Pipeline lief
        ->and($kopie->status->value)->toBe('draft');

    // Eltern-Referenz blockt delete
    $eltern = $this->svc->create($this->rootTeam, ['name' => 'Suppe: Klar']);
    FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $eltern->id, 'position' => 1,
        'raw_text' => 'Fond', 'quantity' => 1000, 'unit_vocab_id' => $g->id,
        'referenced_recipe_id' => $original->id, 'match_method' => 'recipe_ref',
    ]);
    expect(fn () => $this->svc->delete($this->rootTeam, $original->id))
        ->toThrow(RuntimeException::class, 'Suppe: Klar');

    $this->svc->delete($this->rootTeam, $kopie->id);                     // ohne Refs geht es
    expect(FoodAlchemistRecipe::find($kopie->id))->toBeNull();
});

it('Modal-Roundtrip: Anlage validiert, Edit mit yield_kg_manual triggert Recompute (DoD M4-06)', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen')
        ->set('form.name', 'Sorbet: Birne')
        ->set('form.hauptgruppe_id', $this->hg->id)
        ->set('form.category_id', $this->kat->id)
        ->assertSeeHtml('sorbet_birne')                                   // Key-Vorschau
        ->call('speichern')
        ->assertSet('fehler', null)
        ->assertDispatched('recipe-gespeichert');

    $r = FoodAlchemistRecipe::where('recipe_key', 'sorbet_birne')->firstOrFail();
    FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'position' => 1,
        'raw_text' => '1 kg Birne', 'quantity' => 1000, 'unit_vocab_id' => $g->id, 'match_method' => 'manual',
        'gp_id' => $this->makeGp($this->rootTeam, 'Birne')->id,
    ]);
    app(\Platform\FoodAlchemist\Services\RecipeRecomputeService::class)->recomputePipeline($r->id);

    // Edit: yield manuell halbieren ⇒ Recompute-Trigger aktualisiert die Aggregate (A-3)
    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $r->id)
        ->assertSet('form.name', 'Sorbet: Birne')
        ->set('form.yield_kg_manual', '0,5')
        ->call('speichern')
        ->assertSet('fehler', null);

    $r->refresh();
    expect((float) $r->yield_kg_manual)->toBe(0.5)
        ->and((float) $r->yield_kg)->toBe(1.0)                            // Auto bleibt
        ->and($r->version)->toBe(2);

    // Leerer Name ⇒ Fehler, kein Insert
    Livewire::test(RecipeModal::class)
        ->call('oeffnen')
        ->set('form.name', '  ')
        ->call('speichern')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Pflicht'));
});

it('Yield-Guard: Tippfehler/0 im manuellen Yield wird abgewiesen statt still als 0 kg gespeichert', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $r = $this->svc->create($this->rootTeam, ['name' => 'Fond: Yield-Guard']);

    // nicht-numerisch ⇒ Fehler, yield_kg_manual bleibt null (kein stilles 0,0 → ek_per_kg-Vergiftung)
    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $r->id)
        ->set('form.yield_kg_manual', 'abc')
        ->call('speichern')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Yield braucht eine Zahl'));
    expect($r->fresh()->yield_kg_manual)->toBeNull();

    // 0 ⇒ ebenfalls Fehler (0 kg Yield ist nie gültig)
    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $r->id)
        ->set('form.yield_kg_manual', '0')
        ->call('speichern')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Yield braucht eine Zahl'));
    expect($r->fresh()->yield_kg_manual)->toBeNull();

    // gültige Komma-Eingabe wird weiterhin gespeichert
    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $r->id)
        ->set('form.yield_kg_manual', '1,25')
        ->call('speichern')
        ->assertSet('fehler', null);
    expect((float) $r->fresh()->yield_kg_manual)->toBe(1.25);
});

it('UI-Audit: update pflegt die §4.2-Editor-Felder (Status/Zubereitung/Eigenschaften/Notizen/Equipment)', function () {
    // KEIN zweiter seedTeamHierarchy — beforeEach seedet schon; ein Doppel-Seed
    // vergiftet den statischen Ancestry-Cache für nachfolgende Tests (Team-Id-Kollision)
    $svc = app(\Platform\FoodAlchemist\Services\RecipeService::class);
    $r = $svc->create($this->rootTeam, ['name' => 'Fond: Audit']);
    $geraet = \Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment::create(['team_id' => $this->rootTeam->id, 'slug' => 'kombi', 'name' => 'Kombidämpfer']);

    $nach = $svc->update($this->rootTeam, $r->id, [
        'name' => 'Fond: Audit',
        'status' => 'review',
        'preparation' => "1. Ansetzen\n2. Reduzieren",
        'temperature' => 'warm',
        'function' => 'Saucenbasis',
        'notes_manual' => 'Insel-Notiz',
        'equipment_ids' => [$geraet->id],
    ]);

    expect($nach->status->value)->toBe('review')
        ->and($nach->preparation)->toContain('Reduzieren')
        ->and($nach->temperature)->toBe('warm')
        ->and($nach->function)->toBe('Saucenbasis')
        ->and($nach->notes_manual)->toBe('Insel-Notiz')
        ->and($nach->equipment()->pluck('slug')->all())->toBe(['kombi']);

    // ungültiger Status fällt still auf den Bestand zurück (Whitelist)
    expect($svc->update($this->rootTeam, $r->id, ['name' => 'Fond: Audit', 'status' => 'quatsch'])->status->value)->toBe('review');
});

it('Ertrag in Stück (kg↔Stück): persistiert und leert sauber', function () {
    $r = $this->svc->create($this->rootTeam, ['name' => 'Törtchen-Teig', 'is_sales_recipe' => false]);

    $this->svc->update($this->rootTeam, $r->id, ['yield_pieces' => 50]);
    expect((float) $r->fresh()->yield_pieces)->toBe(50.0);

    // Leer-String → null (UI-Pfad)
    $this->svc->update($this->rootTeam, $r->id, ['yield_pieces' => '']);
    expect($r->fresh()->yield_pieces)->toBeNull();
});
