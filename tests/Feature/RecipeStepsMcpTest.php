<?php

use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\RecipeStepService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\RecipeStepsGetTool;
use Platform\FoodAlchemist\Tools\RecipeStepsPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 27 Phase 3 — MCP im Lockstep. Reads gehen über visibleToTeam, Writes nur
 * aufs Besitzer-Team (D1) UND nur in der Draft-Quarantäne (stub/draft) wie
 * recipes.PUT. Zusätzlich: Markdown-Schreibwege (Generator/MCP/Import) erzeugen
 * über RecipeService weiterhin echte Schritte.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->rezept = $this->makeRecipe($this->rootTeam, 'MCP-Rezept', ['status' => 'draft', 'preparation' => null]);

    // Pro Team EIN Kontext (gecacht) — makeUser würde sonst beim zweiten Aufruf
    // fürs selbe Team auf users.email kollidieren.
    $this->kontexte = [];
    $this->ctx = function ($team) {
        return $this->kontexte[$team->id] ??= new ToolContext($this->makeUser($team, 'MCP ' . $team->id), $team);
    };
});

it('recipe_steps.PUT setzt die Schritte, die Reihenfolge im Array ist die Nummerierung', function () {
    $r = (new RecipeStepsPutTool)->execute([
        'recipe_id' => $this->rezept->id,
        'steps' => [
            ['phase' => 'Mise en Place', 'text' => 'Zwiebeln schneiden.'],
            ['phase' => 'Garen', 'text' => 'Bei 160 °C 40 Minuten schmoren.'],
        ],
    ], ($this->ctx)($this->rootTeam));

    expect($r->success)->toBeTrue();
    expect(FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->pluck('position')->all())->toBe([1, 2])
        ->and($this->rezept->fresh()->preparation)
        ->toBe("## Mise en Place\n1. Zwiebeln schneiden.\n\n## Garen\n2. Bei 160 °C 40 Minuten schmoren.");
});

it('recipe_steps.PUT behält Fotos an Schritten mit id und löscht weggelassene Schritte', function () {
    app(RecipeStepService::class)->sync($this->rezept, [['text' => 'eins'], ['text' => 'zwei']]);
    $steps = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->get();
    $foto = FoodAlchemistRecipeStepPhoto::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->rezept->id, 'pfad' => 'x/y.jpg',
    ]);
    $steps[1]->photos()->attach($foto->id, ['position' => 1]);

    (new RecipeStepsPutTool)->execute([
        'recipe_id' => $this->rezept->id,
        'steps' => [['id' => $steps[1]->id, 'text' => 'zwei, umformuliert']],
    ], ($this->ctx)($this->rootTeam));

    $uebrig = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->get();
    expect($uebrig)->toHaveCount(1)
        ->and($uebrig[0]->id)->toBe($steps[1]->id)
        ->and($uebrig[0]->text)->toBe('zwei, umformuliert')
        ->and($uebrig[0]->photos->pluck('id')->all())->toBe([$foto->id]);
});

it('recipe_steps.PUT nimmt auch Markdown', function () {
    (new RecipeStepsPutTool)->execute([
        'recipe_id' => $this->rezept->id,
        'preparation_markdown' => "## Finish\n1. Montieren.\n2. Abschmecken.",
    ], ($this->ctx)($this->rootTeam));

    expect(FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->pluck('text')->all())
        ->toBe(['Montieren.', 'Abschmecken.']);
});

it('recipe_steps.PUT verweigert gepflegte Rezepte (Draft-Quarantäne)', function () {
    $this->rezept->update(['status' => 'approved']);

    $r = (new RecipeStepsPutTool)->execute([
        'recipe_id' => $this->rezept->id,
        'steps' => [['text' => 'darf nicht durchkommen']],
    ], ($this->ctx)($this->rootTeam));

    expect($r->error)->toContain('approved')
        ->and(FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->count())->toBe(0);
});

it('recipe_steps.PUT verweigert geerbte Rezepte (D1)', function () {
    $r = (new RecipeStepsPutTool)->execute([
        'recipe_id' => $this->rezept->id,
        'steps' => [['text' => 'aus dem Kind-Team']],
    ], ($this->ctx)($this->childA));

    expect($r->error)->toContain('D1')
        ->and(FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->count())->toBe(0);
});

it('recipe_steps.GET liefert Schritte inkl. Fotos und ist team-gescoped', function () {
    app(RecipeStepService::class)->sync($this->rezept, [['phase' => 'Garen', 'text' => 'Anbraten.']]);
    $step = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->firstOrFail();
    $foto = FoodAlchemistRecipeStepPhoto::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->rezept->id, 'pfad' => 'a/b.jpg', 'caption' => 'Pfanne',
    ]);
    $step->photos()->attach($foto->id, ['position' => 1]);

    $r = (new RecipeStepsGetTool)->execute(['recipe_id' => $this->rezept->id], ($this->ctx)($this->rootTeam));
    $data = $r->data;

    expect($data['n_steps'])->toBe(1)
        ->and($data['steps'][0]['phase'])->toBe('Garen')
        ->and($data['steps'][0]['photos'][0]['caption'])->toBe('Pfanne')
        ->and($data['result_photo'])->toBeNull();            // noch kein Endprodukt markiert

    // Endprodukt-Bild markieren → Tool weist es aus, auch am Schritt-Foto
    app(RecipeStepService::class)->endproduktSetzen($this->rezept, $foto->id);
    $mitHero = (new RecipeStepsGetTool)->execute(['recipe_id' => $this->rezept->id], ($this->ctx)($this->rootTeam))->data;
    expect($mitHero['result_photo']['id'])->toBe($foto->id)
        ->and($mitHero['steps'][0]['photos'][0]['is_result'])->toBeTrue();

    // Kind-Team darf LESEN (Kette aufwärts) …
    $rKind = (new RecipeStepsGetTool)->execute(['recipe_id' => $this->rezept->id], ($this->ctx)($this->childA));
    expect($rKind->data['n_steps'])->toBe(1);

    // … ein Rezept aus einem Geschwister-Team nicht.
    $fremd = $this->makeRecipe($this->childB, 'Fremd-Rezept', ['status' => 'draft']);
    $rFremd = (new RecipeStepsGetTool)->execute(['recipe_id' => $fremd->id], ($this->ctx)($this->childA));
    expect($rFremd->error)->not->toBeNull();
});

it('Markdown-Schreibwege (Generator/MCP/Import) erzeugen über RecipeService echte Schritte', function () {
    $neu = app(RecipeService::class)->create($this->rootTeam, [
        'name' => 'Fond: aus dem Generator',
        'preparation' => "## Ansetzen\n1. Karkassen rösten.\n2. Mit Wasser aufsetzen.",
    ]);

    expect(FoodAlchemistRecipeStep::where('recipe_id', $neu->id)->orderBy('position')->pluck('text')->all())
        ->toBe(['Karkassen rösten.', 'Mit Wasser aufsetzen.'])
        ->and($neu->fresh()->preparation)->toBe("## Ansetzen\n1. Karkassen rösten.\n2. Mit Wasser aufsetzen.");
});

it('ein Markdown-Update überschreibt bestehende (feinere) Schritte NICHT', function () {
    app(RecipeStepService::class)->sync($this->rezept, [
        ['phase' => 'Garen', 'text' => 'handgepflegter Schritt'],
    ]);

    app(RecipeService::class)->update($this->rootTeam, $this->rezept->id, [
        'preparation' => "1. irgendwas anderes",
    ]);

    expect(FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->pluck('text')->all())
        ->toBe(['handgepflegter Schritt'])
        // …und der Spiegel zeigt die Schritte, NICHT den verworfenen Text
        ->and($this->rezept->fresh()->preparation)->toBe("## Garen\n1. handgepflegter Schritt");
});

/**
 * MCP-Lockstep (2026-09-04): seit die Anrichte-Anleitung in derselben Tabelle liegt
 * (Regelwerk Verkaufsgerichte §3.3), müssen die Step-Tools die Ebene kennen — sonst
 * könnte die UI mehr als das Tool, und ein Agent käme an den Teller-Aufbau nicht heran.
 */
it('recipe_steps.PUT/GET erreichen die Anrichte-Ebene, ohne die Produktions-Ebene zu berühren', function () {
    $step = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::class;

    $ctx = ($this->ctx)($this->rootTeam);

    (new RecipeStepsPutTool)->execute([
        'recipe_id' => $this->rezept->id,
        'steps' => [['text' => 'Filet tranchieren.']],
    ], $ctx);

    $anrichten = (new RecipeStepsPutTool)->execute([
        'recipe_id' => $this->rezept->id,
        'ebene' => 'anrichten',
        'steps' => [['text' => 'Creme aufziehen.'], ['text' => 'Jus angießen.']],
    ], $ctx);
    expect($anrichten->success)->toBeTrue('PUT anrichten: ' . ($anrichten->error ?? ''))
        ->and($anrichten->data['ebene'])->toBe('anrichten')
        ->and($anrichten->data['n_steps'])->toBe(2);

    // Die Produktions-Ebene bleibt unangetastet — und ist der Default beim Lesen.
    $default = (new \Platform\FoodAlchemist\Tools\RecipeStepsGetTool)->execute(['recipe_id' => $this->rezept->id], $ctx);
    expect($default->data['ebene'])->toBe('produktion')
        ->and($default->data['n_steps'])->toBe(1)
        ->and($default->data['steps'][0]['text'])->toBe('Filet tranchieren.');

    $gelesen = (new \Platform\FoodAlchemist\Tools\RecipeStepsGetTool)->execute([
        'recipe_id' => $this->rezept->id, 'ebene' => 'anrichten',
    ], $ctx);
    expect(collect($gelesen->data['steps'])->pluck('text')->all())->toBe(['Creme aufziehen.', 'Jus angießen.']);

    // Jede Ebene nummeriert bei 1.
    expect($step::where('recipe_id', $this->rezept->id)->ebene('anrichten')->min('position'))->toBe(1);

    // Und der Spiegel landet im richtigen Feld.
    expect($this->rezept->fresh()->plating_text)->toContain('Creme aufziehen');
});
