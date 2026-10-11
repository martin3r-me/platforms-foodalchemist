<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Services\RecipeKomponentenPlanService;
use Platform\FoodAlchemist\Services\RecipeRollenService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Services\SpeisenKlassenService;
use Platform\FoodAlchemist\Support\TellerRolle;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Rollen-Umbau (Dominique 11.10.): Rollen gibt es nur im GERICHT (Teller-Vokabular als Regel), das BASISREZEPT trägt
 * seine möglichen Rollen (Tabelle foodalchemist_recipe_rollen, Quelle je Eintrag), Basisrezept-Zeilen tragen weder
 * Rolle noch Funktion. V-21 (aroma_treiber|komponente|beilage|garnitur) ist abgelöst, Altwerte in role_v21.
 */
beforeEach(function () {
    Queue::fake();
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->g = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'g'],
        ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->gp = fn (string $name) => FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'tr|' . bin2hex(random_bytes(4)),
        'name' => $name, 'status' => 'approved', 'is_platzhalter' => false]);
});

it('Teller-Vokabular aus der Regel: Alias, Anrichte-Rang, Pairing-Gewicht; V-21-Werte sind ungültig', function () {
    expect(TellerRolle::kanonisch('Säure'))->toBe('saeure')
        ->and(TellerRolle::kanonisch('knusprig'))->toBe('crunch')            // Alias nur in der Regel
        ->and(TellerRolle::kanonisch('komponente'))->toBeNull()
        ->and(TellerRolle::kanonisch('aroma_treiber'))->toBeNull()
        ->and(TellerRolle::rang('basis'))->toBe(1)->and(TellerRolle::rang('garnitur'))->toBe(8)->and(TellerRolle::rang(null))->toBe(99)
        ->and(TellerRolle::gewicht('sauce'))->toBe(1.5)->and(TellerRolle::gewicht('crunch'))->toBe(0.4)->and(TellerRolle::gewicht(null))->toBe(1.0)
        ->and(FoodAlchemistRule::where('schluessel', 'rezeptzeile.funktion')->value('aktiv'))->toBeFalsy();
});

it('Migration: V-21-Altwerte ins Archiv; im Gericht nur beilage/garnitur übernommen, im Basisrezept keine Zeilen-Rolle', function () {
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Rinderfilet', ['status' => 'draft', 'is_sales_recipe' => true]);
    $basis = $this->makeRecipe($this->rootTeam, 'Jus: Rind', ['status' => 'draft']);
    $z = fn ($r, $rolle, $pos) => tap($this->makeIngredient($r, 'Zeile ' . $pos, null, '10', $pos))->update(['role' => $rolle]);
    $g1 = $z($gericht, 'komponente', 1); $g2 = $z($gericht, 'beilage', 2); $g3 = $z($gericht, 'aroma_treiber', 3);
    $b1 = $z($basis, 'aroma_treiber', 1);
    Schema::table('foodalchemist_recipe_ingredients', fn ($t) => $t->dropColumn('role_v21'));

    (require dirname(__DIR__, 2) . '/database/migrations/2026_10_15_100100_teller_rolle_umstellung.php')->up();

    $f = fn ($x) => DB::table('foodalchemist_recipe_ingredients')->where('id', $x->id)->first(['role', 'role_v21']);
    expect((array) $f($g1))->toBe(['role' => null, 'role_v21' => 'komponente'])
        ->and((array) $f($g2))->toBe(['role' => 'beilage', 'role_v21' => 'beilage'])
        ->and((array) $f($g3))->toBe(['role' => null, 'role_v21' => 'aroma_treiber'])
        ->and((array) $f($b1))->toBe(['role' => null, 'role_v21' => 'aroma_treiber']);
});

it('Generator Basisrezept: Rollen der KI am Rezept (Quelle ki), Zeilen ohne Rolle; im Kaskaden-Kind die geplante Rolle vorn', function () {
    $ki = ['name' => 'Jus: Rind', 'rollen' => ['Sauce', 'aroma', 'komponente'],
        'zutaten' => [['text' => 'Rinderknochen: frisch', 'quantity' => 3000, 'unit' => 'g', 'role' => 'protein']]];
    $frei = app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'Test', [], $ki)['recipe'];
    $kind = app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'Test', ['kind_rolle' => 'aroma'], [...$ki, 'name' => 'Jus: Kalb'])['recipe'];

    $quellen = fn ($r) => FoodAlchemistRecipeRolle::where('recipe_id', $r->id)->orderBy('position')->get()->map(fn ($x) => "{$x->rolle}:{$x->source}")->all();
    expect($quellen($frei))->toBe(['sauce:ki', 'aroma:ki'])
        ->and($quellen($kind))->toBe(['aroma:kaskade', 'sauce:ki'])
        ->and($frei->ingredients()->first()->role)->toBeNull();
});

it('Kind-Pfad Ende-zu-Ende: Rolle der Gericht-Zeile → Plan-Job → Bau-Job → echte KI-Antwort über das Gateway → Rollen am Kind', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review', 'staged' => true, 'brief' => 'Rinderfilet']);
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Rinderfilet', ['status' => 'draft', 'is_sales_recipe' => true]);
    $zeile = $this->makeIngredient($gericht, 'Jus: Rotwein', null, '80', 1);
    $zeile->update(['role' => 'sauce']);
    $eltern = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht',
        'status' => 'done', 'label' => 'Rinderfilet', 'sort' => 1, 'depth' => 0, 'ref_type' => 'recipe', 'ref_id' => $gericht->id,
        'deferred' => ['children' => ['params' => [], 'user_id' => $this->user->id]]]);
    $kind = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'parent_step_id' => $eltern->id,
        'kind' => 'rezept', 'status' => 'geplant', 'label' => 'Jus: Rotwein', 'sort' => 2, 'depth' => 1]);
    \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'parent_step_id' => $eltern->id, 'child_step_id' => $kind->id, 'ingredient_id' => $zeile->id]);

    // 1. „jetzt erzeugen“ → Plan-Job trägt die geplante Rolle
    app(\Platform\FoodAlchemist\Services\PlanningCascadeService::class)->erzeugeGeplantenStep($this->rootTeam, (int) $kind->id);
    $planJob = null;
    Queue::assertPushed(GenerateRecipePlanJob::class, function ($j) use (&$planJob) { $planJob = $j; return ($j->params['kind_rolle'] ?? null) === 'sauce'; });

    // 2. nach dem Plan → Bau-Job mit derselben Rolle
    app(RecipeDependencyWorkflowService::class)->baueKindNachPlan($this->rootTeam, $kind->fresh(), $this->user->id, 'Jus: Rotwein', $planJob->params, []);
    $bauJob = null;
    Queue::assertPushed(GenerateRecipeJob::class, function ($j) use (&$bauJob) { $bauJob = $j; return ($j->parameter['kind_rolle'] ?? null) === 'sauce'; });

    // 3. Bau-Job mit einer KI-Antwort, die durch den ECHTEN Gateway-/Parse-Weg läuft (kein kiRezeptOverride)
    app()->bind(\Platform\FoodAlchemist\Services\Ai\FakeAiProvider::class, fn () => new class extends \Platform\FoodAlchemist\Services\Ai\FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            $werte = ['name' => 'Jus: Rotwein', 'description' => 'Kräftige Jus.', 'preparation' => 'Reduzieren.', 'rollen' => ['aroma', 'sauce'],
                'zutaten' => [['text' => 'Rotwein: konserviert', 'quantity' => 500, 'unit' => 'ml', 'role' => 'sauce', 'funktion' => 'basis']]];

            return ['content' => json_encode(['werte' => $werte, 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE),
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'fake', 'tool_calls' => null];
        }
    });
    $bauJob->handle(app(RecipeGeneratorService::class));
    $rezept = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::where('name', 'Jus: Rotwein')->where('is_sales_recipe', false)->latest('id')->firstOrFail();

    expect(FoodAlchemistRecipeRolle::where('recipe_id', $rezept->id)->orderBy('position')->get()->map(fn ($x) => "{$x->rolle}:{$x->source}")->all())
        ->toBe(['sauce:kaskade', 'aroma:ki'])
        ->and($rezept->ingredients()->first()->role)->toBeNull()          // Basisrezept-Zeile: keine Rolle
        ->and($rezept->ingredients()->first()->funktion)->toBeNull();     // … und keine Funktion mehr
});

it('Gericht: KI-Rolle validiert, Rolle aus dem eingesetzten Basisrezept, Hinweis bei abweichender Rolle, Anrichte-Reihenfolge', function () {
    $jus = $this->makeRecipe($this->rootTeam, 'Jus: Rind');
    app(RecipeRollenService::class)->setze($jus->id, ['sauce'], 'vorschlag');
    $res = app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'Test', [], [
        'name' => '[HG] Rinderfilet | Jus',
        'zutaten' => [
            ['text' => 'Kresse: frisch', 'quantity' => 2, 'unit' => 'g', 'role' => 'garnitur'],
            ['text' => 'Jus: Rind', 'quantity' => 80, 'unit' => 'g', 'sub_rezept_id' => $jus->id],
            ['text' => 'Püree: Sellerie', 'quantity' => 120, 'unit' => 'g', 'role' => 'Basis'],
            ['text' => 'Gel: Limette', 'quantity' => 10, 'unit' => 'g', 'role' => 'komponente'],
        ],
    ], vkModus: true);
    $zeilen = $res['recipe']->ingredients()->orderBy('position')->get(['display_name', 'role'])
        ->map(fn ($z) => $z->display_name . '=' . ($z->role ?? '-'))->all();

    expect($zeilen)->toBe(['Püree: Sellerie=basis', 'Jus: Rind=sauce', 'Kresse: frisch=garnitur', 'Gel: Limette=-']);
});

it('Gericht: setzt die KI eine andere Rolle als der Baustein trägt, wird es sichtbar (keine Sperre)', function () {
    $jus = $this->makeRecipe($this->rootTeam, 'Jus: Rind');
    app(RecipeRollenService::class)->setze($jus->id, ['sauce'], 'vorschlag');
    $res = app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'Test', [], [
        'name' => '[HG] Rinderfilet | Jus',
        'zutaten' => [['text' => 'Jus: Rind', 'quantity' => 80, 'unit' => 'g', 'sub_rezept_id' => $jus->id, 'role' => 'protein']],
    ], vkModus: true);

    expect($res['recipe']->ingredients()->first()->referenced_recipe_id)->toBe($jus->id)
        ->and($res['statistik']['rolle_hinweise'][0]['rollen_des_bausteins'] ?? null)->toBe(['sauce']);
});

it('Speichern: Basisrezept-Zeilen verlieren eine Rolle, Gericht-Zeilen werden gegen das Vokabular geprüft', function () {
    $basis = $this->makeRecipe($this->rootTeam, 'Jus: Rind', ['status' => 'draft']);
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Filet', ['status' => 'draft', 'is_sales_recipe' => true]);
    $zeile = fn (string $rolle) => [['id' => null, 'gp_id' => ($this->gp)('Rinderknochen: frisch')->id, 'display_name' => 'Rinderknochen',
        'raw_text' => 'Rinderknochen', 'quantity' => '100', 'unit_vocab_id' => $this->g->id, 'role' => $rolle]];

    app(RecipeService::class)->syncIngredients($this->rootTeam, $basis->id, $zeile('sauce'));
    app(RecipeService::class)->syncIngredients($this->rootTeam, $gericht->id, $zeile('Säure'));

    expect($basis->ingredients()->first()->role)->toBeNull()
        ->and($gericht->ingredients()->first()->role)->toBe('saeure');
});

it('planChildren: übernommener Baustein ohne die geplante Rolle → sichtbarer Hinweis am Reuse-Step', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running', 'sort' => 1]);
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Rinderfilet', ['status' => 'draft', 'is_sales_recipe' => true]);
    $this->makeIngredient($gericht, 'Jus: Rind')->update(['role' => 'aroma']);
    $jus = $this->makeRecipe($this->rootTeam, 'Jus: Rind');
    app(RecipeRollenService::class)->setze($jus->id, ['sauce'], 'vorschlag');

    app(RecipeDependencyWorkflowService::class)->afterGenerated($this->rootTeam, $step->id, auth()->id(), $gericht,
        [['index' => 0, 'text' => 'Jus: Rind', 'primaer' => 'basisrezept_anlegen']], ['auto_dependencies' => true, 'bestand' => 'hybrid']);
    $reuse = FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->where('depth', 1)->firstOrFail();

    expect($reuse->status)->toBe('skipped')
        ->and($reuse->deferred['rolle_hinweis'] ?? null)->toBe(['geplant' => 'aroma', 'rollen_des_bausteins' => ['sauce']]);
});

it('Nutzungs-Historie zählt die Teller-Rollen', function () {
    $jus = $this->makeRecipe($this->rootTeam, 'Jus: Rind');
    foreach (['sauce', 'sauce', 'aroma'] as $i => $rolle) {
        $g = $this->makeRecipe($this->rootTeam, '[HG] Gericht ' . $i, ['status' => 'draft', 'is_sales_recipe' => true]);
        $this->makeIngredient($g, 'Jus: Rind')->update(['referenced_recipe_id' => $jus->id, 'role' => $rolle]);
    }

    expect(app(RecipeKomponentenPlanService::class)->verwendung($this->rootTeam, $jus->id))->toBe('in 3 Gerichten: 2× sauce, 1× aroma');
});

it('Kommando klassifizieren: schätzt ohne --apply nichts, schreibt mit --apply nur leere Zeilen', function () {
    $g = $this->makeRecipe($this->rootTeam, '[HG] Filet', ['status' => 'draft', 'is_sales_recipe' => true]);
    $leer = $this->makeIngredient($g, 'Rinderfilet', null, '150', 1);
    $voll = $this->makeIngredient($g, 'Kresse', null, '2', 2);
    $voll->update(['role' => 'garnitur']);
    $mock = Mockery::mock(SpeisenKlassenService::class)->makePartial();
    $mock->shouldReceive('verteileRollen')->andReturn(['rollen' => [$leer->id => 'protein', $voll->id => 'aroma'], 'confidence' => 0.9, 'reasoning' => null, 'call_log_id' => null]);
    app()->instance(SpeisenKlassenService::class, $mock);

    $this->artisan('foodalchemist:teller-rollen-klassifizieren', ['--team' => $this->rootTeam->id])->assertSuccessful();
    expect($leer->fresh()->role)->toBeNull();
    $this->artisan('foodalchemist:teller-rollen-klassifizieren', ['--team' => $this->rootTeam->id, '--apply' => true])->assertSuccessful();

    expect($leer->fresh()->role)->toBe('protein')->and($voll->fresh()->role)->toBe('garnitur');
});

it('Kommando vorschlagen: aus Nutzung und Typ, Quelle vorschlag; manuelle Rollen bleiben', function () {
    $jus = $this->makeRecipe($this->rootTeam, 'Jus: Rind');
    $pueree = $this->makeRecipe($this->rootTeam, 'Püree: Sellerie');
    $hand = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');
    app(RecipeRollenService::class)->setze($hand->id, ['crunch'], 'manuell');
    $g = $this->makeRecipe($this->rootTeam, '[HG] Filet', ['status' => 'draft', 'is_sales_recipe' => true]);
    $this->makeIngredient($g, 'Jus: Rind')->update(['referenced_recipe_id' => $jus->id, 'role' => 'aroma']);

    $this->artisan('foodalchemist:basisrezept-rollen-vorschlagen', ['--team' => $this->rootTeam->id, '--apply' => true])->assertSuccessful();
    $rollen = fn ($r) => FoodAlchemistRecipeRolle::where('recipe_id', $r->id)->orderBy('position')->get()->map(fn ($x) => "{$x->rolle}:{$x->source}")->all();

    expect($rollen($jus))->toBe(['aroma:vorschlag', 'sauce:vorschlag'])      // Nutzung zuerst, dann Typ (Jus → sauce, aroma)
        ->and($rollen($pueree))->toBe(['beilage:vorschlag', 'basis:vorschlag'])
        ->and($rollen($hand))->toBe(['crunch:manuell']);
});

it('MCP: recipes.PUT setzt Rollen (manuell), recipes.GET liefert sie, recipes.LIST filtert nach Rolle', function () {
    $jus = $this->makeRecipe($this->rootTeam, 'Jus: Rind', ['status' => 'draft']);
    $this->makeRecipe($this->rootTeam, 'Crunch: Haselnuss', ['status' => 'draft']);
    $k = new ToolContext($this->user, $this->rootTeam);
    $reg = app(ToolRegistry::class);

    $put = $reg->get('foodalchemist.recipes.PUT')->execute(['recipe_id' => $jus->id, 'rollen' => ['Sauce', 'komponente', 'aroma']], $k);
    $get = $reg->get('foodalchemist.recipes.GET')->execute(['id' => $jus->id], $k);
    $list = $reg->get('foodalchemist.recipes.LIST')->execute(['rolle' => 'sauce'], $k);

    expect($put->success)->toBeTrue()
        ->and($get->data['rollen'])->toBe([['rolle' => 'sauce', 'source' => 'manuell'], ['rolle' => 'aroma', 'source' => 'manuell']])
        ->and(collect($list->data['recipes'])->pluck('name')->all())->toBe(['Jus: Rind']);
});

it('Generator-Kontext und Prompts: Teller-Rollen statt Funktion, Nutzungs-Historie an der Plan-Komponente', function () {
    $ctx = app(\Platform\FoodAlchemist\Services\RecipeGenerationContextService::class)->build($this->rootTeam, 'Rinderfilet-Sauce', [
        'plan_komponenten' => [['name' => 'Jus: Rind', 'menge' => 100, 'einheit' => 'g', 'bestand' => ['recipe_id' => 1, 'name' => 'Jus: Rind', 'verwendung' => 'in 3 Gerichten: 3× sauce']]],
    ], false);
    $task = fn (string $key) => (string) (config('foodalchemist.prompts', [])[$key]['task'] ?? '');

    expect($ctx['prompt']['teller_rollen'] ?? [])->toBe(TellerRolle::werte())
        ->and($ctx['prompt'])->not->toHaveKey('funktionen')
        ->and($ctx['prompt']['zusammenstellung']['komponenten'][0]['verwendung'] ?? null)->toBe('in 3 Gerichten: 3× sauce')
        ->and($task('recipe.generator'))->toContain('rollen (1-3 Teller-Rollen laut Regel-Block')->not->toContain('funktion (')->not->toContain('role (')
        ->and($task('recipe.ueberarbeiten'))->not->toContain('funktion')
        ->and($task('vk.generator'))->toContain('role (genau EINE Teller-Rolle laut Regel-Block')->toContain('aroma_treiber/komponente sind ungueltig')
        ->and($task('vk.rollen'))->toContain('komponente sind UNGUELTIG')
        // Spec 81 E: keine zweite, hart codierte Liste in den Prompt-Texten — die Werte kommen aus der Regel.
        ->and($task('vk.generator') . $task('vk.rollen') . $task('recipe.generator'))->not->toContain('protein')->not->toContain('crunch')
        ->and(config('foodalchemist.prompts', []))->not->toHaveKey('gp.role');
});

/**
 * demo Probelauf 11.10. (Calls 2832–2834): die KI lieferte V-21 (komponente/aroma_treiber), weil der Kanon von vk.rollen
 * (Regelwerk §12) das alte Vokabular noch vorschrieb. Geprüft wird darum, was die KI WIRKLICH bekommt — durch den echten
 * Gateway-Weg —, und dass ein Altwert gemeldet statt still verworfen wird.
 */
it('vk.rollen: die KI bekommt das Teller-Vokabular samt Hinweis; ein V-21-Wert wird gemeldet, nicht still leer', function () {
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Rinderfilet', ['status' => 'draft', 'is_sales_recipe' => true]);
    $filet = $this->makeIngredient($gericht, 'Rinderfilet', null, '150', 1);
    $jus = $this->makeIngredient($gericht, 'Jus: Rind', null, '60', 2);
    $gesendet = new ArrayObject;
    app()->bind(\Platform\FoodAlchemist\Services\Ai\FakeAiProvider::class, fn () => new class($gesendet, $filet->id, $jus->id) extends \Platform\FoodAlchemist\Services\Ai\FakeAiProvider
    {
        public function __construct(private ArrayObject $gesendet, private int $a, private int $b)
        {
        }

        public function chat(array $messages, array $options = []): array
        {
            $this->gesendet->append(implode("\n", array_map(fn ($m) => (string) $m['content'], $messages)));
            $werte = ['rollen' => [$this->a => 'komponente', $this->b => 'sauce']];

            return ['content' => json_encode(['werte' => $werte, 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE),
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'fake', 'tool_calls' => null];
        }
    });

    $erg = app(SpeisenKlassenService::class)->verteileRollen($this->rootTeam, $gericht->id);
    $text = (string) ($gesendet[0] ?? '');

    expect($text)->toContain('"vokabular":["basis","protein","beilage","sauce","crunch","saeure","aroma","garnitur"]')
        ->and($text)->toContain('vokabular_hinweis')
        ->and($text)->toContain('UNGUELTIG')
        ->and($erg['rollen'])->toBe([$jus->id => 'sauce'])
        ->and($erg['verworfen'])->toBe([$filet->id => 'komponente']);
});

it('Kommando klassifizieren: ungültige KI-Rollen werden gezählt und gemeldet (Exit ≠ 0)', function () {
    $g = $this->makeRecipe($this->rootTeam, '[HG] Filet', ['status' => 'draft', 'is_sales_recipe' => true]);
    $a = $this->makeIngredient($g, 'Rinderfilet', null, '150', 1);
    $b = $this->makeIngredient($g, 'Kresse', null, '2', 2);
    $mock = Mockery::mock(SpeisenKlassenService::class)->makePartial();
    $mock->shouldReceive('verteileRollen')->andReturn(['rollen' => [$b->id => 'garnitur'], 'verworfen' => [$a->id => 'komponente'],
        'confidence' => 0.9, 'reasoning' => null, 'call_log_id' => null]);
    app()->instance(SpeisenKlassenService::class, $mock);

    $this->artisan('foodalchemist:teller-rollen-klassifizieren', ['--team' => $this->rootTeam->id, '--apply' => true])
        ->expectsOutputToContain('1 Zeilen mit ungültiger Rolle von der KI')
        ->assertFailed();
    expect($a->fresh()->role)->toBeNull()->and($b->fresh()->role)->toBe('garnitur');
});

it('Generator Gericht: eine KI-Rolle außerhalb des Vokabulars steht in der Statistik', function () {
    $res = app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'Test', [], [
        'name' => '[HG] Rinderfilet', 'zutaten' => [['text' => 'Rinderfilet: frisch', 'quantity' => 150, 'unit' => 'g', 'role' => 'komponente']],
    ], vkModus: true);

    expect($res['statistik']['rolle_ungueltig'] ?? [])->toBe([['zeile' => 'Rinderfilet: frisch', 'wert' => 'komponente']]);
});

/**
 * Spec 81 Teil E (Dominique 11.10.): das Teller-Vokabular kommt NUR aus der Regel in den Prompt — nicht mehr aus der
 * Tabelle im Kanon-Dossier §12. Ein neuer Wert in der Regel erreicht die KI ohne Dossier-Änderung.
 */
it('Regel-Block: ein neuer Wert in gericht.teller_rolle steht im gesendeten Prompt von vk.rollen — ohne Dossier', function () {
    $regel = FoodAlchemistRule::where('schluessel', TellerRolle::SCHLUESSEL)->firstOrFail();
    $params = $regel->params;
    $params['werte'][] = ['wert' => 'testrolle'];
    app(\Platform\FoodAlchemist\Services\Regeln\RegelService::class)->speichere([...$regel->only(['schluessel', 'regelwerk', 'paragraph',
        'titel', 'art', 'ziel', 'wirkung', 'beispiele', 'dossier_slug']), 'params' => $params], null, null, 'test');
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Rinderfilet', ['status' => 'draft', 'is_sales_recipe' => true]);
    $this->makeIngredient($gericht, 'Rinderfilet', null, '150', 1);
    $gesendet = new ArrayObject;
    app()->bind(\Platform\FoodAlchemist\Services\Ai\FakeAiProvider::class, fn () => new class($gesendet) extends \Platform\FoodAlchemist\Services\Ai\FakeAiProvider
    {
        public function __construct(private ArrayObject $gesendet)
        {
        }

        public function chat(array $messages, array $options = []): array
        {
            $this->gesendet->append($messages);

            return ['content' => json_encode(['werte' => ['rollen' => []], 'confidence' => 0.8, 'reasoning' => 'stub']),
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'fake', 'tool_calls' => null];
        }
    });

    app(SpeisenKlassenService::class)->verteileRollen($this->rootTeam, $gericht->id);
    $regelBlock = collect($gesendet[0] ?? [])->first(fn ($m) => $m['role'] === 'system' && str_contains((string) $m['content'], 'VERBINDLICHE REGELN'));

    expect($regelBlock)->not->toBeNull()
        ->and($regelBlock['content'])->toContain('basis, protein, beilage, sauce, crunch, saeure, aroma, garnitur, testrolle')
        ->and($regelBlock['content'])->toContain('Anrichte-Reihenfolge');
});

it('Konsumenten-Wächter: §12 als Kanon an vk.rollen ist kein Befund — die Regel kommt über den Regel-Block', function () {
    $slug = FoodAlchemistRule::where('schluessel', TellerRolle::SCHLUESSEL)->value('dossier_slug');
    DB::table('foodalchemist_knowledge_documents')->insert(['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'team_id' => $this->rootTeam->id, 'slug' => $slug, 'title' => '§12', 'category' => 'regelwerk', 'content_md' => 'Teller-Rollen …',
        'version' => 1, 'content_hash' => str_repeat('c', 64), 'char_count' => 14, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService::class)->set($this->rootTeam,
        ['scope' => 'prompt_key', 'scope_key' => 'vk.rollen', 'slug' => $slug, 'mode' => 'pflicht']);

    expect($slug)->toBe('regelwerk-basisrezepte-12-zutaten-komponenten-reihenfolge')
        ->and(collect(app(\Platform\FoodAlchemist\Services\Regeln\RegelKonsumentenPruefung::class)->befunde())->where('schluessel', TellerRolle::SCHLUESSEL))
        ->toHaveCount(0);
});

it('Eine Quelle: ist die Regel aus, gibt es keine Teller-Rollen — kein Rückfall auf eine fest codierte Liste', function () {
    FoodAlchemistRule::where('schluessel', TellerRolle::SCHLUESSEL)->update(['aktiv' => false]);
    RegelBuch::vergessen();

    expect(TellerRolle::werte())->toBe([])->and(TellerRolle::kanonisch('protein'))->toBeNull();
});

it('Anzeigenamen kommen aus der Regel (werte[].label); ein neuer Wert ohne Label erscheint mit großem Anfangsbuchstaben', function () {
    $regel = FoodAlchemistRule::where('schluessel', TellerRolle::SCHLUESSEL)->firstOrFail();
    $params = $regel->params;
    $params['werte'][] = ['wert' => 'kraeuter_akzent'];
    app(\Platform\FoodAlchemist\Services\Regeln\RegelService::class)->speichere([...$regel->only(['schluessel', 'regelwerk', 'paragraph',
        'titel', 'art', 'ziel', 'wirkung', 'beispiele', 'dossier_slug']), 'params' => $params], null, null, 'test');

    expect(TellerRolle::labels())->toMatchArray(['saeure' => 'Säure', 'basis' => 'Basis', 'kraeuter_akzent' => 'Kraeuter akzent']);
});
