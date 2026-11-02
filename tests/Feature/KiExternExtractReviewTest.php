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
 * #1002 · recipes.EXTRACT extern mit Kritiker (recipe.review): Der Kritiker läuft auf vorläufig angelegten Rezepten, deren
 * IDs der Rollback der Tool-Transaktion wieder verwirft. Trug der Review-Prompt die Zutaten-IDs, bekam jede Wiederholung
 * einen neuen messages_hash → neuer Auftrag, die Antwort wurde nie übernommen (Endlosschleife). Der Prompt nummeriert die
 * Zutaten jetzt nach Position; die Antwort wird auf die echten Zeilen zurückgemappt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true,
        'foodalchemist.import.stubs_ausrezeptieren_max' => 0]);
    $this->registry = app(ToolRegistry::class);
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    foreach ([['slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1], ['slug' => 'ml', 'display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_ml' => 1]] as $e) {
        FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, ...$e]);
    }
    // Der „Client“: beantwortet recipe.extract mit einem verschachtelten Rezept und recipe.review mit einem Befund auf die erste Zutat (Kontext-id).
    $this->client = new class extends FakeAiProvider
    {
        public array $features = [];

        public function antwort(string $feature, array $messages): array
        {
            $this->features[] = $feature;
            if ($feature === 'recipe.review') {
                $text = (string) end($messages)['content'];
                $kontext = json_decode(mb_substr($text, mb_strpos($text, "\n\nKontext:\n") + 11), true);
                $erste = $kontext['zutaten'][0];

                return ['befunde' => [['art' => 'menge', 'zutat_id' => $erste['id'], 'zutat_text' => $erste['text'], 'quantity' => 350,
                    'begruendung' => 'Etwas mehr für die Bindung.', 'konfidenz' => 0.8]], 'gesamturteil' => 'solide'];
            }

            return [
                'typ' => 'basisrezept', 'name' => 'Rotweinjus mit Reduktion', 'preparation' => '1. Reduktion ansetzen. 2. Fond angießen.',
                'zutaten' => [['text' => 'Schalotten', 'quantity' => 300, 'unit' => 'g'], ['text' => 'Rotweinreduktion', 'quantity' => 200, 'unit' => 'ml']],
                'komponenten' => [[
                    'name' => 'Rotweinreduktion', 'preparation' => 'Rotwein auf ein Drittel reduzieren.',
                    'zutaten' => [['text' => 'Rotwein', 'quantity' => 600, 'unit' => 'ml'], ['text' => 'Thymian', 'quantity' => 5, 'unit' => 'g']],
                ]],
            ];
        }

        public function chat(array $messages, array $options = []): array
        {
            throw new \RuntimeException('Externer Weg: der Plattform-Provider darf nicht gerufen werden.');
        }
    };
    app()->instance(LLMProviderContract::class, $this->client);
    $this->roh = "Rotweinjus\n300 g Schalotten\n200 ml Rotweinreduktion\n\nFür die Rotweinreduktion: 600 ml Rotwein, 5 g Thymian, auf ein Drittel reduzieren.";
});

it('recipes.EXTRACT extern mit Kritiker: Review-Antwort wird übernommen, genau ein Entwurf samt Unterrezept, keine Waisen, keine neuen Aufträge', function () {
    $extract = fn () => $this->registry->get('foodalchemist.recipes.EXTRACT')->execute(['raw_text' => $this->roh], $this->mcp);
    $beantworte = function () {
        $offen = $this->registry->get('foodalchemist.ki_auftraege.GET')->execute([], $this->mcp)->data['auftraege'];
        $antworten = array_map(fn ($a) => ['auftrag_id' => $a['auftrag_id'], 'werte' => $this->client->antwort($a['feature'], $a['messages'])], $offen);
        $r = $this->registry->get('foodalchemist.ki_auftraege.ANTWORT')->execute(['anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5', 'antworten' => $antworten], $this->mcp);
        expect($r->success)->toBeTrue($r->error ?? '');

        return array_column($offen, 'feature');
    };
    // MySQL rollt Auto-Increment-Zähler beim Rollback NICHT zurück: die Wiederholung bekommt neue Rezept-/Zutaten-IDs. SQLite
    // (AUTOINCREMENT über sqlite_sequence) setzt sie mit zurück — hier den MySQL-Fall nachstellen, sonst bliebe der Fehler unsichtbar.
    $idsWandern = function () {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }
        foreach (['foodalchemist_recipes', 'foodalchemist_recipe_ingredients'] as $t) {
            $seq = (int) (DB::table('sqlite_sequence')->where('name', $t)->value('seq') ?? 0);
            DB::table('sqlite_sequence')->updateOrInsert(['name' => $t], ['seq' => $seq + 100]);
        }
    };

    // 1. EXTRACT → recipe.extract
    $r1 = $extract();
    expect($r1->data['status'] ?? null)->toBe('ki_auftrag_offen')->and($r1->data['auftraege'][0]['feature'])->toBe('recipe.extract');
    expect($beantworte())->toBe(['recipe.extract']);
    $idsWandern();

    // 2. EXTRACT → Extraktion übernommen, Kritiker läuft auf dem verdrahteten Unterrezept → recipe.review, alles zurückgerollt
    $r2 = $extract();
    expect($r2->data['status'] ?? null)->toBe('ki_auftrag_offen')
        ->and(array_column($r2->data['auftraege'], 'feature'))->toBe(['recipe.review'])
        ->and(FoodAlchemistRecipe::ohneTeamSicht('test')->count())->toBe(0);
    expect($beantworte())->toBe(['recipe.review']);
    $idsWandern();

    // 3. EXTRACT → Review-Antwort greift, der Entwurf entsteht
    $r3 = $extract();
    expect($r3->success)->toBeTrue($r3->error ?? '')
        ->and($r3->data['status'] ?? null)->not->toBe('ki_auftrag_offen')
        ->and($r3->data['statistik']['kritiker']['geprueft'] ?? null)->toBeTrue();

    $alle = FoodAlchemistRecipe::ohneTeamSicht('test')->get();
    $eltern = $alle->firstWhere('id', $r3->data['recipe']['id']);
    expect($alle)->toHaveCount(2)                                                // Eltern + genau ein Unterrezept, keine Waisen
        ->and($eltern->name)->toBe('Rotweinjus mit Reduktion')
        ->and(array_column($r3->data['sub_recipes'], 'name'))->toBe(['Rotweinreduktion']);
    $sub = (int) $r3->data['sub_recipes'][0]['id'];
    expect(FoodAlchemistRecipeIngredient::where('recipe_id', $eltern->id)->where('referenced_recipe_id', $sub)->exists())->toBeTrue()
        ->and(FoodAlchemistRecipeIngredient::whereNotIn('recipe_id', $alle->pluck('id'))->count())->toBe(0);

    // keine neuen offenen extract-/review-Aufträge; extract + review je genau einmal angelegt und verbraucht. Offen bleiben nur die
    // Sensorik-Folgeaufträge der Folge-Jobs (erben extern, laufen nach dem Commit) — und die zeigen auf die beiden echten Rezepte.
    $offen = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->get();
    expect($offen->pluck('feature')->unique()->values()->all())->toBe(['recipe.sensorik'])
        ->and($offen->pluck('target_id')->map(fn ($id) => (int) $id)->sort()->values()->all())->toBe($alle->pluck('id')->sort()->values()->all())
        ->and(DB::table('foodalchemist_ki_auftraege')->where('feature', 'recipe.extract')->pluck('status')->all())->toBe(['verbraucht'])
        ->and(DB::table('foodalchemist_ki_auftraege')->where('feature', 'recipe.review')->count())->toBe(1)
        ->and(DB::table('foodalchemist_ki_auftraege')->where('feature', 'recipe.review')->value('status'))->toBe('verbraucht');

    // Rückmapping: der Befund hängt an der echten ersten Zutat des Entwurfs (Prompt-Nr. 1 → Zeilen-ID)
    $ersteZeile = FoodAlchemistRecipeIngredient::where('recipe_id', $eltern->id)->orderBy('position')->first();
    $befund = DB::table('foodalchemist_recipe_findings')->where('recipe_id', $eltern->id)->where('kind', 'menge')->first();
    expect($befund)->not->toBeNull()->and((int) $befund->ingredient_id)->toBe((int) $ersteZeile->id);
});
