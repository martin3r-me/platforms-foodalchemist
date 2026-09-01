<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistConformanceFinding;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConformanceService;
use Platform\FoodAlchemist\Tests\Support\ConformanceHealStub;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Schicht 3 · Slice 2 — Selbstheil-Loop + Ablage ({@see ConformanceService::pruefeUndHeile},
 * {@see ConformanceService::speichere}).
 *
 * Der Loop-Ausgang hängt am {@see ConformanceHealStub}-Zähler (conformance.check 1./2. Call),
 * die Persistenz wird direkt über speichere() deterministisch geprüft (ohne LLM).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'team_id' => $this->rootTeam->id,
        'slug' => 'regelwerk-basisrezepte-6-mengen-einheiten-yield',
        'title' => 'Regelwerk Basisrezepte §6',
        'category' => 'regelwerk',
        'content_md' => 'REGELWERK §6.1 Produktnamen im Singular.',
        'version' => 1,
        'content_hash' => str_repeat('b', 64),
        'char_count' => 40,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id,
        'recipe_key' => 'bx-heal',
        'name' => 'Tomaten Gewürfelt',
        'status' => 'draft',
        'is_sales_recipe' => false,
    ]);
});

$befund = fn (array $o = []) => array_merge([
    'paragraph' => '§6.1',
    'schweregrad' => 'hart',
    'feld' => 'name',
    'begruendung' => 'Plural statt Singular',
    'vorschlag' => 'Tomate: gewürfelt',
    'konfidenz' => 0.9,
], $o);

it('Selbstheil-Loop: Verstoß → Revise-Runde → sauber → nichts persistiert, geheilt=1', function () use ($befund) {
    ConformanceHealStub::bind([[$befund()], []]);   // Call 1: Verstoß · Call 2: sauber

    $erg = app(ConformanceService::class)->pruefeUndHeile($this->rootTeam, 'basisrezept', $this->rezept->id);

    expect($erg['befunde'])->toBe([]);
    expect($erg['geheilt'])->toBe(1);
    expect($erg['ablage']['neu'])->toBe(0);
    expect(FoodAlchemistConformanceFinding::where('artifact_id', $this->rezept->id)->count())->toBe(0);
});

it('Selbstheil-Loop übernimmt den kontrollierten Naming-Vorschlag auch wenn der freie Revise ihn auslässt', function () use ($befund) {
    ConformanceHealStub::bind([[$befund()], []], []);

    app(ConformanceService::class)->pruefeUndHeile($this->rootTeam, 'basisrezept', $this->rezept->id);

    expect($this->rezept->fresh()->name)->toBe('Tomate: gewürfelt');
});

it('Selbstheil-Loop: Verstoß bleibt nach Runde → als Hinweis persistiert (kein Block)', function () use ($befund) {
    ConformanceHealStub::bind([[$befund()], [$befund()]]);   // Revise half nicht

    $erg = app(ConformanceService::class)->pruefeUndHeile($this->rootTeam, 'basisrezept', $this->rezept->id);

    expect($erg['befunde'])->toHaveCount(1);
    expect($erg['geheilt'])->toBe(0);
    expect($erg['ablage']['neu'])->toBe(1);

    $row = FoodAlchemistConformanceFinding::where('artifact_id', $this->rezept->id)->first();
    expect($row->status)->toBe('offen');
    expect($row->schweregrad)->toBe('hart');
    expect($row->paragraph)->toBe('§6.1');
    expect($row->artifact_type)->toBe('recipe');
});

it('ConformanceCheckJob: fährt die Selbstheil-Prüfung best-effort und legt Hinweise ab (offeneFuer)', function () use ($befund) {
    ConformanceHealStub::bind([[$befund()], [$befund()]]);   // Verstoß bleibt → persistiert

    (new \Platform\FoodAlchemist\Jobs\ConformanceCheckJob(
        $this->rootTeam->id, (int) auth()->id(), 'basisrezept', $this->rezept->id,
    ))->handle(app(ConformanceService::class));

    $offen = app(ConformanceService::class)->offeneFuer($this->rootTeam, 'recipe', $this->rezept->id);
    expect($offen)->toHaveCount(1);
    expect($offen[0]['schweregrad'])->toBe('hart');
    expect($offen[0]['paragraph'])->toBe('§6.1');
});

it('Ablage: wertfreier Fingerprint dedupliziert (seen_count↑), verworfen bleibt, weg=verschwunden', function () use ($befund) {
    $svc = app(ConformanceService::class);
    $wo = FoodAlchemistConformanceFinding::where('artifact_id', $this->rezept->id);

    // 1. neuer Befund
    $z = $svc->speichere($this->rootTeam, 'recipe', $this->rezept->id, [$befund()]);
    expect($z['neu'])->toBe(1);
    $row = $wo->first();
    expect($row->seen_count)->toBe(1);

    // 2. gleicher § + Feld, anders formulierter Grund → KEINE Dublette, seen_count 2
    $z = $svc->speichere($this->rootTeam, 'recipe', $this->rezept->id, [$befund(['begruendung' => 'komplett anders formuliert'])]);
    expect($z['wieder'])->toBe(1);
    expect($wo->count())->toBe(1);
    expect($row->fresh()->seen_count)->toBe(2);

    // 3. verworfen bleibt verworfen (ein Folgelauf öffnet ihn NICHT wieder)
    $row->update(['status' => 'verworfen']);
    $svc->speichere($this->rootTeam, 'recipe', $this->rezept->id, [$befund()]);
    expect($row->fresh()->status)->toBe('verworfen');

    // 4. offener Befund, den der Lauf nicht mehr meldet → verschwunden
    $row->update(['status' => 'offen']);
    $z = $svc->speichere($this->rootTeam, 'recipe', $this->rezept->id, []);
    expect($z['verschwunden'])->toBe(1);
    expect($row->fresh()->status)->toBe('verschwunden');
});
