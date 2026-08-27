<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConformanceService;
use Platform\FoodAlchemist\Tests\Support\CopilotStub;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Schicht 3 · Slice 1 — der generische Konformitäts-Critic ({@see ConformanceService}).
 *
 * Beweist die Verdrahtung mit {@see CopilotStub} (kanonische Befunde statt echtem LLM):
 *  1. die VOLLEN Regelwerk-Dossiers landen im Prompt (Wissen injiziert, ungekappt),
 *  2. Roh-Befunde werden zu normierten Regelverstößen (paragraph/schweregrad/feld/begründung),
 *  3. Rauschen (Befund ohne Begründung) fällt raus,
 *  4. unbekannter Schweregrad → 'weich' (Default = nur Hinweis, nie Block).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    // Ein aktives Regelwerk-Dossier, gegen das geprüft wird — der Marker beweist
    // später, dass sein VOLLER Text im Prompt steht.
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'team_id' => $this->rootTeam->id,
        'slug' => 'regelwerk-basisrezepte-6-mengen-einheiten-yield',
        'title' => 'Regelwerk Basisrezepte §6 — Mengen, Einheiten & Yield',
        'category' => 'regelwerk',
        'content_md' => 'SCHICHT3_REGELWERK_MARKER §6.1: Produktnamen im Singular/Lemma.',
        'version' => 1,
        'content_hash' => str_repeat('a', 64),
        'char_count' => 60,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id,
        'recipe_key' => 'bx-schicht3',
        'name' => 'Tomaten Gewürfelt',
        'status' => 'draft',
        'is_sales_recipe' => false,
    ]);
});

it('Slice 1: Regelwerk landet im Prompt + Roh-Befunde werden zu normierten Regelverstößen', function () {
    CopilotStub::bind([
        ['paragraph' => '§6.1', 'schweregrad' => 'hart', 'feld' => 'name',
            'begruendung' => 'Plural statt Singular', 'vorschlag' => 'Tomate: gewürfelt', 'konfidenz' => 0.9],
        ['paragraph' => '§8', 'schweregrad' => 'quatsch', 'feld' => 'kategorie',
            'begruendung' => 'Pflichtangabe fehlt', 'konfidenz' => 'hoch'],          // unbekannter Schweregrad → weich
        ['paragraph' => '§3', 'schweregrad' => 'weich', 'feld' => 'x', 'begruendung' => ''], // Rauschen → raus
    ], 'Zwei Verstöße gefunden.');

    $ergebnis = app(ConformanceService::class)->pruefe($this->rootTeam, 'basisrezept', $this->rezept->id);

    // (1) Volles Regelwerk im Prompt
    expect($GLOBALS['l6_user_prompt'] ?? '')->toContain('SCHICHT3_REGELWERK_MARKER');

    // (2)+(3) zwei valide Befunde (der begründungslose fiel raus)
    expect($ergebnis['befunde'])->toHaveCount(2);
    expect($ergebnis['befunde'][0]['paragraph'])->toBe('§6.1');
    expect($ergebnis['befunde'][0]['schweregrad'])->toBe('hart');
    expect($ergebnis['befunde'][0]['feld'])->toBe('name');
    expect($ergebnis['befunde'][0]['vorschlag'])->toBe('Tomate: gewürfelt');

    // (4) unbekannter Schweregrad wird zu 'weich' entschärft
    expect($ergebnis['befunde'][1]['schweregrad'])->toBe('weich');
    expect($ergebnis['befunde'][1]['konfidenz'])->toBe(0.9);        // 'hoch' → 0.9

    expect($ergebnis['gesamturteil'])->toBe('Zwei Verstöße gefunden.');
});

it('Slice 1: ohne aktives Regelwerk-Dossier wirft die Prüfung (keine Blind-Prüfung)', function () {
    DB::table('foodalchemist_knowledge_documents')->update(['active' => 0]);
    CopilotStub::bind([]);

    expect(fn () => app(ConformanceService::class)->pruefe($this->rootTeam, 'basisrezept', $this->rezept->id))
        ->toThrow(RuntimeException::class);
});

it('Slice 1: unbekannter Artefakt-Typ wirft', function () {
    expect(fn () => app(ConformanceService::class)->pruefe($this->rootTeam, 'grundprodukt', 1))
        ->toThrow(InvalidArgumentException::class);
});
