<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\DetailPanel as RezeptDetailPanel;
use Platform\FoodAlchemist\Livewire\Verkauf\DetailPanel as GerichtDetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Ai\RecipeKiKontextService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * KI-Kontext am Rezept (2026-09-06): „In der UI sieht man das Wissen nie komplett."
 *
 * Drei Glieder, drei Beweise:
 *  1. Gateway persistiert die Wissens-KANÄLE (`knowledge_channels`) — Kanon/gebunden setzt er
 *     selbst, der Aufrufer darf sie nicht behaupten (Etikett lügt sonst).
 *  2. Generator hängt seinen Call ans erzeugte Rezept (`target_table/target_id`) — vorher leer,
 *     weil das Rezept erst nach dem Call entsteht.
 *  3. Rezept- UND Gericht-Detail rendern die Sektion „KI-Kontext" aus dem Call-Log; ohne Call
 *     bleibt sie aus (kein leeres Panel, keine erfundenen Nullen).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);

    $this->mkDoc = function (string $slug, int $chars = 300): void {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => (int) $this->rootTeam->id, 'slug' => $slug,
            'title' => 'Titel '.$slug, 'category' => 'regelwerk', 'content_md' => str_repeat('Regel ', (int) ($chars / 6)),
            'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => $chars,
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    // Fake-Provider, der ein strukturell brauchbares Rezept liefert (der Standard-Fake ist ein Kontext-Echo).
    $this->fakeRezept = function (): void {
        app()->singleton(FakeAiProvider::class, fn () => new class extends FakeAiProvider
        {
            public function chat(array $messages, array $options = []): array
            {
                return ['content' => json_encode(['werte' => [
                    'name' => 'Reduktion: Rotwein-Schalotte',
                    'zutaten' => [['text' => 'Schalotten', 'slug' => 'schalotten', 'quantity' => 200, 'unit' => 'g', 'garverlust_pct' => 20]],
                ], 'confidence' => 0.9]), 'model' => 'fake-1', 'usage' => ['input_tokens' => 1234, 'output_tokens' => 56]];
            }
        });
    };
});

it('Gateway: persistiert Wissens-Kanäle — Kanon aus eigener Wahrheit, nicht aus der Behauptung des Aufrufers', function () {
    ($this->mkDoc)('kk-kanon-a');
    app(KnowledgeCanonService::class)->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'recipe.description', 'slug' => 'kk-kanon-a', 'ord' => 10, 'mode' => 'pflicht']);

    app(AiGatewayService::class)->propose('recipe.description', ['description' => 'Fond.'], [
        'knowledge' => 'x',
        'knowledge_used' => ['dom-a@v1', 'pair-b@v2'],
        'knowledge_channels' => ['domain' => ['dom-a@v1'], 'pairing' => ['pair-b@v2'], 'kanon' => ['ERFUNDEN@v9'], 'leer' => []],
    ]);

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'recipe.description')->latest('id')->first();
    $kanaele = json_decode((string) $log->knowledge_channels, true);
    expect($kanaele)->toBe(['kanon' => ['kk-kanon-a@v1'], 'domain' => ['dom-a@v1'], 'pairing' => ['pair-b@v2']])
        // Die flache Audit-Liste bleibt, wie sie war (Vertrag GL-13 §6).
        ->and(json_decode((string) $log->knowledge_used, true))->toContain('dom-a@v1')->toContain('kk-kanon-a@v1')->not->toContain('ERFUNDEN@v9');
});

it('Gateway: ohne Kanal-Angabe fällt der Retrieval-Anteil auf einen Kanal „retrieval" — nie auf einen erfundenen Kanon', function () {
    app(AiGatewayService::class)->propose('recipe.description', ['description' => 'Fond.'], ['knowledge' => 'x', 'knowledge_used' => ['dom-a@v1']]);

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'recipe.description')->latest('id')->first();
    expect(json_decode((string) $log->knowledge_channels, true))->toBe(['retrieval' => ['dom-a@v1']]);
});

it('Generator: hängt den Call ans Rezept und das Detail-Panel zeigt den KI-Kontext', function () {
    ($this->fakeRezept)();
    ($this->mkDoc)('kk-gen-kanon');
    app(KnowledgeCanonService::class)->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'recipe.generator', 'slug' => 'kk-gen-kanon', 'ord' => 10, 'mode' => 'pflicht']);

    $resultat = app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'Rotwein-Schalotten-Reduktion', ['convenience' => 'from_scratch']);
    $rezept = $resultat['recipe']->refresh();

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'recipe.generator')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->target_table)->toBe('foodalchemist_recipes')
        ->and((int) $log->target_id)->toBe($rezept->id);

    $kk = app(RecipeKiKontextService::class)->fuerRezept($rezept);
    expect($kk)->not->toBeNull()
        ->and($kk['meta']['feature'])->toBe('recipe.generator')
        ->and($kk['meta']['model'])->toBe('fake-1')
        ->and($kk['meta']['tokens_in'])->toBe(1234)
        ->and($kk['kontext']['wissen']['kanon'] ?? null)->toBe(['kk-gen-kanon@v1'])
        ->and($kk['kontext']['prompt']['kanon'] ?? 0)->toBeGreaterThan(0);

    Livewire::test(RezeptDetailPanel::class, ['recipeId' => $rezept->id])
        ->assertSee('KI-Kontext')
        ->assertSee('Basisrezept-Generator')
        ->assertSee('kk-gen-kanon')
        ->assertSee('Kanon (verbindlich)')
        ->assertSee('fake-1');
});

it('Detail-Panels ohne Generator-Call zeigen KEINE KI-Kontext-Sektion (Rezept + Gericht)', function () {
    $basis = $this->makeRecipe($this->rootTeam, 'Handgeschrieben');
    $gericht = $this->makeRecipe($this->rootTeam, 'Handgeschriebenes Gericht', ['is_sales_recipe' => true]);

    Livewire::test(RezeptDetailPanel::class, ['recipeId' => $basis->id])->assertSee('Handgeschrieben')->assertDontSee('KI-Kontext');
    Livewire::test(GerichtDetailPanel::class, ['recipeId' => $gericht->id])->assertSee('Handgeschriebenes Gericht')->assertDontSee('KI-Kontext');
});

it('Gericht: Alt-Zeile ohne Kanäle wird ehrlich als ein Kanal „Wissen" gezeigt — kein erfundener Kanon', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Altes KI-Gericht', ['is_sales_recipe' => true]);
    DB::table('foodalchemist_ai_call_log')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id, 'feature' => 'vk.generator', 'tier' => 'A', 'model' => 'alt-modell',
        'knowledge_used' => json_encode(['alt-dossier@v3']), 'target_table' => 'foodalchemist_recipes', 'target_id' => $gericht->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $kk = app(RecipeKiKontextService::class)->fuerRezept($gericht);
    expect($kk['kontext']['wissen'])->toBe(['wissen' => ['alt-dossier@v3']])
        ->and($kk['kontext']['prompt'])->toBeNull();   // Sonde hat nichts → keine Nullen

    Livewire::test(GerichtDetailPanel::class, ['recipeId' => $gericht->id])
        ->assertSee('KI-Kontext')->assertSee('Gericht-Generator')->assertSee('alt-dossier')->assertDontSee('Kanon (verbindlich)');
});
