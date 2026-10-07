<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel as GpDetailPanel;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Livewire\Recipes\StepEditor;
use Platform\FoodAlchemist\Livewire\Suppliers\ItemModal;
use Platform\FoodAlchemist\Livewire\Verkauf\DetailPanel as VkDetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\BearbeitungssperreService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 65 · Bearbeitungssperre, Rollout Gruppe A: Basisrezept-Editor (+ eingebetteter Schritt-Editor),
 * Grundprodukt-Editor + GP-Detailspalte, Lieferantenartikel-Editor, Gericht-Detailspalte.
 *
 * Je Komponente: ohne „Bearbeiten" Lesemodus und die Schreibaktion wird serverseitig abgewiesen (DB unverändert),
 * mit „Bearbeiten" schreibbar, eine zweite Person wird abgewiesen.
 */
beforeEach(function () {
    config(['foodalchemist.bearbeitungssperre' => true]);
    $this->seedTeamHierarchy();
    $this->anna = $this->makeUser($this->rootTeam, 'Anna');
    $this->ben = $this->makeUser($this->rootTeam, 'Ben');
    $this->svc = app(BearbeitungssperreService::class);
});

// ── Basisrezept-Editor ─────────────────────────────────────────────────────

it('Basisrezept-Editor: ohne „Bearbeiten" Lesemodus, Speichern wird abgewiesen', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Kalbsfond');
    $this->actingAs($this->anna);

    Livewire::test(RecipeModal::class)->call('oeffnen', $r->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->assertDontSeeHtml('data-rezept-speichern')
        ->set('form.name', 'Geändert')->call('speichern');

    expect($r->fresh()->name)->toBe('Kalbsfond');
});

it('Basisrezept-Editor: „Bearbeiten" schreibt, Speichern gibt frei und lässt den Editor offen im Lesemodus', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Kalbsfond');
    $this->actingAs($this->anna);

    $c = Livewire::test(RecipeModal::class)->call('oeffnen', $r->id)
        ->call('bearbeitenStarten')
        ->assertSeeHtml('data-rezept-speichern')
        ->assertSeeHtml('data-fa-lesemodus="0"');
    expect($this->svc->haelt('recipe', $r->id, $this->anna->id))->toBeTrue();

    $c->set('form.name', 'Kalbsfond hell')->call('speichern')
        ->dispatch('zutaten-persistiert', recipeId: $r->id)
        ->assertNotDispatched('modal.close')
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"');

    expect($r->fresh()->name)->toBe('Kalbsfond hell')
        ->and($this->svc->haelt('recipe', $r->id, $this->anna->id))->toBeFalse();
});

it('Basisrezept-Editor: zweite Person sieht „wird bearbeitet" und kann nicht schreiben', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Kalbsfond');
    $this->svc->sperren('recipe', $r->id, $this->anna->id, 'Anna');
    $this->actingAs($this->ben);

    Livewire::test(RecipeModal::class)->call('oeffnen', $r->id)
        ->assertSeeHtml('data-bearbeiten-fremd')->assertSee('Wird von Anna bearbeitet')
        ->call('bearbeitenStarten')
        ->set('form.name', 'Von Ben')->call('speichern');

    expect($r->fresh()->name)->toBe('Kalbsfond')
        ->and($this->svc->haelt('recipe', $r->id, $this->ben->id))->toBeFalse();
});

it('Basisrezept-Editor: Schließen und Abbrechen geben die eigene Sperre frei', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Kalbsfond');
    $this->actingAs($this->anna);

    Livewire::test(RecipeModal::class)->call('oeffnen', $r->id)
        ->call('bearbeitenStarten')
        ->dispatch('modal.closed', name: 'recipe-modal');
    expect($this->svc->haelt('recipe', $r->id, $this->anna->id))->toBeFalse();

    Livewire::test(RecipeModal::class)->call('oeffnen', $r->id)
        ->call('bearbeitenStarten')
        ->set('form.name', 'Verworfen')
        ->call('bearbeitenAbbrechen')
        ->assertSet('form.name', 'Kalbsfond');
    expect($this->svc->haelt('recipe', $r->id, $this->anna->id))->toBeFalse()
        ->and($r->fresh()->name)->toBe('Kalbsfond');
});

// ── Schritt-Editor (eingebettet, hängt an der Rezept-Sperre) ───────────────

it('Schritt-Editor: ohne Sperre weder Schritt anlegen noch Text sofort schreiben (updated-Hook)', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Kalbsfond', ['preparation' => null]);
    $s = FoodAlchemistRecipeStep::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'position' => 1, 'text' => 'Alt']);
    $this->actingAs($this->anna);

    Livewire::test(StepEditor::class, ['recipeId' => $r->id])
        ->call('schrittAnlegen')
        ->set('texte.'.$s->id, 'Neu ohne Sperre');

    expect(FoodAlchemistRecipeStep::where('recipe_id', $r->id)->count())->toBe(1)
        ->and($s->fresh()->text)->toBe('Alt');
});

it('Schritt-Editor: mit der Sperre des Rezepts (Voll-Editor hält sie) wird geschrieben', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Kalbsfond', ['preparation' => null]);
    $s = FoodAlchemistRecipeStep::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'position' => 1, 'text' => 'Alt']);
    $this->svc->sperren('recipe', $r->id, $this->anna->id, 'Anna');
    $this->actingAs($this->anna);

    Livewire::test(StepEditor::class, ['recipeId' => $r->id])
        ->set('texte.'.$s->id, 'Zwiebeln in Brunoise schneiden.')
        ->call('schrittAnlegen');

    expect(FoodAlchemistRecipeStep::where('recipe_id', $r->id)->count())->toBe(2)
        ->and($s->fresh()->text)->toBe('Zwiebeln in Brunoise schneiden.');
});

it('Schritt-Editor: zweite Person wird abgewiesen, solange die erste die Sperre hält', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Kalbsfond', ['preparation' => null]);
    $s = FoodAlchemistRecipeStep::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'position' => 1, 'text' => 'Alt']);
    $this->svc->sperren('recipe', $r->id, $this->anna->id, 'Anna');
    $this->actingAs($this->ben);

    Livewire::test(StepEditor::class, ['recipeId' => $r->id])
        ->call('schrittAnlegen')
        ->set('texte.'.$s->id, 'Von Ben')
        ->call('schrittLoeschen', $s->id);

    expect(FoodAlchemistRecipeStep::where('recipe_id', $r->id)->count())->toBe(1)
        ->and($s->fresh()->text)->toBe('Alt');
});

it('Schritt-Editor: Schalter aus = Verhalten wie vor Spec 65 (sofort schreiben ohne Sperre)', function () {
    config(['foodalchemist.bearbeitungssperre' => false]);
    $r = $this->makeRecipe($this->rootTeam, 'Kalbsfond', ['preparation' => null]);
    $s = FoodAlchemistRecipeStep::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'position' => 1, 'text' => 'Alt']);
    $this->actingAs($this->anna);

    Livewire::test(StepEditor::class, ['recipeId' => $r->id])->set('texte.'.$s->id, 'Frei');

    expect($s->fresh()->text)->toBe('Frei');
});

// ── Grundprodukt-Editor ────────────────────────────────────────────────────

it('GP-Editor: ohne „Bearbeiten" Lesemodus, Speichern wird abgewiesen', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet');
    $this->actingAs($this->anna);

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->assertDontSeeHtml('data-gp-speichern')
        ->set('defaults.piece_default_g', '120')->call('speichern');

    expect($gp->fresh()->piece_default_g)->toBeNull();
});

it('GP-Editor: „Bearbeiten" schreibt, Speichern gibt frei und schließt NICHT', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet');
    $this->actingAs($this->anna);

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)
        ->call('bearbeitenStarten')
        ->assertSeeHtml('data-gp-speichern')
        ->set('defaults.piece_default_g', '120')->call('speichern')
        ->assertSet('fehler', null)
        ->assertNotDispatched('modal.close')
        ->assertSeeHtml('data-bearbeiten-starten');

    expect((float) $gp->fresh()->piece_default_g)->toBe(120.0)
        ->and($this->svc->haelt('gp', $gp->id, $this->anna->id))->toBeFalse();
});

it('GP-Editor: zweite Person wird abgewiesen, Schließen gibt die eigene Sperre frei', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet');
    $this->svc->sperren('gp', $gp->id, $this->anna->id, 'Anna');
    $this->actingAs($this->ben);

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)
        ->assertSeeHtml('data-bearbeiten-fremd')
        ->call('bearbeitenStarten')
        ->set('defaults.piece_default_g', '99')->call('speichern');
    expect($gp->fresh()->piece_default_g)->toBeNull();

    $this->actingAs($this->anna);
    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)
        ->dispatch('modal.closed', name: 'gp-modal');
    expect($this->svc->haelt('gp', $gp->id, $this->anna->id))->toBeFalse();
});

it('GP-Editor: Neuanlage braucht keine Sperre', function () {
    $this->actingAs($this->anna);

    Livewire::test(GpModal::class)->call('oeffnen')
        ->assertDontSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-gp-speichern')
        ->set('builder.hauptzutat', 'Zander')->set('builder.condition', 'TK')->set('builder.form', 'Filet')
        ->call('speichern')->assertSet('fehler', null);

    expect(FoodAlchemistGp::where('name', 'like', 'Zander%')->exists())->toBeTrue();
});

// ── GP-Detailspalte ────────────────────────────────────────────────────────

it('GP-Detailspalte: ohne „Bearbeiten" wird Löschen abgewiesen, mit Sperre gelöscht und frei', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet');
    $this->actingAs($this->anna);

    $c = Livewire::test(GpDetailPanel::class, ['gpId' => $gp->id])
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->call('gpLoeschen');
    expect(FoodAlchemistGp::find($gp->id))->not->toBeNull();

    $c->call('bearbeitenStarten')->assertSeeHtml('data-bearbeiten-fertig')->assertSeeHtml('data-fa-lesemodus="0"')
        ->call('gpLoeschen');
    expect(FoodAlchemistGp::find($gp->id))->toBeNull()
        ->and($this->svc->haelt('gp', $gp->id, $this->anna->id))->toBeFalse();
});

it('GP-Detailspalte: zweite Person abgewiesen, „Fertig" gibt frei', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet');
    $this->actingAs($this->anna);
    Livewire::test(GpDetailPanel::class, ['gpId' => $gp->id])->call('bearbeitenStarten');

    $this->actingAs($this->ben);
    Livewire::test(GpDetailPanel::class, ['gpId' => $gp->id])
        ->assertSeeHtml('data-bearbeiten-fremd')
        ->call('gpLoeschen');
    expect(FoodAlchemistGp::find($gp->id))->not->toBeNull();

    $this->actingAs($this->anna);
    Livewire::test(GpDetailPanel::class, ['gpId' => $gp->id])->call('bearbeitenFertig');
    expect($this->svc->haelt('gp', $gp->id, $this->anna->id))->toBeFalse();
});

it('GP-Detailspalte eingebettet: keine eigene Leiste und kein eigenes fieldset (sperrt der GP-Editor)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet');
    $this->actingAs($this->anna);

    Livewire::test(GpDetailPanel::class, ['gpId' => $gp->id, 'embedded' => true, 'section' => 'las'])
        ->assertDontSeeHtml('data-bearbeiten-starten')
        ->assertDontSeeHtml('data-fa-lesemodus="1"');
});

// ── Lieferantenartikel-Editor ──────────────────────────────────────────────

it('LA-Editor: ohne „Bearbeiten" abgewiesen, mit Sperre gespeichert, zweite Person abgewiesen', function () {
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'Senf mittelscharf 1 kg', 'qty' => 1, 'unit_code' => 'kg',
    ]);
    $this->actingAs($this->anna);

    $c = Livewire::test(ItemModal::class)->call('oeffnen', $la->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->assertDontSeeHtml('data-la-speichern')
        ->set('stammdaten.designation', 'Ohne Sperre')->call('speichern');
    expect($la->fresh()->designation)->toBe('Senf mittelscharf 1 kg');

    $c->call('bearbeitenStarten')->assertSeeHtml('data-la-speichern')
        ->set('stammdaten.designation', 'Senf scharf 1 kg')->call('speichern')
        ->assertSet('fehler', null)
        ->assertNotDispatched('modal.close')
        ->assertSeeHtml('data-bearbeiten-starten');
    expect($la->fresh()->designation)->toBe('Senf scharf 1 kg')
        ->and($this->svc->haelt('supplier_item', $la->id, $this->anna->id))->toBeFalse();

    $this->svc->sperren('supplier_item', $la->id, $this->anna->id, 'Anna');
    $this->actingAs($this->ben);
    Livewire::test(ItemModal::class)->call('oeffnen', $la->id)
        ->assertSeeHtml('data-bearbeiten-fremd')
        ->set('stammdaten.designation', 'Von Ben')->call('speichern')
        ->call('preisLoeschen', 1);
    expect($la->fresh()->designation)->toBe('Senf scharf 1 kg');

    $this->actingAs($this->anna);
    Livewire::test(ItemModal::class)->call('oeffnen', $la->id)->dispatch('modal.closed', name: 'item-modal');
    expect($this->svc->haelt('supplier_item', $la->id, $this->anna->id))->toBeFalse();
});

// ── Gericht-Detailspalte ───────────────────────────────────────────────────

it('Gericht-Detailspalte: Eignung nur mit Sperre, zweite Person abgewiesen, „Fertig" gibt frei', function () {
    $vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk-sperre', 'name' => 'FIN: Wrap',
        'status' => 'draft', 'is_sales_recipe' => true,
    ]);
    $zeilen = fn () => DB::table('foodalchemist_recipe_sector_suitability')->where('recipe_id', $vk->id)->whereNull('deleted_at')->count();
    $this->actingAs($this->anna);

    $c = Livewire::test(VkDetailPanel::class, ['recipeId' => $vk->id])
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->call('eignungSetzen', 'sektor', 'care');
    expect($zeilen())->toBe(0);

    $c->call('bearbeitenStarten')->assertSeeHtml('data-fa-lesemodus="0"')
        ->call('eignungSetzen', 'sektor', 'care');
    expect($zeilen())->toBe(1);

    $this->actingAs($this->ben);
    Livewire::test(VkDetailPanel::class, ['recipeId' => $vk->id])
        ->assertSeeHtml('data-bearbeiten-fremd')
        ->call('eignungEntfernen', 'sektor', 'care');
    expect($zeilen())->toBe(1);

    $this->actingAs($this->anna);
    $c->call('bearbeitenFertig')->assertSeeHtml('data-bearbeiten-starten');
    expect($this->svc->haelt('recipe', $vk->id, $this->anna->id))->toBeFalse();
});
