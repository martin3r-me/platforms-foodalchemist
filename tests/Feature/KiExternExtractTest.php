<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002 · Paket 2d-1: recipes.EXTRACT (Textweg) per MCP gleichwertig — ein propose() (recipe.extract), danach dieselbe Anlage wie auf der
 * Plattform. Bild/PDF gehen nur über die Oberfläche (Dateien) und bleiben Plattform.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    $this->registry = app(ToolRegistry::class);
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $this->intern = new ToolContext($this->user, $this->rootTeam);
    foreach ([['slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1], ['slug' => 'ml', 'display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_ml' => 1]] as $e) {
        FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, ...$e]);
    }
    $this->stub = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;

            return ['content' => json_encode(['werte' => [
                'typ' => 'basisrezept', 'name' => 'Rotweinjus', 'preparation' => '1. Schalotten anschwitzen. 2. Mit Rotwein ablöschen und reduzieren.',
                'zutaten' => [['text' => 'Schalotten', 'quantity' => 300, 'unit' => 'g'], ['text' => 'Rotwein', 'quantity' => 200, 'unit' => 'ml']],
            ], 'confidence' => 0.9, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE), 'usage' => [], 'model' => 'stub-1', 'tool_calls' => null];
        }
    };
    app()->instance(LLMProviderContract::class, $this->stub);
    $this->roh = "Rotweinjus\n300 g Schalotten\n200 ml Rotwein\nSchalotten anschwitzen, mit Rotwein ablöschen, reduzieren.";
    $this->extract = fn (ToolContext $k, array $extra = []) => $this->registry->get('foodalchemist.recipes.EXTRACT')->execute(['raw_text' => $this->roh] + $extra, $k);
    $this->loese = function (array $extra = []) {
        for ($i = 0; $i < 6; $i++) {
            $r = ($this->extract)($this->mcp, $extra);
            expect($r->success)->toBeTrue($r->error ?? '');
            if (($r->data['status'] ?? null) !== 'ki_auftrag_offen') {
                return [$r, $i + 1];
            }
            $offen = $this->registry->get('foodalchemist.ki_auftraege.GET')->execute([], $this->mcp)->data['auftraege'];
            $antworten = array_map(fn ($a) => ['auftrag_id' => $a['auftrag_id'], 'werte' => json_decode($this->stub->chat($a['messages'], [])['content'], true)], $offen);
            $this->registry->get('foodalchemist.ki_auftraege.ANTWORT')->execute(['anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5', 'antworten' => $antworten], $this->mcp);
        }
        throw new \RuntimeException('nicht fertig');
    };
});

it('Vergleichstest recipes.EXTRACT dry_run: derselbe Extrakt, Auftrag recipe.extract, nichts angelegt', function () {
    $rp = ($this->extract)($this->intern, ['dry_run' => true]);
    expect($rp->success)->toBeTrue($rp->error ?? '')->and($this->stub->calls)->toBe(1)->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);

    $erst = ($this->extract)($this->mcp, ['dry_run' => true]);
    expect($erst->data['status'])->toBe('ki_auftrag_offen')->and($erst->data['auftraege'][0]['feature'])->toBe('recipe.extract')->and($this->stub->calls)->toBe(1);

    [$rm, $runden] = ($this->loese)(['dry_run' => true]);
    expect($rm->data['extrakt'])->toBe($rp->data['extrakt'])->and($runden)->toBe(2)
        ->and(FoodAlchemistRecipe::ohneTeamSicht('test')->count())->toBe(0)
        ->and(json_decode(DB::table('foodalchemist_ai_call_log')->whereNull('error')->orderByDesc('id')->value('prompt_parts'), true)['quelle'])->toBe('mcp');
});

it('Vergleichstest recipes.EXTRACT Anlage: Plattformweg und MCP-Weg legen denselben Entwurf an', function () {
    $rp = ($this->extract)($this->intern);
    expect($rp->success)->toBeTrue($rp->error ?? '');
    $form = fn (int $id) => ['status' => $rp->data['recipe']['status'] ?? null, 'zutaten' => FoodAlchemistRecipeIngredient::where('recipe_id', $id)->orderBy('id')->get()->map(fn ($z) => [$z->text ?? $z->name ?? null, (float) $z->quantity])->all(),
        'preparation' => FoodAlchemistRecipe::ohneTeamSicht('test')->find($id)->preparation];

    FoodAlchemistRecipe::whereKey($rp->data['recipe']['id'])->update(['name' => 'Plattform-Original']);
    [$rm] = ($this->loese)();

    expect($rm->data['recipe']['id'])->not->toBe($rp->data['recipe']['id'])
        ->and($rm->data['recipe']['created_via'])->toBe($rp->data['recipe']['created_via'])->and($rm->data['recipe']['status'])->toBe($rp->data['recipe']['status'])
        ->and($rm->data['recipe']['n_ingredients_total'])->toBe($rp->data['recipe']['n_ingredients_total'])
        ->and(array_column($rm->data['offene'], 'text'))->toBe(array_column($rp->data['offene'], 'text'))   // die Statistik trägt Zutaten-IDs und ist darum nicht vergleichbar
        ->and($form($rm->data['recipe']['id'])['zutaten'])->toBe($form($rp->data['recipe']['id'])['zutaten'])
        ->and($form($rm->data['recipe']['id'])['preparation'])->toBe($form($rp->data['recipe']['id'])['preparation']);
});

it('Schalter aus: recipes.EXTRACT läuft über den Plattform-Provider, kein Auftrag', function () {
    config(['foodalchemist.ai.mcp_extern' => false]);
    $r = ($this->extract)($this->mcp, ['dry_run' => true]);

    expect($r->success)->toBeTrue($r->error ?? '')->and($this->stub->calls)->toBe(1)->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
});
