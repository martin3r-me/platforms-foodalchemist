<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\Matching\MatchHeuristics;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 80 B4 · zweite Pfade (Review 09.10.): die Regel „Basisrezept-Zeile → nur freigegebenes Unterrezept oder
 * Lücke, nie Rohware" galt im Generator und seit #230 in syncIngredients — Mint bei der Anreicherung, der
 * Alias-Zweig des Matchers und `gps.MATCH` umgingen sie. Dazu Lauf 86: Heilung verdrahtete „bitte prüfen"-
 * Treffer, und Zustandswörter allein ergaben einen Treffer.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->mkLa = fn (string $designation) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => $designation, 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
});

it('istBasisrezeptZeile: Typ aus dem Vokabular oder Zubereitungs-Präfix, Einkaufsform ist Ware', function () {
    $h = app(MatchHeuristics::class);

    expect($h->istBasisrezeptZeile('Püree: Petersilienwurzel'))->toBeTrue()
        ->and($h->istBasisrezeptZeile('Matte: Petersilie'))->toBeTrue()     // nur im Vokabular, kein Zubereitungs-Präfix
        ->and($h->istBasisrezeptZeile('Jus: Ginger Beer'))->toBeTrue()
        ->and($h->istBasisrezeptZeile('Blätterteig: TK'))->toBeFalse()      // Einkaufsform = Ware
        ->and($h->istBasisrezeptZeile('Crunch: Röstzwiebeln, trocken (Zukauf)'))->toBeTrue()   // Zukauf schlägt Einkaufsform
        ->and($h->istBasisrezeptZeile('Öl: Kürbiskernöl, konserviert (Zukauf)'))->toBeTrue()
        ->and($h->istBasisrezeptZeile('Petersilienwurzel'))->toBeFalse()
        ->and($h->istBasisrezeptZeile('Butter: frisch'))->toBeFalse();
});

it('Anreicherung (minteFehlendeGps) mintet keine Basisrezept-Zeile, eine Roh-Lücke weiterhin', function () {
    ($this->mkLa)('Sesampaste');
    $r = $this->makeRecipe($this->rootTeam, 'Dip: Sesam', ['status' => 'approved']);
    $g = $this->unitG($this->rootTeam)->id;
    $basis = FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id,
        'raw_text' => 'Creme: Sesampaste', 'quantity' => '500', 'unit_vocab_id' => $g, 'position' => 1]);
    $roh = FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id,
        'raw_text' => 'Sesampaste', 'quantity' => '100', 'unit_vocab_id' => $g, 'position' => 2]);

    $erg = app(RecipeOneShotService::class)->minteFehlendeGps($this->rootTeam, $r->fresh());

    expect($basis->fresh()->gp_id)->toBeNull()          // bleibt Lücke, kein Sesampasten-GP als „Creme"
        ->and($roh->fresh()->gp_id)->not->toBeNull()
        ->and($erg['minted'])->toBe(1)
        ->and($erg['basisrezept_luecken'])->toBe(1);
});

it('Anreicherung: nur Basisrezept-Lücken heißt nicht „vollständig"', function () {
    ($this->mkLa)('Sesampaste');
    $r = $this->makeRecipe($this->rootTeam, 'Dip: Sesam 2', ['status' => 'approved']);
    FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id,
        'raw_text' => 'Creme: Sesampaste', 'quantity' => '500', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1]);

    expect(app(RecipeOneShotService::class)->minteFehlendeGps($this->rootTeam, $r->fresh())['status'])->toBe('basisrezept_luecken');
});

it('Alias-Zweig des Matchers entscheidet nur für freigegebene Basisrezepte', function () {
    $entwurf = $this->makeRecipe($this->rootTeam, 'Heller Kalbsfond', ['status' => 'draft']);
    $svc = app(IngredientMatchService::class);

    expect($svc->matchIngredient($this->rootTeam, 'Rinderbrühe')['recipe_id'] ?? null)->not->toBe($entwurf->id);

    $entwurf->update(['status' => 'approved']);
    $treffer = $svc->matchIngredient($this->rootTeam, 'Rinderbrühe');
    expect($treffer['target'])->toBe('sub_recipe')->and($treffer['recipe_id'])->toBe($entwurf->id);
});

it('Re-Grounding verdrahtet „bitte prüfen"-Treffer nicht (Lauf 86: Ginger Beer → Beeren-Kaviar)', function () {
    $this->makeGp($this->rootTeam, 'Beeren-Kaviar');
    $this->makeGp($this->rootTeam, 'Petersilienwurzel: frisch');
    $r = $this->makeRecipe($this->rootTeam, 'Jus: Ginger Beer', ['status' => 'draft']);
    $g = $this->unitG($this->rootTeam)->id;

    app(RecipeService::class)->syncIngredients($this->rootTeam, $r->id, [
        ['raw_text' => 'Ginger Beer', 'quantity' => 500, 'unit_vocab_id' => $g],
        ['raw_text' => 'Petersilienwurzel', 'quantity' => 100, 'unit_vocab_id' => $g],
    ]);

    $zeilen = $r->fresh()->ingredients()->orderBy('position')->get();
    expect($zeilen[0]->gp_id)->toBeNull()               // FuzzyLow 0,5 bleibt offen für den Menschen
        ->and($zeilen[1]->gp_id)->not->toBeNull();      // sicherer Treffer wie bisher
});

it('istAutomatischVerdrahtbar: nur Exact/FuzzyHigh, auch als String-Band', function () {
    expect(IngredientMatchService::istAutomatischVerdrahtbar(['status' => 'exact']))->toBeTrue()
        ->and(IngredientMatchService::istAutomatischVerdrahtbar(['status' => 'fuzzy_high']))->toBeTrue()
        ->and(IngredientMatchService::istAutomatischVerdrahtbar(['status' => 'fuzzy_low']))->toBeFalse()
        ->and(IngredientMatchService::istAutomatischVerdrahtbar(['status' => 'gezogen']))->toBeFalse()
        ->and(IngredientMatchService::istAutomatischVerdrahtbar([]))->toBeFalse();
});

it('matchScore: nur gemeinsame Zustandswörter sind kein Treffer (Lauf 86: Pilzmischung → Auberginen)', function () {
    $e = app(TokenEngine::class);
    $q = $e->tokenize('Pilzmischung: frisch, ganz');

    expect($e->matchScore($q, null, $e->tokenize('Auberginen: frisch, ganz'), null))->toBe(0.0)
        ->and($e->matchScore($q, null, $e->tokenize('Pilzmischung: frisch, geschnitten'), null))->toBeGreaterThan(0.5);
});

it('gps.MATCH: Basisrezept-Zeile bekommt kein GP und wird nicht gemintet', function () {
    $this->makeGp($this->rootTeam, 'Petersilienwurzel: frisch');
    ($this->mkLa)('Petersilienwurzel');
    $vorher = FoodAlchemistGp::count();

    $res = app(ToolRegistry::class)->get('foodalchemist.gps.MATCH')->execute(
        ['zutat' => 'Püree: Petersilienwurzel', 'mint_if_missing' => true],
        new ToolContext($this->user, $this->rootTeam),
    );

    expect($res->success)->toBeTrue()
        ->and($res->data['best_match']['gp_id'])->toBeNull()
        ->and($res->data['minted'])->toBeFalse()
        ->and($res->data['basisrezept_zeile'])->toBeTrue()
        ->and(FoodAlchemistGp::count())->toBe($vorher);
});

it('gps.MATCH: der Draw zieht nur freigegebene Basisrezepte', function () {
    $entwurf = $this->makeRecipe($this->rootTeam, 'Püree: Petersilienwurzel', ['status' => 'draft']);
    $mock = Mockery::mock(IngredientMatchService::class)->makePartial();
    $mock->shouldReceive('matchIngredient')->andReturn([
        'target' => 'none', 'status' => 'no_match', 'gp_id' => null, 'gp_name' => null,
        'recipe_id' => null, 'recipe_name' => null, 'score' => 0.4,
    ]);
    $mock->shouldReceive('candidatesFor')->andReturn([
        ['kind' => 'sub', 'id' => $entwurf->id, 'name' => $entwurf->name, 'score' => 0.9, 'origin' => 'semantic'],
    ]);
    app()->instance(IngredientMatchService::class, $mock);
    $tool = fn () => app(ToolRegistry::class)->get('foodalchemist.gps.MATCH')->execute(
        ['zutat' => 'Püree: Petersilienwurzel', 'mode' => 'sub_recipe_first'], new ToolContext($this->user, $this->rootTeam),
    );

    expect($tool()->data['best_match']['target'])->toBe('none');      // Entwurf ist kein Bestand

    $entwurf->update(['status' => 'approved']);
    expect($tool()->data['best_match']['recipe_id'])->toBe($entwurf->id);
});

it('Architektur: jeder automatische mintFromLa-Aufrufer prüft istBasisrezeptZeile (sonst bewusst ausgenommen)', function () {
    // Das Muster der Bugs vom 09.10.: die Regel gilt an einer Stelle, ein zweiter Pfad umgeht sie. Wer einen neuen
    // Mint-Pfad baut, muss hier entscheiden — prüfen oder mit Begründung ausnehmen.
    $ausgenommen = [
        'Services/LaFirstGpService.php' => 'die Mint-Fähigkeit selbst',
        'Tools/GpsMintFromLaTool.php' => 'expliziter GP-Auftrag (Name genannt), keine Rezeptzeile',
        'Services/DishReverseService.php' => 'Analyse von Freitext, schreibt keine Rezeptzeilen',
    ];
    $src = realpath(__DIR__ . '/../../src');
    $ohnePruefung = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $datei) {
        if (! str_ends_with((string) $datei, '.php')) {
            continue;
        }
        $code = (string) file_get_contents((string) $datei);
        $rel = ltrim(str_replace($src, '', (string) $datei), '/');
        if (str_contains($code, 'mintFromLa(') && ! isset($ausgenommen[$rel]) && ! str_contains($code, 'istBasisrezeptZeile(')) {
            $ohnePruefung[] = $rel;
        }
    }

    expect($ohnePruefung)->toBe([]);
});
