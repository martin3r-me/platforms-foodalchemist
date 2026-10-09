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

    app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService::class)->set($this->rootTeam, [
        'scope' => 'prompt_key', 'scope_key' => 'recipe.generator',
        'slug' => 'regelwerk-basisrezepte-6-mengen-einheiten-yield', 'mode' => 'pflicht',
    ]);

    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id,
        'recipe_key' => 'bx-heal',
        'name' => 'Tomaten Concassée',
        'status' => 'draft',
        'is_sales_recipe' => false,
    ]);
});

$befund = fn (array $o = []) => array_merge([
    'paragraph' => '§6.1',
    'schweregrad' => 'hart',
    'feld' => 'name',
    'begruendung' => 'Plural statt Singular',
    'vorschlag' => 'Sauce: Tomate',
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

    expect($this->rezept->fresh()->name)->toBe('Sauce: Tomate');
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

/**
 * Spec 52/C5 — die Selbstheilung war der einzige Schritt ohne jedes Wissen.
 *
 * Gemessen 2026-09-10: `RecipeConformanceAdapter::revise()` rief `propose()` mit EINEM
 * Argument. `recipe.ueberarbeiten` hat keine Kanon-Zeile, Bindungen sind seit Paket 3
 * abgeschafft, `contextFor()` wurde nie gerufen — das Modell sollte einen §-Verstoss
 * korrigieren, ohne den § zu kennen.
 *
 * Geprueft wird am AUDIT, nicht am Prompt-Wortlaut: `prompt_parts.kanon` ist eine Zahl,
 * kein Satz, und bleibt gueltig, wenn jemand die Formulierung aendert.
 */
it('C5: die Selbstheil-Runde erbt den Kanon ihres Erzeugers — der verletzte § steht im Prompt', function () use ($befund) {
    ConformanceHealStub::bind([[$befund()], []], ['name' => 'Sauce: Tomate']);

    app(ConformanceService::class)->pruefeUndHeile($this->rootTeam, 'basisrezept', $this->rezept->id);

    $revise = DB::table('foodalchemist_ai_call_log')
        ->where('feature', 'recipe.ueberarbeiten')->orderByDesc('id')->first();

    expect($revise)->not->toBeNull();
    $teile = json_decode((string) $revise->prompt_parts, true) ?: [];
    expect($teile['kanon'] ?? 0)->toBeGreaterThan(0);
});

// ── Review 09.10.: nur heilen, was revise ändern kann ──────────────────────────────────────

$luecke = fn (array $o = []) => array_merge([
    'paragraph' => '§4', 'schweregrad' => 'hart', 'feld' => 'zutat:Püree: Petersilienwurzel',
    'begruendung' => 'Kein Unterrezept verknüpft, im Bestand fehlt ein freigegebenes Basisrezept.',
    'vorschlag' => '', 'konfidenz' => 0.8,
], $o);

$aufrufe = fn (string $feature) => DB::table('foodalchemist_ai_call_log')->where('feature', $feature)->count();

it('Heilung: nur offene Basisrezept-Lücken → kein Revise, keine zweite Prüfung, Befund bleibt Hinweis', function () use ($luecke, $aufrufe) {
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id,
        'recipe_id' => $this->rezept->id, 'raw_text' => 'Püree: Petersilienwurzel', 'quantity' => '7000',
        'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1]);
    ConformanceHealStub::bind([[$luecke()], [$luecke()]]);

    $erg = app(ConformanceService::class)->pruefeUndHeile($this->rootTeam, 'basisrezept', $this->rezept->id);

    expect($erg['heilung_uebersprungen'])->toBe('nichts_heilbar')
        ->and($aufrufe('recipe.ueberarbeiten'))->toBe(0)
        ->and($aufrufe('conformance.check'))->toBe(1)
        ->and($erg['ablage']['neu'])->toBe(1);
});

it('Heilung: gemischt → Revise läuft, aber nur mit dem heilbaren Befund', function () use ($befund, $luecke, $aufrufe) {
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id,
        'recipe_id' => $this->rezept->id, 'raw_text' => 'Püree: Petersilienwurzel', 'quantity' => '7000',
        'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1]);
    $heilbar = app(\Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter::class)
        ->heilbar($this->rootTeam, $this->rezept->id, [$befund(), $luecke()]);
    ConformanceHealStub::bind([[$befund(), $luecke()], [$luecke()]]);

    $erg = app(ConformanceService::class)->pruefeUndHeile($this->rootTeam, 'basisrezept', $this->rezept->id);

    expect(array_column($heilbar, 'feld'))->toBe(['name'])
        ->and($erg['heilung_uebersprungen'])->toBeNull()
        ->and($aufrufe('recipe.ueberarbeiten'))->toBe(1);
});

it('heilbar: an der Lücken-Zeile bleibt ein Name-/Präfix-Befund heilbar, Verweiszeile und manuelle Beschreibung nicht', function () use ($luecke) {
    $g = $this->unitG($this->rootTeam)->id;
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id,
        'recipe_id' => $this->rezept->id, 'raw_text' => 'Matte: Petersilie', 'quantity' => '100', 'unit_vocab_id' => $g, 'position' => 1]);
    $sub = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'bx-sub', 'name' => 'Jus: Kalb', 'status' => 'approved']);
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id,
        'recipe_id' => $this->rezept->id, 'referenced_recipe_id' => $sub->id, 'raw_text' => 'Jus: Kalb', 'quantity' => '200', 'unit_vocab_id' => $g, 'position' => 2]);
    $this->rezept->update(['description_source' => 'manual']);

    $praefix = $luecke(['feld' => 'zutat:Matte: Petersilie', 'begruendung' => 'Typ-Präfix „Matte" nicht im Vokabular, Basisrezept fehlt.']);
    $verknuepfung = $luecke(['feld' => 'zutat:Matte: Petersilie']);
    $amVerweis = $luecke(['feld' => 'zutat:Jus: Kalb', 'begruendung' => 'Jus enthält Butter, Gericht ist vegan.']);
    $beschreibung = $luecke(['paragraph' => '§8.3', 'feld' => 'description', 'begruendung' => 'Zu viele Sätze.']);

    $heilbar = app(\Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter::class)
        ->heilbar($this->rootTeam, $this->rezept->id, [$praefix, $verknuepfung, $amVerweis, $beschreibung]);

    expect($heilbar)->toBe([$praefix]);
});

it('Heilung: derselbe Befund am unveränderten Rezept wird nicht ein drittes Mal geheilt', function () use ($befund, $aufrufe) {
    $svc = app(ConformanceService::class);
    $svc->speichere($this->rootTeam, 'recipe', $this->rezept->id, [$befund()]);
    $svc->speichere($this->rootTeam, 'recipe', $this->rezept->id, [$befund()]);   // seen_count 2 = eine Heilung überlebt
    FoodAlchemistRecipe::whereKey($this->rezept->id)->update(['updated_at' => now()->subMinute()]);
    ConformanceHealStub::bind([[$befund()], [$befund()]]);

    $erg = $svc->pruefeUndHeile($this->rootTeam, 'basisrezept', $this->rezept->id);

    expect($erg['heilung_uebersprungen'])->toBe('kein_fortschritt')
        ->and($aufrufe('recipe.ueberarbeiten'))->toBe(0);
});

it('Heilung: nach einer Änderung am Rezept wird wieder geheilt', function () use ($befund, $aufrufe) {
    $svc = app(ConformanceService::class);
    $svc->speichere($this->rootTeam, 'recipe', $this->rezept->id, [$befund()]);
    $svc->speichere($this->rootTeam, 'recipe', $this->rezept->id, [$befund()]);
    FoodAlchemistConformanceFinding::where('artifact_id', $this->rezept->id)->update(['last_seen_at' => now()->subMinute()]);
    ConformanceHealStub::bind([[$befund()], []]);

    $erg = $svc->pruefeUndHeile($this->rootTeam, 'basisrezept', $this->rezept->id);

    expect($erg['heilung_uebersprungen'])->toBeNull()
        ->and($aufrufe('recipe.ueberarbeiten'))->toBe(1);
});

it('Heilung: nur weiche Befunde → kein Revise, keine zweite Prüfung, Hinweis bleibt (Entscheidung 09.10.)', function () use ($befund, $aufrufe) {
    $weich = $befund(['schweregrad' => 'weich']);
    ConformanceHealStub::bind([[$weich], [$weich]]);

    $erg = app(ConformanceService::class)->pruefeUndHeile($this->rootTeam, 'basisrezept', $this->rezept->id);

    expect($erg['heilung_uebersprungen'])->toBe('nur_weich')
        ->and($aufrufe('recipe.ueberarbeiten'))->toBe(0)
        ->and($aufrufe('conformance.check'))->toBe(1)
        ->and($erg['ablage']['neu'])->toBe(1);
});

it('Heilung: hart + weich → die Direktive trägt nur den harten Befund', function () use ($befund, $aufrufe) {
    $weich = $befund(['paragraph' => '§8.3', 'feld' => 'description', 'begruendung' => 'Satzzahl knapp', 'schweregrad' => 'weich']);
    ConformanceHealStub::bind([[$befund(), $weich], [$weich]]);
    $direktive = null;
    $adapter = Mockery::mock(\Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter::class)->makePartial();
    $adapter->shouldReceive('revise')->once()->andReturnUsing(function ($t, $id, $d, $b) use (&$direktive) {
        $direktive = $b;
    });
    app()->instance(\Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter::class, $adapter);

    $erg = app(ConformanceService::class)->pruefeUndHeile($this->rootTeam, 'basisrezept', $this->rezept->id);

    expect($erg['heilung_uebersprungen'])->toBeNull()
        ->and(array_column($direktive, 'schweregrad'))->toBe(['hart']);
});
