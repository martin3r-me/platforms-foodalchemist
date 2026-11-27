<?php

use Platform\FoodAlchemist\Livewire\Recipes\StepEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Services\Gericht\GerichtHuelleService;
use Platform\FoodAlchemist\Services\Rezept\RecipeGeneratorService;
use Platform\FoodAlchemist\Services\Rezept\RecipeOneShotService;
use Platform\FoodAlchemist\Services\Rezept\RecipeReviewService;
use Platform\FoodAlchemist\Services\Rezept\RecipeReviseService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.5 Schritt 6a, Rezept-Kern/KI — PR0: Ist-Stand der KI-Prompt-Kontexte eines Gerichts, bevor die Leser der
 * Spiegelfelder (sales_quantity_per_unit_g, sales_unit_count, sales_unit_vocab_id) auf die Standard-Darreichung umgestellt
 * werden. Jede Änderung am Prompt-Text wird hier sichtbar (Kurator 27.11.: Prompt-Änderungen melden, nicht still anpassen).
 *
 * Seit 6c (Drop der Rezept-Spiegelfelder) gibt es nur noch eine Lage: Die Prompt-Werte kommen aus der Standard-Darreichung
 * (250 g × 1 Portion). Die frühere Lage „abweichend“ (Spiegel 999 g, Portionszahl 3) ist entfallen; es gibt keinen Spiegel
 * mehr, der abweichen könnte. Sie hat in 6a gezeigt, dass die Leser die Darreichung lesen.
 * Erfasst: recipe.review-Kontext, Konformitäts-Prüfauftrag, vk.ueberarbeiten-Payload, recipe.steps-Kontext (OneShot und
 * StepEditor) und der Generator-Parameter ziel_portion_g der Gericht-Hülle.
 *
 * Neu schreiben nur mit Begründung: PROMPT_KONTEXT_GOLDEN_SCHREIBEN=1.
 */
const PROMPT_KONTEXT_GERICHT_GOLDEN = __DIR__.'/../Fixtures/golden/prompt_kontext_gericht.json';

function promptKontextGolden(string $schluessel, mixed $ist): void
{
    $ist = json_decode(json_encode($ist, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);
    $alle = is_file(PROMPT_KONTEXT_GERICHT_GOLDEN) ? json_decode((string) file_get_contents(PROMPT_KONTEXT_GERICHT_GOLDEN), true) : [];
    if (getenv('PROMPT_KONTEXT_GOLDEN_SCHREIBEN')) {
        $alle[$schluessel] = $ist;
        ksort($alle);
        file_put_contents(PROMPT_KONTEXT_GERICHT_GOLDEN, json_encode($alle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
        test()->markTestSkipped('Golden neu geschrieben: '.$schluessel);
    }
    expect($alle)->toHaveKey($schluessel);
    expect($ist)->toBe($alle[$schluessel]);
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $t = $this->rootTeam->id;
    $this->portion = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $t, 'slug' => 'portion'], ['display_de' => 'Portion', 'dimension' => 'count']);
    $teller = FoodAlchemistServierform::create(['team_id' => $t, 'code' => 'teller', 'label' => 'Teller']);

    // Gericht mit Komponente, Standard-Darreichung 250 g × 1 Portion (Rezept-Spiegelfelder gibt es seit 6c nicht mehr).
    $this->gericht = function (string $lage) use ($t, $teller) {
        $komponente = $this->makeRecipe($this->rootTeam, 'Rahmsauce '.$lage, ['preparation' => 'Sahne reduzieren.']);
        $g = $this->makeRecipe($this->rootTeam, '[HG] Schnitzel '.$lage, ['is_sales_recipe' => true, 'description' => 'Wiener Art']);
        $this->makeIngredient($g, 'Rahmsauce '.$lage, null, '80', 1)->update(['referenced_recipe_id' => $komponente->id]);
        FoodAlchemistRecipeDarreichung::create(['team_id' => $t, 'recipe_id' => $g->id, 'serving_form_id' => $teller->id, 'is_standard' => true,
            'quantity_per_unit_g' => 250, 'unit_count' => 1, 'unit_vocab_id' => $this->portion->id, 'ek_portion' => 3.0, 'sales_net' => 12.0, 'price_mode' => 'auto']);

        return FoodAlchemistRecipe::find($g->id);
    };
    $this->privat = fn (object $o, string $m, ...$args) => (fn () => $this->{$m}(...$args))->call($o);
});

foreach (['konsistent'] as $lage) {
    it("Prompt-Kontexte eines Gerichts ({$lage})", function () use ($lage) {
        $g = ($this->gericht)($lage);
        $review = ($this->privat)(app(RecipeReviewService::class), 'kontext', $g->fresh(), true);
        $schritteOneShot = ($this->privat)(app(RecipeOneShotService::class), 'stepKontext', $g->fresh());
        $editor = new StepEditor();
        $schritteEditor = ($this->privat)($editor, 'stepKontext', $g->fresh());
        $pruef = app(RecipeConformanceAdapter::class)->pruefauftrag($this->rootTeam, (int) $g->id);

        $revise = null;
        $this->mock(AiGatewayService::class, function ($m) use (&$revise) {
            $m->shouldReceive('propose')->andReturnUsing(function ($key, $payload) use (&$revise) {
                $revise = ['key' => $key, 'facetten' => $payload['facetten'] ?? $payload];

                return new AiProposal(['zutaten' => []], 0.5, 'Mock', [], 'vk');
            });
        });
        try {
            app(RecipeReviseService::class)->vkFreitextVorschlag($this->rootTeam, $g->fresh(), 'weniger Salz');
        } catch (\Throwable) {
            // nur der Payload zählt
        }

        promptKontextGolden("gericht.{$lage}", [
            'review' => array_intersect_key($review, array_flip(['portion_g', 'verkaufseinheiten', 'speisen_klasse', 'diaetform'])),
            'konformitaet' => array_intersect_key($pruef['kontext'] ?? $pruef, array_flip(['portion_g', 'verkaufseinheiten'])),
            'revise' => $revise,
            'schritte_oneshot' => $schritteOneShot['verkaufseinheit'] ?? null,
            'schritte_editor' => $schritteEditor['verkaufseinheit'] ?? null,
        ]);
    });

    it("Gericht-Hülle: ziel_portion_g an den Generator ({$lage})", function () use ($lage) {
        $g = ($this->gericht)($lage);
        \Illuminate\Support\Facades\DB::table('foodalchemist_recipes')->where('id', $g->id)->update(['status' => 'draft']);
        \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::where('recipe_id', $g->id)->delete();   // Hülle = ohne Komponenten
        $session = app(\Platform\FoodAlchemist\Services\Planung\PlanungsEinstiegService::class)->rezepturSessionFuer($this->rootTeam, $g->id);
        $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'running']);
        $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);

        $gesehen = null;
        $this->mock(RecipeGeneratorService::class, function ($m) use (&$gesehen, $g) {
            $m->shouldReceive('generiere')->once()->andReturnUsing(function ($team, $beschreibung, $parameter) use (&$gesehen, $g) {
                $gesehen = $parameter;

                return ['recipe' => $g->fresh(), 'statistik' => [], 'offene' => []];
            });
        });
        app(GerichtHuelleService::class)->generiere($this->rootTeam, (int) $step->id, 'Bauplan', [], [], fn () => null);

        promptKontextGolden("huelle.{$lage}", ['ziel_portion_g' => $gesehen['ziel_portion_g'] ?? null]);
    });
}
