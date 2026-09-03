<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(fn () => $this->seedTeamHierarchy());

/**
 * Dossiers gehören an den Prompt, der sie BENUTZT — nicht an den Bereichs-Präfix.
 *
 * Dominique 2026-09-03: „wir hatten ja gesagt, dass wir die Dossiers da nutzen wollen, wo sie
 * auch schlussendlich benutzt werden. Arbeitszeit bei den Stammdaten zum Einfüllen der
 * Kochzeiten. Geschmacksbalance, ja, braucht es bei Gerichten und Basisrezepten."
 *
 * Warum es einen Test braucht und nicht nur einen Kommentar: `selectBoundKnowledge()` liest
 * `whereIn('target_key', [$promptKey, $bereich])`. Eine Bindung am Präfix `recipe` landet damit
 * in ALLEN 22 `recipe.*`-Prompts — und das ist unsichtbar, solange niemand danach sucht. Genau
 * so überlebte die MCP-Anleitung (6.761 Z.) jeden bisherigen Steuerdaten-Lauf, und genau so
 * fielen hier 17.759 Zeichen an, von denen gemessen 8.238 pro Call gebaut und weggeworfen wurden.
 */
function drDoc(string $slug, int $chars, ?int $teamId = null): int
{
    $md = str_repeat('Regel ', (int) ceil($chars / 6));

    return DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'team_id' => $teamId,
        'slug' => $slug,
        'title' => ucfirst(str_replace('-', ' ', $slug)),
        'category' => 'cross_cutting',
        'content_md' => mb_substr($md, 0, $chars),
        'version' => 1,
        'content_hash' => hash('sha256', $slug),
        'char_count' => $chars,
        'active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function drBind(int $docId, string $targetKey, string $mode, ?int $teamId = null): void
{
    DB::table('foodalchemist_knowledge_bindings')->insert([
        'uuid' => (string) Str::uuid(),
        'team_id' => $teamId,
        'knowledge_document_id' => $docId,
        'binding_type' => 'layer',
        'target_key' => $targetKey,
        'mode' => $mode,
        'weight' => 0,
        'active' => 1,
        'source' => 'test',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return array<string, array{mode:string, active:int}> target_key → Zustand */
function drZustand(string $slug): array
{
    return DB::table('foodalchemist_knowledge_bindings as b')
        ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'b.knowledge_document_id')
        ->where('d.slug', $slug)->whereNull('b.deleted_at')
        ->get(['b.target_key', 'b.mode', 'b.active'])
        ->mapWithKeys(fn ($r) => [$r->target_key => ['mode' => $r->mode, 'active' => (int) $r->active]])
        ->all();
}

it('loest geschmacksbalance vom recipe-Praefix und bindet es an BEIDE Generatoren', function () {
    $id = drDoc('geschmacksbalance', 10670, $this->rootTeam->id);
    drBind($id, 'recipe', 'discovery', $this->rootTeam->id);   // der Ist-Zustand auf demo

    $this->artisan('foodalchemist:wissen-steuerdaten-w0', ['--apply' => true]);

    $z = drZustand('geschmacksbalance');

    expect($z)->toHaveKeys(['recipe', 'recipe.generator', 'vk.generator'])
        // Am Präfix still gelegt — nicht gelöscht, damit es reversibel bleibt.
        ->and($z['recipe']['active'])->toBe(0)
        // Und an den zwei Zielen ALWAYS: „braucht es" heisst ganz, nicht score-gegatet.
        ->and($z['recipe.generator'])->toBe(['mode' => 'always', 'active' => 1])
        ->and($z['vk.generator'])->toBe(['mode' => 'always', 'active' => 1]);
});

it('bindet das Arbeitszeit-Dossier an recipe.eigenschaften — den einzigen Prompt, der Zeiten setzt', function () {
    $id = drDoc('produktion-arbeitszeit-und-personenminuten', 7089, $this->rootTeam->id);
    drBind($id, 'recipe', 'discovery', $this->rootTeam->id);

    $this->artisan('foodalchemist:wissen-steuerdaten-w0', ['--apply' => true]);

    $z = drZustand('produktion-arbeitszeit-und-personenminuten');

    expect($z['recipe']['active'])->toBe(0)
        ->and($z['recipe.eigenschaften'])->toBe(['mode' => 'always', 'active' => 1])
        // NICHT an den Generatoren: Arbeitszeit ist Produktionsplanung, nicht Rezept-Inhalt.
        ->and($z)->not->toHaveKey('recipe.generator')
        ->and($z)->not->toHaveKey('vk.generator');
});

it('ist idempotent — ein zweiter Lauf legt keine zweite Bindung an', function () {
    $id = drDoc('geschmacksbalance', 10670, $this->rootTeam->id);
    drBind($id, 'recipe', 'discovery', $this->rootTeam->id);

    $this->artisan('foodalchemist:wissen-steuerdaten-w0', ['--apply' => true]);
    $this->artisan('foodalchemist:wissen-steuerdaten-w0', ['--apply' => true]);

    $anzahl = DB::table('foodalchemist_knowledge_bindings as b')
        ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'b.knowledge_document_id')
        ->where('d.slug', 'geschmacksbalance')->where('b.target_key', 'recipe.generator')
        ->whereNull('b.deleted_at')->count();

    expect($anzahl)->toBe(1);
});

it('erbt team_id vom Dossier — eine Bindung darf nicht sichtbarer sein als ihr Dokument', function () {
    // Der Insert-Pfad (neues Ziel, noch keine Zeile) ist der einzige, der team_id SETZT.
    // Ein NULL hier machte die Bindung global, obwohl das Dossier dem Team gehört.
    $id = drDoc('geschmacksbalance', 10670, $this->rootTeam->id);
    drBind($id, 'recipe', 'discovery', $this->rootTeam->id);

    $this->artisan('foodalchemist:wissen-steuerdaten-w0', ['--apply' => true]);

    $teamIds = DB::table('foodalchemist_knowledge_bindings as b')
        ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'b.knowledge_document_id')
        ->where('d.slug', 'geschmacksbalance')->whereIn('b.target_key', ['recipe.generator', 'vk.generator'])
        ->whereNull('b.deleted_at')->pluck('b.team_id')->unique()->values()->all();

    expect($teamIds)->toBe([$this->rootTeam->id]);
});

it('die Deckel tragen die neue Pflichtmenge — sonst kommt das Dossier als Anschnitt', function () {
    $b = config('foodalchemist.ai.bound_knowledge_budget');

    // Pflicht recipe.generator: 18.521 (Bau-§§ + Basis + mengen_defaults) + 10.670 = 29.191
    expect($b['recipe.generator']['total'])->toBeGreaterThanOrEqual(29191)
        // …und chars_per_doc muss das GRÖSSTE Pflicht-Dossier ganz fassen, nicht 8.400 davon.
        ->and($b['recipe.generator']['chars_per_doc'])->toBeGreaterThanOrEqual(10670)
        // Pflicht vk.generator: 25.421 + 10.670 = 36.091
        ->and($b['vk.generator']['total'])->toBeGreaterThanOrEqual(36091)
        ->and($b['vk.generator']['chars_per_doc'])->toBeGreaterThanOrEqual(10670)
        // recipe.eigenschaften braucht einen EIGENEN Deckel — der Default (3 × 1.400)
        // hätte 7.089 Zeichen auf einen 1.400-Zeichen-Kopf geschnitten.
        ->and($b['recipe.eigenschaften']['chars_per_doc'])->toBeGreaterThanOrEqual(7089);
});
