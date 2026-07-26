<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 03·L6: MCP `foodalchemist.recipes.REVIEW` — read-only Prüfpass.
 *
 * Die Fach-Logik hängt in RecipeReviewServiceTest; hier zählt die Fläche:
 * Registry-Eintrag, read-only-Metadaten (das Tool darf NICHTS schreiben) und
 * Tenancy — ein fremdes Rezept ist nicht prüfbar, sonst wäre der Prüfpass ein
 * Leseleck über die Team-Grenze (#504-Muster).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => []]);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);

    $g = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ]);
    $this->recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'l6-mcp', 'name' => 'Kartoffelpüree', 'status' => 'draft',
    ]);
    DB::table('foodalchemist_recipe_ingredients')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->recipe->id, 'gp_id' => $this->makeGp($this->rootTeam, 'Butter')->id,
        'raw_text' => 'Butter', 'display_name' => 'Butter', 'quantity' => 80, 'unit_vocab_id' => $g->id,
        'position' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->zeileId = (int) DB::getPdo()->lastInsertId();

    app()->bind(LLMProviderContract::class, fn () => new class implements LLMProviderContract
    {
        public function getName(): string
        {
            return 'test-stub';
        }

        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => ['gesamturteil' => 'Mager gewürzt.', 'befunde' => [
                ['art' => 'fehlt', 'zutat_text' => 'Yuzu-Kosho aus Kyushu', 'quantity' => 5, 'einheit_slug' => 'g',
                    'begruendung' => 'Bringt Spannung.', 'konfidenz' => 0.5],
                ['art' => 'hinweis', 'begruendung' => 'Butter erst nach dem Stampfen einrühren.', 'konfidenz' => 0.8],
            ]], 'confidence' => 0.7, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void
        {
            $onDelta($this->chat($messages, $options)['content']);
        }

        public function getAvailableModels(): array
        {
            return ['stub'];
        }

        public function getDefaultModel(): string
        {
            return 'stub';
        }

        public function isAvailable(): bool
        {
            return true;
        }
    });
});

it('L6: registriert als foodalchemist.recipes.REVIEW und ist als read-only deklariert', function () {
    $tool = $this->registry->get('foodalchemist.recipes.REVIEW');

    expect($tool)->not->toBeNull()
        ->and($tool->getSchema()['required'])->toBe(['recipe_id'])
        ->and($tool->getMetadata()['read_only'])->toBeTrue()
        ->and($tool->getMetadata()['risk_level'])->toBe('read')
        ->and($tool->getMetadata()['side_effects'])->toBe([]);
});

it('L6: liefert geprüfte Befunde und ändert dabei nichts am Rezept', function () {
    $vorher = DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $this->recipe->id)->get();

    $res = $this->registry->get('foodalchemist.recipes.REVIEW')
        ->execute(['recipe_id' => $this->recipe->id], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['gesamturteil'])->toBe('Mager gewürzt.')
        ->and($res->data['zusammenfassung'])->toBe(['gesamt' => 2, 'anwendbar' => 0, 'hardstop' => 1])
        ->and($res->data['befunde'][0]['status'])->toBe('kein_treffer')   // Hard-Stop statt Raten
        ->and($res->data['befunde'][0]['primaer'])->not->toBeNull()
        ->and($res->data['hinweis'])->toContain('NICHT geraten');

    expect(DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $this->recipe->id)->get()->toArray())
        ->toEqual($vorher->toArray());
});

it('#504-Muster: Tenancy — ein Rezept aus einem fremden Zweig ist nicht prüfbar', function () {
    $res = $this->registry->get('foodalchemist.recipes.REVIEW')
        ->execute(['recipe_id' => $this->recipe->id],
            new ToolContext($this->makeUser($this->childB, 'Kind B User'), $this->childB));

    // childB sieht die Kette nach OBEN (root) — die Gegenprobe ist ein Rezept in childA.
    expect($res->success)->toBeTrue();

    $fremd = FoodAlchemistRecipe::create([
        'team_id' => $this->childA->id, 'recipe_key' => 'l6-fremd', 'name' => 'Fremd', 'status' => 'draft',
    ]);
    $res2 = $this->registry->get('foodalchemist.recipes.REVIEW')
        ->execute(['recipe_id' => $fremd->id],
            new ToolContext($this->makeUser($this->childB, 'Kind B User 2'), $this->childB));

    expect($res2->success)->toBeFalse()->and($res2->errorCode)->toBe('NOT_FOUND');
});

it('#504-Muster: ohne Team im Kontext kein Prüfpass', function () {
    $ohneTeam = \Platform\Core\Models\User::forceCreate([
        'name' => 'Teamlos', 'email' => 'teamlos-l6@test.local', 'password' => bcrypt('secret'), 'current_team_id' => null,
    ]);
    $res = $this->registry->get('foodalchemist.recipes.REVIEW')
        ->execute(['recipe_id' => $this->recipe->id], new ToolContext($ohneTeam, null));

    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NO_TEAM');
});
