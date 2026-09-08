<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Dossiers gehören an den Prompt, der sie BENUTZT — nicht an einen Bereichs-Präfix.
 *
 * Dominique 2026-09-03: „wir hatten ja gesagt, dass wir die Dossiers da nutzen wollen, wo sie
 * auch schlussendlich benutzt werden. Arbeitszeit bei den Stammdaten zum Einfüllen der
 * Kochzeiten. Geschmacksbalance, ja, braucht es bei Gerichten und Basisrezepten."
 *
 * ★ **Die Regel ist geblieben, ihr Träger hat gewechselt.** Diese Datei pinnte, dass der
 * W0-Befehl Bindungen vom Präfix `recipe` löst und an die richtigen Prompt-Keys hängt — nötig,
 * weil `selectBoundKnowledge()` auf `[$promptKey, $bereich]` matchte und eine Präfix-Bindung
 * damit in ALLEN 22 `recipe.*`-Prompts landete (gemessen 17.759 Zeichen, davon 8.238 pro Call
 * gebaut und weggeworfen).
 *
 * Seit Spec 52 · F2 gibt es keine Bindungen mehr. Der Träger ist der Kanon — und der kennt
 * **von Anfang an keinen Präfix**: `selectKanon()` liest ausschliesslich
 * `scope='prompt_key'` mit dem exakten Key. Die Fehlerklasse ist damit nicht bewacht,
 * sondern **strukturell unmöglich**. Genau das prüft dieser Test jetzt, statt eine
 * Umbindungs-Choreografie, die es nicht mehr gibt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);

    $this->mkDoc = function (string $slug, int $chars): void {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) Str::uuid(), 'team_id' => (int) $this->rootTeam->id, 'slug' => $slug,
            'title' => 'Titel '.$slug, 'category' => 'regelwerk',
            'content_md' => str_repeat('Regel ', (int) ceil($chars / 6)),
            'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => $chars,
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('eine Kanon-Zeile am BEREICH erreicht den Prompt NICHT — der Präfix-Weg existiert nicht', function () {
    // Der Kernbeweis. Früher hätte `target_key='recipe'` jeden `recipe.*`-Prompt getroffen.
    // Der Kanon kennt diesen Weg nicht: er ist eine explizite Liste je Prompt-Key.
    ($this->mkDoc)('dr-am-bereich', 600);
    app(KnowledgeCanonService::class)->set($this->rootTeam, [
        'scope' => 'prompt_key', 'scope_key' => 'recipe', 'slug' => 'dr-am-bereich', 'ord' => 10,
    ]);

    app(AiGatewayService::class)->propose('recipe.description', ['description' => 'Fond.']);

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'recipe.description')->latest('id')->first();
    $parts = json_decode((string) $log->prompt_parts, true);

    expect($parts['kanon'])->toBe(0)
        ->and($parts['bound'])->toBe(0)
        ->and((string) $log->knowledge_used)->not->toContain('dr-am-bereich');
});

it('am exakten Prompt-Key kommt dasselbe Dossier an', function () {
    // Gegenprobe: nicht der Kanon ist blind, sondern der Präfix existiert nicht.
    ($this->mkDoc)('dr-am-key', 600);
    app(KnowledgeCanonService::class)->set($this->rootTeam, [
        'scope' => 'prompt_key', 'scope_key' => 'recipe.description', 'slug' => 'dr-am-key', 'ord' => 10,
    ]);

    app(AiGatewayService::class)->propose('recipe.description', ['description' => 'Fond.']);

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'recipe.description')->latest('id')->first();
    expect(json_decode((string) $log->prompt_parts, true)['kanon'])->toBeGreaterThan(600)
        ->and((string) $log->knowledge_used)->toContain('dr-am-key');
});

it('eine ALT-Bindung am Bereich wirkt ebenfalls nicht mehr — auch nicht ohne Kanon', function () {
    // Die verbliebenen Alt-Zeilen (auf demo 9) dürfen nicht durch die Hintertür zurückkommen,
    // wenn ein Key gerade keinen Kanon hat.
    ($this->mkDoc)('dr-alt-bindung', 900);
    DB::table('foodalchemist_knowledge_bindings')->insert([
        'uuid' => (string) Str::uuid(), 'team_id' => (int) $this->rootTeam->id,
        'knowledge_document_id' => DB::table('foodalchemist_knowledge_documents')->where('slug', 'dr-alt-bindung')->value('id'),
        'binding_type' => 'layer', 'target_key' => 'recipe', 'mode' => 'always', 'weight' => 0,
        'active' => 1, 'source' => 'test', 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(AiGatewayService::class)->propose('recipe.description', ['description' => 'Fond.']);

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'recipe.description')->latest('id')->first();
    expect(json_decode((string) $log->prompt_parts, true)['bound'])->toBe(0)
        ->and((string) $log->knowledge_used)->not->toContain('dr-alt-bindung');
});

it('die Deckel tragen die Pflichtmenge — sonst kommt das Dossier als Anschnitt', function () {
    // Unverändert gültig: der Deckel gehört jetzt dem Kanon (der Bound-Kanal ist weg), aber
    // die Zahlen und ihr Grund bleiben dieselben.
    $b = config('foodalchemist.ai.bound_knowledge_budget');

    // Kanon-Pflicht recipe.generator = 13 Dossiers Σ 33.902 — der Deckel muss die Summe
    // tragen, sonst behauptet die Config ein Budget, das der Prompt längst reisst.
    expect($b['recipe.generator']['total'])->toBeGreaterThanOrEqual(33902)
        // …und chars_per_doc muss das GRÖSSTE Pflicht-Dossier ganz fassen, nicht 8.400 davon.
        ->and($b['recipe.generator']['chars_per_doc'])->toBeGreaterThanOrEqual(10670)
        ->and($b['vk.generator']['total'])->toBeGreaterThanOrEqual(36091)
        ->and($b['vk.generator']['chars_per_doc'])->toBeGreaterThanOrEqual(10670)
        // recipe.eigenschaften braucht einen EIGENEN Deckel — der Default (3 × 1.400)
        // hätte 7.089 Zeichen auf einen 1.400-Zeichen-Kopf geschnitten.
        ->and($b['recipe.eigenschaften']['chars_per_doc'])->toBeGreaterThanOrEqual(7089);
});
