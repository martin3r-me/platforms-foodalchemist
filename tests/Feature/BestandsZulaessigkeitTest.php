<?php

use Illuminate\Support\Facades\Queue;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\HardstopResolveService;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\Regeln\RegelService;
use Platform\FoodAlchemist\Support\BestandsZulaessigkeit;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * EINE Bestands-Entscheidung für jeden Bindungsweg ({@see BestandsZulaessigkeit}). Anlass: dreimal galt eine Regel an
 * einer Stelle, ein zweiter Weg gab eine andere Antwort — zuletzt demo Lauf 95 (Plan lehnte „Jus: Rind (Zukauf)“ ab,
 * der Matcher band ihn trotzdem). Die Tests prüfen die Wege, die vorher NICHT oder anders prüften.
 */
beforeEach(function () {
    Queue::fake();
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->g = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'g'],
        ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->hausRegel = function (array $eintraege) {
        $regel = FoodAlchemistRule::where('schluessel', 'basisrezept.bestand.haus_zuordnung')->firstOrFail();
        app(RegelService::class)->speichere(
            [...$regel->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung', 'notiz']),
                'params' => ['vergleich' => 'text', 'eintraege' => $eintraege]], null, true);
    };
    // Matcher-Treffer erzwingen: die Entscheidung des Matchers ist nicht Gegenstand, nur was der Aufrufer daraus macht.
    $this->matcherTrifft = function (int $recipeId, string $name) {
        $mock = Mockery::mock(IngredientMatchService::class)->makePartial();
        $mock->shouldReceive('matchIngredient')->andReturn([
            'target' => 'sub_recipe', 'status' => 'exact', 'gp_id' => null, 'gp_name' => null,
            'recipe_id' => $recipeId, 'recipe_name' => $name, 'score' => 0.95,
        ]);
        app()->instance(IngredientMatchService::class, $mock);
    };
});

it('Reihenfolge: Freigabe vor Fertigung vor Passung; vertraut überspringt nur die Freigabe', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Jus: Rind (Zukauf)', ['status' => 'draft', 'production_depth' => 'convenience']);
    $k = BestandsZulaessigkeit::lade($this->rootTeam, (int) $r->id);
    $pruefe = fn (array $kontext) => BestandsZulaessigkeit::pruefe($this->rootTeam, 'Jus: Rind', $k, $kontext);

    expect($pruefe(['regler' => 'from_scratch']))->toContain('noch nicht freigegeben')
        ->and($pruefe(['regler' => 'from_scratch', 'vertraut' => true]))->toContain('from scratch')
        ->and($pruefe(['vertraut' => true]))->toBeNull();
});

it('planChildren: eine Haus-Zuordnung bindet auch bei abweichendem Namen (vorher killte die Bestandteil-Prüfung sie)', function () {
    $std = $this->makeRecipe($this->rootTeam, 'Jus: Standard');
    ($this->hausRegel)([['begriff' => 'Jus: Rind', 'ziel_typ' => 'rezept', 'ziel_name' => 'Jus: Standard']]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running', 'sort' => 1]);
    $gericht = $this->makeRecipe($this->rootTeam, 'Rinderfilet mit Jus', ['status' => 'draft']);
    $zutat = $this->makeIngredient($gericht, 'Jus: Rind');

    app(RecipeDependencyWorkflowService::class)->afterGenerated($this->rootTeam, $step->id, auth()->id(), $gericht,
        [['index' => 0, 'text' => 'Jus: Rind', 'primaer' => 'basisrezept_anlegen']],
        ['auto_dependencies' => true, 'bestand' => 'hybrid']);

    expect($zutat->refresh()->referenced_recipe_id)->toBe($std->id);
});

it('planChildren: auch die Haus-Zuordnung hält Freigabe und Fertigung ein', function () {
    $this->makeRecipe($this->rootTeam, 'Jus: Standard (Zukauf)', ['production_depth' => 'convenience']);
    ($this->hausRegel)([['begriff' => 'Jus: Rind', 'ziel_typ' => 'rezept', 'ziel_name' => 'Jus: Standard (Zukauf)']]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running', 'sort' => 1]);
    $gericht = $this->makeRecipe($this->rootTeam, 'Rinderfilet mit Jus', ['status' => 'draft']);
    $zutat = $this->makeIngredient($gericht, 'Jus: Rind');

    app(RecipeDependencyWorkflowService::class)->afterGenerated($this->rootTeam, $step->id, auth()->id(), $gericht,
        [['index' => 0, 'text' => 'Jus: Rind', 'primaer' => 'basisrezept_anlegen']],
        ['auto_dependencies' => true, 'bestand' => 'hybrid', 'convenience' => 'from_scratch']);

    expect($zutat->refresh()->referenced_recipe_id)->toBeNull();
});

it('Heilung (syncIngredients): ein „from scratch“-Rezept bekommt keinen Zukauf-Bestand, ein teilfertiges schon', function () {
    $zukauf = $this->makeRecipe($this->rootTeam, 'Jus: Rind (Zukauf)', ['production_depth' => 'convenience']);
    ($this->matcherTrifft)((int) $zukauf->id, 'Jus: Rind (Zukauf)');
    $zeile = [['id' => null, 'gp_id' => null, 'display_name' => 'Jus: Rind', 'raw_text' => 'Jus: Rind', 'quantity' => '100', 'unit_vocab_id' => $this->g->id]];

    $scratch = $this->makeRecipe($this->rootTeam, 'Rinderfilet: Test', ['status' => 'draft', 'production_depth' => 'from_scratch']);
    app(RecipeService::class)->syncIngredients($this->rootTeam, $scratch->id, $zeile);
    $teil = $this->makeRecipe($this->rootTeam, 'Rinderfilet: Teil', ['status' => 'draft', 'production_depth' => 'teilfertig']);
    app(RecipeService::class)->syncIngredients($this->rootTeam, $teil->id, $zeile);

    expect($scratch->ingredients()->first()->referenced_recipe_id)->toBeNull()
        ->and($teil->ingredients()->first()->referenced_recipe_id)->toBe($zukauf->id);
});

it('Heilung (syncIngredients): ein nicht freigegebener Matcher-Treffer wird nicht gebunden', function () {
    $entwurf = $this->makeRecipe($this->rootTeam, 'Jus: Rind', ['status' => 'draft']);
    ($this->matcherTrifft)((int) $entwurf->id, 'Jus: Rind');
    $rezept = $this->makeRecipe($this->rootTeam, 'Rinderfilet: Test', ['status' => 'draft']);

    app(RecipeService::class)->syncIngredients($this->rootTeam, $rezept->id,
        [['id' => null, 'gp_id' => null, 'display_name' => 'Jus: Rind', 'raw_text' => 'Jus: Rind', 'quantity' => '100', 'unit_vocab_id' => $this->g->id]]);

    expect($rezept->ingredients()->first()->referenced_recipe_id)->toBeNull();
});

it('gps.MATCH: der Draw bindet keinen abgelehnten Bestand, meldet aber den Grund', function () {
    $zukauf = $this->makeRecipe($this->rootTeam, 'Jus: Rind (Zukauf)', ['production_depth' => 'convenience']);
    $entwurf = $this->makeRecipe($this->rootTeam, 'Jus: Kalb', ['status' => 'draft']);
    $mock = Mockery::mock(IngredientMatchService::class)->makePartial();
    $mock->shouldReceive('matchIngredient')->andReturn(['target' => 'none', 'status' => 'no_match', 'gp_id' => null,
        'gp_name' => null, 'recipe_id' => null, 'recipe_name' => null, 'score' => 0.4]);
    $mock->shouldReceive('candidatesFor')->andReturn([
        ['kind' => 'sub', 'id' => $entwurf->id, 'name' => 'Jus: Kalb', 'score' => 0.92, 'origin' => 'semantic'],
        ['kind' => 'sub', 'id' => $zukauf->id, 'name' => 'Jus: Rind (Zukauf)', 'score' => 0.90, 'origin' => 'semantic'],
    ]);
    app()->instance(IngredientMatchService::class, $mock);

    $res = app(ToolRegistry::class)->get('foodalchemist.gps.MATCH')->execute(
        ['zutat' => 'Jus: Rind', 'mode' => 'sub_recipe_first', 'convenience' => 'from_scratch'],
        new ToolContext($this->user, $this->rootTeam));
    $daten = $res->data;

    expect($daten['best_match']['target'])->toBe('none')
        ->and(collect($daten['abgelehnt'])->pluck('grund', 'recipe_id')->all())->toMatchArray([
            $entwurf->id => 'noch nicht freigegeben (Status draft)',
            $zukauf->id => 'Zukauf, der Lauf verlangt „from scratch“',
        ]);
});

it('HardstopResolve: der Mensch darf binden — der Grund der Automatik erscheint nur als Hinweis', function () {
    $entwurf = $this->makeRecipe($this->rootTeam, 'Jus: Kalb', ['status' => 'draft']);
    $rezept = $this->makeRecipe($this->rootTeam, 'Kalbsrücken', ['status' => 'draft']);
    $zeile = $this->makeIngredient($rezept, 'Jus: Kalb', null, '100', 0);

    $erg = app(HardstopResolveService::class)->verknuepfe($this->rootTeam, $rezept->id, 0, 'sub', $entwurf->id);

    expect($erg['ok'])->toBeTrue()
        ->and($erg['hinweis'])->toContain('noch nicht freigegeben')
        ->and($erg['meldung'])->toContain('Hinweis:')
        ->and($zeile->refresh()->referenced_recipe_id)->toBe($entwurf->id);
});

/**
 * Architektur: wer Unterrezept-Kandidaten aus dem Bestand holt UND Unterrezepte verdrahtet, läuft über
 * BestandsZulaessigkeit::pruefe. Neue Bindungswege fallen hier auf statt erst auf demo.
 */
it('Architektur: jeder Bindungsweg aus dem Bestand ruft BestandsZulaessigkeit::pruefe', function () {
    $ausnahmen = [
        // Der Matcher selbst: liefert nur Kandidaten (bestSubrecipeMatch/resolveSubByName schon nur freigegeben).
        'Services/IngredientMatchService.php',
        // Nur Anzeige — die Bindung läuft danach über syncIngredients (geprüft).
        'Services/RecipeReviseService.php',
        'Services/RecipeReviewService.php',
        // Analyse „Gericht zerlegen“: erkennt Komponenten, verdrahtet nichts.
        'Services/DishReverseService.php',
        // Prüft Fertigware, bindet nicht.
        'Services/ZukaufBasisrezeptService.php',
    ];
    $src = dirname(__DIR__, 2) . '/src/';
    $verstoesse = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $datei) {
        if (! $datei->isFile() || $datei->getExtension() !== 'php') {
            continue;
        }
        $rel = substr($datei->getPathname(), strlen($src));
        $code = file_get_contents($datei->getPathname());
        if (in_array($rel, $ausnahmen, true) || str_starts_with($rel, 'Support/Bestands')) {
            continue;
        }
        $holtKandidaten = preg_match('/findByTokenSetMitReife\(|drawKandidaten\(|hausZuordnung\(|matchIngredient\(/', $code);
        $bindetSub = preg_match("/referenced_recipe_id|'sub_recipe'|=== 'sub'/", $code);
        if ($holtKandidaten && $bindetSub && ! str_contains($code, 'BestandsZulaessigkeit::pruefe')) {
            $verstoesse[] = $rel;
        }
    }

    expect($verstoesse)->toBe([]);
});
