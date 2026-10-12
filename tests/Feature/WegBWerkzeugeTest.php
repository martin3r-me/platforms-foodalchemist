<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\ReifeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Weg B Paket 3 (Agenten-Durchlauf Gericht 3920, 2026-10-12): Werkzeuge, die dem Agenten fehlten —
 * Typ-Präfix-Hinweis beim Anlegen (Befund 4), Dichteklasse und Lagerung am Gericht per PUT (Befund 2/3),
 * Lager-Aspekt in der Reife (Befund 2), ungültige Lagerwerte als Fehler statt still null (Befund 2),
 * Aromenprofil-Neuberechnung auch für Gerichte (Befund 7).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->run = fn (string $tool, array $args) => app(ToolRegistry::class)->get($tool)->execute($args, $this->ctx);
});

it('recipes.POST: Präfix außerhalb des Typ-Vokabulars oder fehlendes Präfix = typ_hinweis, kein Block', function () {
    $ohne = ($this->run)('foodalchemist.recipes.POST', ['name' => 'Senfrostbraten vom Weiderind']);
    expect($ohne->success)->toBeTrue($ohne->error ?? '')
        ->and($ohne->data['typ_hinweis'])->toContain('ohne Typ-Präfix')
        ->and($ohne->data['note'])->toContain('recipe_typen.GET');

    // „Garmethode" steht im Seed-Vokabular (Gruppe Sous-Vide & Garmethoden) — der Agent lag damit formal richtig,
    // nur fachlich daneben; das fängt erst die Kuratorin. Geprüft wird hier ein Präfix AUSSERHALB des Vokabulars.
    $fremd = ($this->run)('foodalchemist.recipes.POST', ['name' => 'Quatschtyp: Senfrostbraten']);
    expect($fremd->success)->toBeTrue($fremd->error ?? '')
        ->and($fremd->data['typ_hinweis'])->toContain('Quatschtyp');

    $gut = ($this->run)('foodalchemist.recipes.POST', ['name' => 'Püree: Petersilienwurzel']);
    expect($gut->success)->toBeTrue($gut->error ?? '')
        ->and($gut->data['typ_hinweis'])->toBeNull();

    // Gerichte tragen keinen Typ — kein Hinweis
    $gericht = ($this->run)('foodalchemist.recipes.POST', ['name' => '[HG] Senfrostbraten | Jus', 'is_sales_recipe' => true]);
    expect($gericht->success)->toBeTrue($gericht->error ?? '')
        ->and($gericht->data['typ_hinweis'])->toBeNull();
});

it('verkaufsrezepte.PUT: dichteklasse und Lagerung am Gericht setzbar, Override-Lineage, Vokabular geprüft', function () {
    $g = $this->makeRecipe($this->rootTeam, '[HG] Filet', ['status' => 'draft', 'is_sales_recipe' => true]);

    $ok = ($this->run)('foodalchemist.verkaufsrezepte.PUT', ['id' => $g->id, 'felder' => [
        'dichteklasse' => 'dicht', 'storage_type' => 'gekuehlt', 'storage_types' => ['gekuehlt', 'tiefgekuehlt'],
        'shelf_life_chilled_days' => 3, 'shelf_life_frozen_days' => 90,
    ]]);
    expect($ok->success)->toBeTrue($ok->error ?? '');
    $g->refresh();
    expect($g->dichteklasse)->toBe('dicht')
        ->and($g->dichteklasse_source)->toBe('manual')
        ->and($g->storage_type)->toBe('gekuehlt')
        ->and($g->lagerarten())->toBe(['gekuehlt', 'tiefgekuehlt'])
        ->and($g->shelf_life_chilled_days)->toBe(3)
        ->and($g->shelf_life_frozen_days)->toBe(90);

    // Unverändert mitgeschickt → Lineage bleibt; ungültige Werte → VALIDATION_ERROR mit den erlaubten Werten
    $gleich = ($this->run)('foodalchemist.verkaufsrezepte.PUT', ['id' => $g->id, 'felder' => ['dichteklasse' => 'dicht', 'description' => 'x']]);
    expect($gleich->success)->toBeTrue()->and($g->fresh()->dichteklasse_source)->toBe('manual');

    $falschD = ($this->run)('foodalchemist.verkaufsrezepte.PUT', ['id' => $g->id, 'felder' => ['dichteklasse' => 'sahnig']]);
    expect($falschD->errorCode)->toBe('VALIDATION_ERROR')->and($falschD->error)->toContain('schuettfaehig');

    $falschL = ($this->run)('foodalchemist.verkaufsrezepte.PUT', ['id' => $g->id, 'felder' => ['storage_type' => 'kalt']]);
    expect($falschL->errorCode)->toBe('VALIDATION_ERROR')->and($falschL->error)->toContain('gekuehlt, tiefgekuehlt, trocken');
    expect($g->fresh()->storage_type)->toBe('gekuehlt');                                  // nichts still genullt

    // Leeren geht ausdrücklich
    $leer = ($this->run)('foodalchemist.verkaufsrezepte.PUT', ['id' => $g->id, 'felder' => ['dichteklasse' => null, 'storage_type' => null]]);
    expect($leer->success)->toBeTrue()->and($g->fresh()->dichteklasse)->toBeNull()->and($g->fresh()->dichteklasse_source)->toBeNull();
});

it('recipes.PUT: ungültige Lagerart ist ein VALIDATION_ERROR, kein stilles null (Basisrezept)', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Fond: Test', ['status' => 'draft', 'storage_type' => 'trocken']);

    $res = ($this->run)('foodalchemist.recipes.PUT', ['recipe_id' => $r->id, 'storage_types' => ['gekuehlt', 'lauwarm']]);
    expect($res->errorCode)->toBe('VALIDATION_ERROR')->and($res->error)->toContain('lauwarm')
        ->and($r->fresh()->storage_type)->toBe('trocken');

    $ok = ($this->run)('foodalchemist.recipes.PUT', ['recipe_id' => $r->id, 'storage_types' => ['tiefgekuehlt', 'gekuehlt'], 'storage_type' => 'gekuehlt']);
    expect($ok->success)->toBeTrue($ok->error ?? '')
        ->and($r->fresh()->lagerarten())->toBe(['gekuehlt', 'tiefgekuehlt']);          // feste Reihenfolge
});

it('Reife: Lagerung ist ein Soll-Aspekt (Hinweis) auf beiden Ebenen — mit Schreib-Tool', function () {
    $svc = app(ReifeService::class);
    $basis = $this->makeRecipe($this->rootTeam, 'Fond: Lager', ['status' => 'draft']);
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Lager', ['status' => 'draft', 'is_sales_recipe' => true]);

    $rb = $svc->reife($this->rootTeam, 'recipe', $basis->id);
    $lb = collect($rb['luecken'])->firstWhere('code', 'lagerung');
    expect($lb)->not->toBeNull()
        ->and($lb['schwere'])->toBe('hinweis')
        ->and($lb['wie']['tool'] ?? null)->toBe('foodalchemist.recipes.PUT');

    $rg = $svc->reife($this->rootTeam, 'recipe', $gericht->id);
    expect(collect($rg['luecken'])->firstWhere('code', 'lagerung')['wie']['tool'] ?? null)->toBe('foodalchemist.verkaufsrezepte.PUT');

    $basis->update(['storage_types' => ['gekuehlt']]);
    $rb2 = $svc->reife($this->rootTeam, 'recipe', $basis->id);
    expect(collect($rb2['luecken'])->pluck('code')->all())->not->toContain('lagerung')
        ->and($rb2['erfuellt'])->toContain('lagerung');
});

it('recipes.RECOMPUTE: auch ein Gericht lässt sich neu berechnen (Aromenprofil nach Rezeptänderung)', function () {
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Recompute', ['status' => 'draft', 'is_sales_recipe' => true]);
    $this->makeIngredient($gericht, 'Kresse', $this->makeGp($this->rootTeam, 'Kresse: frisch'), '5', 1);

    $res = ($this->run)('foodalchemist.recipes.RECOMPUTE', ['id' => $gericht->id]);
    expect($res->success)->toBeTrue($res->error ?? '')
        ->and($res->data['recomputed'])->toBeTrue()
        ->and($res->data['betroffene_rezepte'])->toBeGreaterThanOrEqual(1);
});
