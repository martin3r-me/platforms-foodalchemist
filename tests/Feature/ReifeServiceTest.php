<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\ReifeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 · Schicht 4 — die Vollständigkeits-Messung.
 *
 * Getestet wird die MESSUNG, nicht das Gemessene: die Fälle werden absichtlich gebaut und
 * geprüft, dass der Report sie findet UND richtig einordnet. Eine Messung, die immer „alles
 * gut" meldet, sieht aus wie Erfolg — deshalb steht zu jedem Positivfall der Gegenfall.
 *
 * Die tragende Invariante hat einen eigenen Test: **read-only**. `wirtschaftlichkeitsGlied`
 * legt beim Messen eine Standard-Darreichung an und schreibt die Aufschlagsklasse fest; der
 * Reife-Report darf genau das NICHT — er hängt am Schreibpfad und würde sonst bei jedem
 * `recipes.POST` Daten erzeugen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ReifeService::class);
});

it('Basisrezept: meldet die Felder ohne Schrittfolge als eigene Lücken', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Fond: Test', ['work_time_min' => null, 'dichteklasse' => null]);

    $reife = $this->svc->reife($this->rootTeam, 'recipe', $r->id);
    $codes = array_column($reife['luecken'], 'code');

    // Beide tragen je eine Kette: ohne work_time_min rechnet die Kalkulation FEK = 0,
    // ohne dichteklasse kann der Behälterbedarf nicht gerechnet werden.
    expect($codes)->toContain('work_time_min')
        ->and($codes)->toContain('dichteklasse')
        ->and($reife['ampel'])->toBe('rot');           // work_time_min ist `blockiert`
});

it('Gegenfall: gefüllte Felder erscheinen unter erfuellt, nicht als Lücke', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Fond: Voll', ['work_time_min' => 30, 'dichteklasse' => 'mittel']);

    $reife = $this->svc->reife($this->rootTeam, 'recipe', $r->id);

    expect(array_column($reife['luecken'], 'code'))->not->toContain('work_time_min')
        ->and($reife['erfuellt'])->toContain('work_time_min')
        ->and($reife['erfuellt'])->toContain('dichteklasse');
});

it('ehrliche Degradation: Pairings ohne Anker sind nicht messbar, keine Lücke', function () {
    // Das `pairings`-Glied steigt ohne Anker-Grounding aus. Als Lücke gemeldet wäre es ein
    // Auftrag, den niemand erfüllen kann — also gehört es in `nicht_messbar`.
    $r = $this->makeRecipe($this->rootTeam, 'Fond: Ohne Anker', ['work_time_min' => 10]);

    $reife = $this->svc->reife($this->rootTeam, 'recipe', $r->id);

    expect(array_column($reife['luecken'], 'code'))->not->toContain('pairings')
        ->and(array_column($reife['nicht_messbar'], 'code'))->toContain('pairings');
});

it('★ read-only: die Messung legt KEINE Standard-Darreichung an', function () {
    // `wirtschaftlichkeitsGlied` ruft `ensureStandard` und schreibt die Aufschlagsklasse fest.
    // Der Reife-Report nutzt denselben Rechenweg (vkVorbedingungen), darf aber nichts erzeugen —
    // sonst produziert jede Write-Antwort, die ihn mitliefert, stillschweigend Daten.
    $g = $this->makeRecipe($this->rootTeam, 'HG: Ohne Darreichung', ['is_sales_recipe' => true]);
    expect(FoodAlchemistRecipeDarreichung::where('recipe_id', $g->id)->count())->toBe(0);

    $reife = $this->svc->reife($this->rootTeam, 'sales_recipe', $g->id);

    expect(FoodAlchemistRecipeDarreichung::where('recipe_id', $g->id)->count())->toBe(0)
        ->and($g->fresh()->markup_class_id)->toBeNull()
        // …und die fehlende Darreichung wird als blockierende Lücke gemeldet, nicht behoben.
        ->and(array_column($reife['luecken'], 'code'))->toContain('darreichung')
        ->and($reife['ampel'])->toBe('rot');
});

it('naechste_schritte: je Tool einmal, blockierende zuerst', function () {
    $g = $this->makeRecipe($this->rootTeam, 'HG: Schritte', ['is_sales_recipe' => true]);

    $reife = $this->svc->reife($this->rootTeam, 'sales_recipe', $g->id);
    $tools = array_column($reife['naechste_schritte'], 'tool');

    expect($tools)->toBe(array_values(array_unique($tools)))            // keine Dubletten
        ->and($reife['naechste_schritte'][0]['pflicht'])->toBeTrue();   // schwerste zuerst
});

it('kurz(): leer, wenn nichts offen ist — sonst die Codes', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Fond: Kurzform', ['work_time_min' => 5]);

    $kurz = $this->svc->kurz($this->rootTeam, 'recipe', $r->id);

    expect($kurz)->not->toBeNull()
        ->and($kurz['luecken'])->toBeArray()
        ->and($kurz)->toHaveKeys(['ampel', 'offen', 'luecken', 'naechste_schritte']);
});

it('recipes.REIFE: fremdes Rezept ist nicht sichtbar', function () {
    $fremdesTeam = Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false]);
    $fremd = $this->makeRecipe($fremdesTeam, 'Fond: Fremd');

    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $res = app(ToolRegistry::class)->get('foodalchemist.recipes.REIFE')
        ->execute(['recipe_id' => $fremd->id], new ToolContext($user, $this->rootTeam));

    expect($res->success)->toBeFalse();
});

it('unbekannter Artefakt-Typ wirft — kein stiller Leerbefund', function () {
    expect(fn () => $this->svc->reife($this->rootTeam, 'quatsch', 1))
        ->toThrow(InvalidArgumentException::class);
});
