<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #509: Basisrezept-„Anlegen" war eine verlustbehaftete Hülle — create() verwarf
 * still temperature/function/preparation/notes_manual/yield_pieces + Equipment
 * (update() = Voll-Writer), und das Modal schloss nach dem Anlegen, statt in den
 * Edit-Modus zu springen. Diese Tests sichern die Feld-Parität + den nahtlosen
 * Anlegen→Edit-Übergang (VkModal::anlegen-Muster).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(RecipeService::class);
});

it('create() ist feld-parität mit update() — die §4.2-Fachfelder gehen NICHT mehr verloren', function () {
    $geraet = FoodAlchemistVocabKochequipment::create(['team_id' => $this->rootTeam->id, 'slug' => 'kombi', 'name' => 'Kombidämpfer']);

    $r = $this->svc->create($this->rootTeam, [
        'name' => 'Fond: Anlage-Parität',
        'temperature' => 'warm',
        'function' => 'Saucenbasis',
        'preparation' => "1. Ansetzen\n2. Reduzieren",
        'notes_manual' => 'Insel-Notiz',
        'yield_pieces' => 24,
        'equipment_ids' => [$geraet->id],
    ]);

    expect($r->temperature)->toBe('warm')
        ->and($r->function)->toBe('Saucenbasis')
        ->and($r->preparation)->toContain('Reduzieren')
        ->and($r->notes_manual)->toBe('Insel-Notiz')
        ->and((float) $r->yield_pieces)->toBe(24.0)
        ->and($r->equipment()->pluck('slug')->all())->toBe(['kombi'])
        ->and($r->status->value)->toBe('draft');
});

it('RecipeModal: Anlegen springt in den Edit-Modus (Modal bleibt offen, recipeId gesetzt)', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    $lw = Livewire::test(RecipeModal::class)
        ->call('oeffnen')                                            // Anlage-Modus (recipeId null)
        ->assertSet('recipeId', null)
        ->set('form.name', 'Fond: Edit-Sprung')
        ->set('form.preparation', 'getippte Zubereitung')
        ->set('form.temperature', 'warm')
        ->set('form.notes_manual', 'getippte Notiz')
        ->call('speichern')
        ->assertSet('fehler', null)
        ->assertDispatched('recipe-gespeichert')
        ->assertDispatched('recipe-selected');

    // Edit-Sprung: recipeId ist jetzt gesetzt, Modal NICHT geschlossen (istOffen), Form neu geladen
    $lw->assertSet('recipeId', fn ($id) => $id !== null)
        ->assertSet('istOffen', true)
        ->assertSet('form.name', 'Fond: Edit-Sprung');

    // Getipptes ist wirklich in der DB gelandet (kein stiller Verlust)
    $r = FoodAlchemistRecipe::where('recipe_key', 'fond_edit_sprung')->firstOrFail();
    expect($r->preparation)->toBe('getippte Zubereitung')
        ->and($r->temperature)->toBe('warm')
        ->and($r->notes_manual)->toBe('getippte Notiz');
});
