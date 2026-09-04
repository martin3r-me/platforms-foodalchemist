<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 51 Etappe C — der Schreibpfad war auf Gerichte verriegelt, obwohl Schema und Lesepfad
 * immer jedes Rezept konnten. Hier wird der Default gepflegt, den jedes Gericht erbt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'b1',
        'name' => 'Ragout: Rind', 'status' => 'approved', 'is_sales_recipe' => false,
    ]);

    $this->gn = DB::table('foodalchemist_vocab_containers')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id,
        'slug' => 'gn_11_65mm', 'name' => 'GN 1/1 65mm', 'sort_order' => 1,
        'laenge_mm' => 530, 'breite_mm' => 325, 'tiefe_mm' => 65, 'volumen_l' => 8.8,
        'nutzfaktor' => 0.85, 'max_fuellgewicht_kg' => 15,
        'eignung' => json_encode(['abfuellen', 'regenerieren', 'ausgabe']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('das Basisrezept trägt jetzt seine eigene Regenerationszeile', function () {
    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->basis->id)
        ->call('tabLaden', 'regeneration')
        ->set('regenForm.temp_c', '160')
        ->set('regenForm.duration_min', '12')
        ->call('regenerationSpeichern');

    $zeile = DB::table('foodalchemist_recipe_regenerations')->where('recipe_id', $this->basis->id)->first();

    expect($zeile)->not->toBeNull()
        ->and((int) $zeile->temp_c)->toBe(160)
        ->and($zeile->ingredient_id)->toBeNull()          // »das bin ich«, kein Komponenten-Override
        ->and($zeile->device_vocab_id)->toBeNull();       // kalt bzw. Gerät offen
});

it('alles leer heisst »keine Angabe« und löscht die Zeile — nicht »kalt servieren«', function () {
    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->basis->id)
        ->call('tabLaden', 'regeneration')
        ->set('regenForm.temp_c', '160')
        ->call('regenerationSpeichern');

    expect(DB::table('foodalchemist_recipe_regenerations')
        ->where('recipe_id', $this->basis->id)->whereNull('deleted_at')->count())->toBe(1);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->basis->id)
        ->call('tabLaden', 'regeneration')
        ->set('regenForm.temp_c', '')
        ->call('regenerationSpeichern');

    // Eine Zeile mit leerem Gerät WÄRE eine Entscheidung (kalt). Keine Zeile ist eine Lücke.
    expect(DB::table('foodalchemist_recipe_regenerations')
        ->where('recipe_id', $this->basis->id)->whereNull('deleted_at')->count())->toBe(0);
});

it('Behälter je Zweck landen am Basisrezept, samt Referenzmenge', function () {
    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->basis->id)
        ->call('tabLaden', 'regeneration')
        ->set('behaelterForm.abfuellen.container_vocab_id', (string) $this->gn)
        ->set('behaelterForm.abfuellen.referenz_menge_kg', '8')
        ->set('behaelterForm.abfuellen.skalierung', 'hoehe_gebunden')
        ->set('dichteklasse', 'dicht')
        ->call('regenerationSpeichern');

    $z = DB::table('foodalchemist_recipe_containers')
        ->where('recipe_id', $this->basis->id)->where('zweck', 'abfuellen')->first();

    expect((int) $z->container_vocab_id)->toBe($this->gn)
        ->and((float) $z->referenz_menge_kg)->toBe(8.0)
        ->and($z->skalierung)->toBe('hoehe_gebunden')
        ->and(FoodAlchemistRecipe::find($this->basis->id)->dichteklasse)->toBe('dicht');
});

it('»= wie Abfüllen« macht aus zwei Zeilen einen durchgängigen Behälter', function () {
    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->basis->id)
        ->call('tabLaden', 'regeneration')
        ->set('behaelterForm.abfuellen.container_vocab_id', (string) $this->gn)
        ->set('behaelterForm.abfuellen.referenz_menge_kg', '8')
        ->call('behaelterUebernehmen', 'abfuellen', 'regenerieren')
        ->assertSet('behaelterForm.regenerieren.container_vocab_id', (string) $this->gn)
        ->call('regenerationSpeichern');

    $zwecke = DB::table('foodalchemist_recipe_containers')
        ->where('recipe_id', $this->basis->id)->whereNull('deleted_at')->pluck('container_vocab_id', 'zweck');

    expect((int) $zwecke['abfuellen'])->toBe($this->gn)
        ->and((int) $zwecke['regenerieren'])->toBe($this->gn);
});

it('ein Zweck kommt genau einmal vor — zweimal speichern legt nichts doppelt an', function () {
    foreach (['8', '9'] as $menge) {
        Livewire::test(RecipeModal::class)
            ->call('oeffnen', $this->basis->id)
            ->call('tabLaden', 'regeneration')
            ->set('behaelterForm.abfuellen.container_vocab_id', (string) $this->gn)
            ->set('behaelterForm.abfuellen.referenz_menge_kg', $menge)
            ->call('regenerationSpeichern');
    }

    $zeilen = DB::table('foodalchemist_recipe_containers')
        ->where('recipe_id', $this->basis->id)->where('zweck', 'abfuellen')->whereNull('deleted_at')->get();

    expect($zeilen)->toHaveCount(1)->and((float) $zeilen[0]->referenz_menge_kg)->toBe(9.0);
});

it('das MCP-Tool nimmt jetzt auch ein Basisrezept an', function () {
    $team = $this->rootTeam;
    $tool = app(\Platform\FoodAlchemist\Tools\RecipeRegenerationPutTool::class);

    $ergebnis = $tool->execute(
        ['recipe_id' => $this->basis->id, 'felder' => ['component_label' => 'Ragout: Rind', 'temp_c' => 155]],
        new \Platform\Core\Contracts\ToolContext(user: \Illuminate\Support\Facades\Auth::user(), team: $team)
    );

    expect($ergebnis->success)->toBeTrue()
        ->and((int) DB::table('foodalchemist_recipe_regenerations')
            ->where('recipe_id', $this->basis->id)->value('temp_c'))->toBe(155);
});
