<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Taxonomie;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup;
use Platform\FoodAlchemist\Services\VocabularyService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M1-04: Rezept-Taxonomie — CRUD + Sortierung; die M4-Browser-Bäume lesen
 * exakt diese Service-Methoden (DoD).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->vocab = app(VocabularyService::class);

    $this->hg = FoodAlchemistRecipeMainGroup::create([
        'team_id' => $this->rootTeam->id, 'code' => 'fonds_reduktionen',
        'label' => 'Fonds & Reduktionen', 'bereich' => 'KUECHE_HERZHAFT', 'sort_order' => 1,
    ]);
    $this->kat = $this->vocab->createRecipeCategory($this->rootTeam, $this->hg->id, ['label' => 'Heller Fond', 'sort_order' => 1]);
});

it('liefert HG-Baum mit Kategorie-Zählern je Team-Kette (M4-Lese-Vertrag)', function () {
    $baum = $this->vocab->listMainGroups($this->childA); // Kind sieht geerbten Baum

    expect($baum)->toHaveCount(1)
        ->and($baum->first()->kategorie_count)->toBe(1)
        ->and($this->vocab->listRecipeCategories($this->childA, $this->hg->id)->pluck('label'))->toContain('Heller Fond');
});

it('CRUD: anlegen mit Slug-Code, ändern + sortieren nur als Besitzer', function () {
    expect($this->kat->code)->toBe('heller_fond');

    $this->vocab->updateRecipeCategory($this->rootTeam, $this->kat->id, ['label' => 'Heller Fond (Geflügel)', 'sort_order' => 5]);
    expect($this->kat->fresh()->label)->toBe('Heller Fond (Geflügel)')
        ->and($this->kat->fresh()->sort_order)->toBe(5);

    expect(fn () => $this->vocab->updateRecipeCategory($this->childA, $this->kat->id, ['label' => 'Gekapert']))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');

    $this->vocab->updateMainGroupSort($this->rootTeam, $this->hg->id, 7);
    expect($this->hg->fresh()->sort_order)->toBe(7);
});

it('löscht Kategorien ohne Rezepte; recipe_count ist 0 bis M4-01', function () {
    expect($this->kat->recipe_count ?? null)->toBeNull(); // Attribut kommt aus list*

    $liste = $this->vocab->listRecipeCategories($this->rootTeam, $this->hg->id);
    expect($liste->first()->recipe_count)->toBe(0);

    $this->vocab->deleteRecipeCategory($this->rootTeam, $this->kat->id);
    expect(FoodAlchemistRecipeCategory::find($this->kat->id))->toBeNull();
});

it('Kind-Team legt eigene Kategorie im geerbten Baum an — Geschwister sehen sie nicht', function () {
    $eigene = $this->vocab->createRecipeCategory($this->childA, $this->hg->id, ['label' => 'Hausfond A']);

    expect($this->vocab->listRecipeCategories($this->childA, $this->hg->id)->pluck('label'))->toContain('Hausfond A')
        ->and($this->vocab->listRecipeCategories($this->childB, $this->hg->id)->pluck('label'))->not->toContain('Hausfond A')
        ->and($eigene->team_id)->toBe($this->childA->id);
});

it('legt eine neue Hauptgruppe an — Bug-Fix 2026-06-14 (vorher gar nicht möglich)', function () {
    $hg = $this->vocab->createMainGroup($this->rootTeam, ['label' => 'Saucen & Dips']);

    expect($hg->code)->toBe('saucen_dips')
        ->and($hg->team_id)->toBe($this->rootTeam->id)
        ->and($this->vocab->listMainGroups($this->rootTeam)->pluck('label'))->toContain('Saucen & Dips');

    // Slug-Kollision wird suffixt (unique[team_id, code])
    expect($this->vocab->createMainGroup($this->rootTeam, ['label' => 'Saucen Dips'])->code)->toBe('saucen_dips_2');
});

it('Livewire: „Neue Hauptgruppe" legt an und wählt sie direkt aus', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(Taxonomie::class)
        ->set('neueHauptgruppe', 'Frühstück')
        ->call('hgNeu')
        ->assertSet('neueHauptgruppe', '')
        ->assertSet('hauptgruppeId', fn ($id) => $id !== null);

    expect(FoodAlchemistRecipeMainGroup::where('label', 'Frühstück')->where('team_id', $this->rootTeam->id)->exists())->toBeTrue();
});
