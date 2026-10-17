<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 · Foto an einem fremden Rezept. Ein Kaskaden-Schritt kann über „Bestand zuerst“
 * (RecipeDependencyWorkflowService::uebernimmBestandFuerKind, Status skipped, deferred.reuse.eigen = false) an ein nur
 * SICHTBARES Rezept gebunden sein — Oberteam-Rezept oder Abo-Fassung des Masters. Die Oberfläche zeigt den Foto-Knopf
 * dort nicht (nur bei „freigegeben“), die Livewire-Methode ist aber direkt aufrufbar. Riegel jetzt im Service.
 */
const SPEC84_FOTO_MELDUNG = 'Dieses Rezept gehört einem anderen Team (übernommener Bestand) – Fotos bitte am eigenen Rezept anlegen.';

beforeEach(function () {
    $this->seedTeamHierarchy();
    Storage::fake('public');
    $this->fremd = $this->makeRecipe($this->rootTeam, 'Oberteam-Fond');
    $this->hero = FoodAlchemistRecipeStepPhoto::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->fremd->id,
        'pfad' => 'foodalchemist/x/hero.jpg', 'caption' => 'Hero', 'is_result' => true]);
    $this->kind = $this->makeUser($this->childA, 'Kind Koch');
    $this->actingAs($this->kind);
    $this->svc = app(RecipeImageService::class);
});

it('Spec 84 · Upload an fremdes Rezept: abgewiesen mit lesbarer Meldung, kein Foto, Titelbild bleibt, keine Datei', function () {
    expect(fn () => $this->svc->uebernimmManuellesFoto($this->childA, $this->fremd, UploadedFile::fake()->image('x.jpg'), null, true))
        ->toThrow(GeerbtNurLesenException::class, SPEC84_FOTO_MELDUNG);

    expect(FoodAlchemistRecipeStepPhoto::where('recipe_id', $this->fremd->id)->count())->toBe(1)
        ->and((bool) $this->hero->fresh()->is_result)->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('Spec 84 · Wiederverwendung eines eigenen Fotos auf ein fremdes Rezept: ebenfalls abgewiesen', function () {
    $eigenesRezept = $this->makeRecipe($this->childA, 'Eigener Fond');
    $quelle = $this->svc->uebernimmManuellesFoto($this->childA, $eigenesRezept, UploadedFile::fake()->image('q.jpg'));

    expect(fn () => $this->svc->uebernimmVorhandenesFoto($this->childA, $this->fremd, $quelle, null, true))
        ->toThrow(GeerbtNurLesenException::class, SPEC84_FOTO_MELDUNG)
        ->and((bool) $this->hero->fresh()->is_result)->toBeTrue()
        ->and(FoodAlchemistRecipeStepPhoto::where('recipe_id', $this->fremd->id)->count())->toBe(1);
});

it('Spec 84 · eigenes Rezept: Foto wird angelegt und als Titelbild gesetzt (Erfolgsweg)', function () {
    $eigen = $this->makeRecipe($this->childA, 'Eigenes Gericht', ['is_sales_recipe' => true]);
    $foto = $this->svc->uebernimmManuellesFoto($this->childA, $eigen, UploadedFile::fake()->image('e.jpg'), null, true);

    expect((int) $foto->recipe_id)->toBe($eigen->id)
        ->and((bool) $foto->is_result)->toBeTrue()
        ->and((bool) $this->hero->fresh()->is_result)->toBeTrue();   // fremdes Titelbild unberührt
});

it('Spec 84 · Planung: Foto über einen übernommenen (skipped) Schritt mit fremdem Rezept → Meldung im Modal, kein 500', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->childA->id, 'scope' => 'rezept', 'creative_mode' => 'voll_kreativ',
        'brief' => 'Test', 'status' => 'done', 'staged' => false, 'created_via' => 'import']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->childA->id, 'cascade_run_id' => $run->id, 'parent_step_id' => null,
        'kind' => 'rezept', 'ref_type' => 'recipe', 'ref_id' => $this->fremd->id, 'label' => 'Oberteam-Fond', 'status' => 'skipped', 'sort' => 0,
        'deferred' => ['reuse' => ['reif' => true, 'eigen' => false, 'luecken' => []]]]);

    Livewire::test(\Platform\FoodAlchemist\Livewire\Planung\Index::class)
        ->set("fotoUploads.{$step->id}", UploadedFile::fake()->image('p.jpg'))
        ->call('fotoHochladen', $step->id, true)
        ->assertSet('fehler', SPEC84_FOTO_MELDUNG);

    expect((bool) $this->hero->fresh()->is_result)->toBeTrue()
        ->and(FoodAlchemistRecipeStepPhoto::where('recipe_id', $this->fremd->id)->count())->toBe(1);
});
