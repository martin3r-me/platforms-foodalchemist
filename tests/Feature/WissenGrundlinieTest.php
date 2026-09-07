<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\RecipeKiKontextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 · A1 — die Grundlinie muss am tatsächlich versendeten Aufruf messen, und sie muss
 * ehrlich sagen, was sie NICHT weiss.
 *
 * Zwei Fallen, die dieser Bericht nicht wiederholen darf:
 *   · `dropped: 0` heisst ausserhalb der Generatoren „nicht gemessen", nicht „nichts verloren" —
 *     `knowledge_dropped_chars` geben nur 2 von 14 Aufrufern weiter (Befund I3).
 *   · eine fehlende Call-Log-Zeile heisst „nicht ans Rezept gehängt", nicht „kein Aufruf" —
 *     die Klammer ist heute `target_table`/`target_id`, nicht eine Lauf-ID.
 * Wer das verwischt, baut eine Grundlinie, die stärker klingt als sie ist.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    // `recipe_key` ist NOT NULL — im Bestand setzt es der Service, im Test muessen wir es
    // mitgeben (dieselbe Konvention wie SeedsTeamHierarchy::fixtureRezept).
    $this->mkRezept = function (string $name, bool $vk = false): FoodAlchemistRecipe {
        static $seq = 0;
        $seq++;

        return FoodAlchemistRecipe::create([
            'team_id' => (int) $this->rootTeam->id,
            'recipe_key' => 'grundlinie-'.$seq,
            'name' => $name,
            'is_sales_recipe' => $vk,
            'status' => 'draft',
            'created_via' => 'mcp',
        ]);
    };

    $this->mkCall = function (int $recipeId, string $feature, array $teile, array $kanaele = []): void {
        $daten = [
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'team_id' => (int) $this->rootTeam->id,
            'feature' => $feature,
            'model' => 'test-model',
            'tier' => 'B',
            'target_table' => 'foodalchemist_recipes',
            'target_id' => $recipeId,
            'knowledge_used' => json_encode(array_merge(...array_values($kanaele) ?: [[]])),
            'prompt_chars' => array_sum($teile),
            'prompt_parts' => json_encode($teile),
            'tokens_in' => 1000, 'tokens_out' => 100, 'tokens_cached' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_ai_call_log', 'knowledge_channels')) {
            $daten['knowledge_channels'] = json_encode($kanaele);
        }
        DB::table('foodalchemist_ai_call_log')->insert($daten);
    };
});

it('liefert alle Aufrufe eines Rezepts, nicht nur die Erstellung', function () {
    // Der Kontext-Inspektor filtert auf GENERATOR_FEATURES und blendet damit drei von vier
    // Aufrufen aus (Befund G6). Die Grundlinie muss die ganze Kette sehen.
    $r = ($this->mkRezept)('Püree: Karotte-Ingwer');
    ($this->mkCall)($r->id, 'recipe.generator', ['kanon' => 33000, 'retrieval' => 12000, 'task' => 900, 'dropped' => 0]);
    ($this->mkCall)($r->id, 'conformance.check', ['kanon' => 0, 'bound' => 0, 'retrieval' => 42000, 'task' => 600, 'dropped' => 0]);
    ($this->mkCall)($r->id, 'recipe.ueberarbeiten', ['kanon' => 0, 'bound' => 0, 'retrieval' => 8000, 'task' => 500, 'dropped' => 4000]);

    $calls = app(RecipeKiKontextService::class)->alleCallsFuerRezept($r->fresh());

    expect($calls)->toHaveCount(3)
        ->and(array_column($calls, 'feature'))
        ->toBe(['recipe.generator', 'conformance.check', 'recipe.ueberarbeiten']);
});

it('meldet einen Aufruf ohne Kanon und ohne Bound als "KEIN verbindliches Wissen"', function () {
    // Das ist der Zustand, den Befund B3/I5 vermutet — hier wird er zur Zahl statt zur These.
    $r = ($this->mkRezept)('Ohne Regelwerk');
    ($this->mkCall)($r->id, 'recipe.geschmack', ['kanon' => 0, 'bound' => 0, 'retrieval' => 0, 'task' => 400]);

    $this->artisan('foodalchemist:wissen-grundlinie', [
        '--team' => $this->rootTeam->id, '--recipes' => (string) $r->id,
    ])
        ->expectsOutputToContain('KEIN verbindliches Wissen')
        ->assertExitCode(0);
});

it('unterscheidet "nichts gekappt" von "Kappung nicht gemessen"', function () {
    // Befund I3: `knowledge_dropped_chars` geben nur RecipeGeneratorService und
    // RecipeOneShotService weiter. Bei `recipe.review` ist die 0 also eine Leerstelle.
    // Verwischt der Bericht das, behauptet er Sicherheit, die er nicht hat.
    $r = ($this->mkRezept)('Zwei Sorten Null');
    ($this->mkCall)($r->id, 'recipe.generator', ['kanon' => 30000, 'retrieval' => 5000, 'task' => 500, 'dropped' => 0]);
    ($this->mkCall)($r->id, 'recipe.review', ['kanon' => 0, 'bound' => 0, 'retrieval' => 9000, 'task' => 500, 'dropped' => 0]);

    $this->artisan('foodalchemist:wissen-grundlinie', [
        '--team' => $this->rootTeam->id, '--recipes' => (string) $r->id,
    ])
        ->expectsOutputToContain('dropped nicht gemessen')
        ->assertExitCode(0);
});

it('sagt bei fehlenden Aufrufen, dass das kein Beweis fuer "kein Aufruf" ist', function () {
    $r = ($this->mkRezept)('Ohne jeden Log');

    $this->artisan('foodalchemist:wissen-grundlinie', [
        '--team' => $this->rootTeam->id, '--recipes' => (string) $r->id,
    ])
        ->expectsOutputToContain('heisst NICHT, dass keiner lief')
        ->assertExitCode(0);
});

it('meldet eine echte Kappung als GEKAPPT', function () {
    $r = ($this->mkRezept)('Mit Kappung');
    ($this->mkCall)($r->id, 'recipe.generator', ['kanon' => 30000, 'retrieval' => 12000, 'task' => 500, 'dropped' => 4200]);

    $this->artisan('foodalchemist:wissen-grundlinie', [
        '--team' => $this->rootTeam->id, '--recipes' => (string) $r->id,
    ])
        ->expectsOutputToContain('GEKAPPT')
        ->expectsOutputToContain('1 mit Kappung')
        ->assertExitCode(0);
});

/**
 * Spec 52/B3 — der Wächter über die Messlücke.
 *
 * `knowledge_dropped_chars` gaben ZWEI von achtzehn Aufrufern weiter, und darum stand in
 * sechzehn Features `prompt_parts.dropped = 0`, obwohl Zeichen fehlten (Befund I3). Das war
 * keine Nachlässigkeit einzelner Stellen, sondern eine Konvention ohne Durchsetzung.
 *
 * ⚠ **Dieser Test scannt Quelltext und ist damit bewusst grob** — Marker statt Bedeutung. Er
 * fängt den Fall „neue Aufrufstelle vergisst das Messfeld", nicht jede Umformulierung. Mit
 * Spec 52/C2 (`propose()` baut den Kontext selbst) verschwinden Helfer und Test gemeinsam;
 * bis dahin ist ein grober Wächter besser als keiner.
 */
it('B3: die Aufrufstellen der Rezept-Kette geben knowledge_dropped_chars weiter', function () {
    $modul = dirname(__DIR__, 2);
    // Die Kette, die Etappe A1 messen muss: Draft, Anreicherung, Ueberarbeiten, Review,
    // Eigenschaften/Dichteklasse und Step-by-step.
    $kette = [
        'src/Services/RecipeGeneratorService.php',
        'src/Services/RecipeOneShotService.php',
        'src/Services/RecipeReviseService.php',
        'src/Services/RecipeReviewService.php',
        'src/Services/BulkEnrichService.php',
        'src/Livewire/Recipes/RecipeModal.php',
        'src/Livewire/Recipes/StepEditor.php',
    ];

    $ohneMessung = [];
    foreach ($kette as $rel) {
        $quelle = (string) file_get_contents($modul.'/'.$rel);
        if (! str_contains($quelle, "'knowledge' =>") && ! str_contains($quelle, 'proposeOptionen')) {
            continue; // baut keine Wissens-Optionen
        }
        // Entweder ueber den Helfer (nimmt das Feld mit) oder ausdruecklich selbst.
        if (! str_contains($quelle, 'proposeOptionen') && ! str_contains($quelle, 'knowledge_dropped_chars')) {
            $ohneMessung[] = $rel;
        }
    }

    expect($ohneMessung)->toBe([], 'Diese Aufrufstellen der Rezept-Kette messen die Kappung nicht: '
        .implode(', ', $ohneMessung).' — entweder KnowledgeContextService::proposeOptionen() '
        .'benutzen oder knowledge_dropped_chars ausdruecklich setzen.');
});

it('B3: der Helfer nimmt die Kappung mit und laesst knowledge_channels bewusst aus', function () {
    // Das Channels-Feld ist der Dedup-/Anzeige-Eingang, an dem schon einmal der Bound-Kanal
    // gestorben ist (W0-3b). Der Helfer darf es NICHT stillschweigend setzen.
    $opts = \Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::proposeOptionen([
        'block' => 'Regeltext', 'files_used' => ['a@v1'], 'dropped_chars' => 4200,
        'used_by_category' => ['regelwerk' => ['a@v1']],
    ]);

    expect($opts['knowledge'])->toBe('Regeltext')
        ->and($opts['knowledge_used'])->toBe(['a@v1'])
        ->and($opts['knowledge_dropped_chars'])->toBe(4200)
        ->and($opts)->not->toHaveKey('knowledge_channels');
});

it('B3: ein leerer Block wird zu null, damit der Gateway nichts Leeres anhaengt', function () {
    $opts = \Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::proposeOptionen([
        'block' => '', 'files_used' => [], 'dropped_chars' => 0,
    ]);

    expect($opts['knowledge'])->toBeNull()
        ->and($opts['knowledge_dropped_chars'])->toBe(0);
});
