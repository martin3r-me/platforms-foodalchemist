<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\StepEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 27 Phase 2 — Schritt-Editor. Kern-Versprechen der Spec: das Foto klebt am
 * SCHRITT, nicht an einer Nummer (reorder-fest), ein Foto darf an mehreren Schritten
 * hängen, und der `preparation`-Spiegel ist nach jeder Aktion aktuell.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->rezept = $this->makeRecipe($this->rootTeam, 'Editor-Rezept', ['preparation' => null]);

    $this->foto = fn (string $datei) => FoodAlchemistRecipeStepPhoto::create([
        'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->rezept->id,
        'pfad' => 'foodalchemist/rezepte/' . $this->rezept->id . '/' . $datei,
    ]);

    $this->editor = fn () => Livewire::test(StepEditor::class, ['recipeId' => $this->rezept->id]);
});

it('legt Schritte an und nummeriert fortlaufend, Abschnitt wird übernommen', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root A'));

    $lw = ($this->editor)()->call('schrittAnlegen');
    $erster = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->firstOrFail();
    $lw->set('phasen.' . $erster->id, 'Mise en Place')->call('schrittAnlegen');

    $steps = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->get();
    expect($steps->pluck('position')->all())->toBe([1, 2])
        ->and($steps->pluck('phase')->all())->toBe(['Mise en Place', 'Mise en Place']);   // erbt den laufenden Abschnitt
});

it('Text tippen persistiert und zieht den preparation-Spiegel nach', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root B'));

    $lw = ($this->editor)()->call('schrittAnlegen');
    $s = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->firstOrFail();

    $lw->set('texte.' . $s->id, 'Zwiebeln in Brunoise schneiden.');

    expect($s->fresh()->text)->toBe('Zwiebeln in Brunoise schneiden.')
        ->and($this->rezept->fresh()->preparation)->toBe('1. Zwiebeln in Brunoise schneiden.');
});

it('▲▼ und Drag-Drop sortieren um — die Fotos wandern mit dem Schritt', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root C'));

    app(\Platform\FoodAlchemist\Services\RecipeStepService::class)->sync($this->rezept, [
        ['text' => 'eins'], ['text' => 'zwei'], ['text' => 'drei'],
    ]);
    $steps = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->get();
    $f = ($this->foto)('an-drei.jpg');
    $steps[2]->photos()->attach($f->id, ['position' => 1]);

    // „drei" per ▲ nach oben
    ($this->editor)()->call('hoch', $steps[2]->id);
    $nach = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->get();
    expect($nach->pluck('text')->all())->toBe(['eins', 'drei', 'zwei'])
        ->and($nach[1]->id)->toBe($steps[2]->id)
        ->and($nach[1]->photos->pluck('id')->all())->toBe([$f->id]);   // ⬅ Foto klebt am Schritt

    // „eins" per Drag hinter „zwei" (letzte Zeile)
    ($this->editor)()->call('verschieben', $steps[0]->id, $steps[1]->id);
    expect(FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->pluck('text')->all())
        ->toBe(['drei', 'zwei', 'eins']);
});

it('verknüpft ein Foto per Klick an mehrere Schritte und löst es wieder (M:N)', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root D'));

    app(\Platform\FoodAlchemist\Services\RecipeStepService::class)->sync($this->rezept, [
        ['text' => 'eins'], ['text' => 'zwei'],
    ]);
    $steps = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->get();
    $f = ($this->foto)('mep.jpg');

    $lw = ($this->editor)()
        ->call('fotoUmschalten', $steps[0]->id, $f->id)
        ->call('fotoUmschalten', $steps[1]->id, $f->id);

    expect($f->fresh()->steps->pluck('id')->all())->toBe([$steps[0]->id, $steps[1]->id]);

    // zweiter Klick auf denselben Schritt = lösen; das Foto bleibt im Pool
    $lw->call('fotoUmschalten', $steps[0]->id, $f->id);
    expect($f->fresh()->steps->pluck('id')->all())->toBe([$steps[1]->id])
        ->and(FoodAlchemistRecipeStepPhoto::whereKey($f->id)->exists())->toBeTrue();
});

it('Upload mit aktivem Schritt legt das Foto in den Pool UND verlinkt es', function () {
    Storage::fake('public');
    $this->actingAs($this->makeUser($this->rootTeam, 'Root E'));

    app(\Platform\FoodAlchemist\Services\RecipeStepService::class)->sync($this->rezept, [['text' => 'eins']]);
    $s = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->firstOrFail();

    ($this->editor)()
        ->call('poolOeffnen', $s->id)
        ->set('fotoUpload', UploadedFile::fake()->image('schritt.jpg'))
        ->set('fotoCaption', 'Brunoise')
        ->call('fotoHochladen')
        ->assertSet('fehler', null);

    $foto = FoodAlchemistRecipeStepPhoto::where('recipe_id', $this->rezept->id)->firstOrFail();
    expect($foto->caption)->toBe('Brunoise')
        ->and($s->fresh()->photos->pluck('id')->all())->toBe([$foto->id]);
    Storage::disk('public')->assertExists($foto->pfad);
});

it('Schritt löschen nummeriert neu und lässt das Foto im Pool', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root F'));

    app(\Platform\FoodAlchemist\Services\RecipeStepService::class)->sync($this->rezept, [
        ['text' => 'eins'], ['text' => 'zwei'], ['text' => 'drei'],
    ]);
    $steps = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->get();
    $f = ($this->foto)('an-zwei.jpg');
    $steps[1]->photos()->attach($f->id, ['position' => 1]);

    ($this->editor)()->call('schrittLoeschen', $steps[1]->id);

    expect(FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->pluck('position')->all())->toBe([1, 2])
        ->and($this->rezept->fresh()->preparation)->toBe("1. eins\n2. drei")
        ->and(FoodAlchemistRecipeStepPhoto::whereKey($f->id)->exists())->toBeTrue()
        ->and($f->fresh()->steps)->toHaveCount(0);
});

it('Foto endgültig löschen entfernt es aus allen Schritten', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root G'));

    app(\Platform\FoodAlchemist\Services\RecipeStepService::class)->sync($this->rezept, [['text' => 'eins']]);
    $s = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->firstOrFail();
    $f = ($this->foto)('weg.jpg');
    $s->photos()->attach($f->id, ['position' => 1]);

    ($this->editor)()->call('fotoLoeschen', $f->id);

    expect(FoodAlchemistRecipeStepPhoto::whereKey($f->id)->exists())->toBeFalse()
        ->and($s->fresh()->photos)->toHaveCount(0);
});

it('Markdown-Import parst in Schritte und ersetzt den Bestand', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root H'));

    ($this->editor)()
        ->set('markdownImport', "## Garen\n1. Anbraten.\n2. Ablöschen.")
        ->call('markdownUebernehmen')
        ->assertSet('fehler', null);

    expect(FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->pluck('text')->all())
        ->toBe(['Anbraten.', 'Ablöschen.'])
        ->and($this->rezept->fresh()->preparation)->toBe("## Garen\n1. Anbraten.\n2. Ablöschen.");
});

it('Markdown-Import ohne erkennbaren Schritt meldet den Fehler statt still zu schlucken', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root I'));

    ($this->editor)()->set('markdownImport', '   ')->call('markdownUebernehmen')
        ->assertSet('fehler', 'Im eingefügten Text war kein Schritt erkennbar.');

    expect(FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->count())->toBe(0);
});

it('D1: ein geerbtes Rezept ist lesbar, aber nicht schreibbar', function () {
    // Nutzer im Kind-Team, Rezept gehört dem Root-Team → sichtbar (Kette aufwärts), nicht editierbar.
    $this->actingAs($this->makeUser($this->childA, 'Kind A'));

    ($this->editor)()
        ->call('schrittAnlegen')
        ->assertSet('fehler', 'Geerbtes Rezept — Zubereitung nur durchs Besitzer-Team (D1).');

    expect(FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->count())->toBe(0);
});
