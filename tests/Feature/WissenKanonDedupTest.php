<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 Welle 2 — Kanon ⇄ Retrieval-Dedup.
 *
 * `pflicht`-Kanon-Dossiers stellt der Gateway IMMER vollständig in den Prompt — er ignoriert dafür
 * Budget UND `alreadyUsed`. Das Retrieval (`contextFor`) weiß davon nichts und kann dieselben
 * Dossiers ein zweites Mal laden: gemessen 2026-09-06 an `ai_generate_recipe × cross_cutting
 * discovery 6×8000`, das die mengen_defaults-/geschmacksbalance-Splits aus dem Kanon noch einmal
 * zog. Zwei Kopien derselben Regel im Prompt kosten Tokens und verdrängen ein anderes Dossier.
 *
 * Lösung an EINEM Ort: `_kanon_prompt_key` → contextFor löst den Kanon auf und schließt die
 * `pflicht`-Slugs aus. `wenn_platz` bleibt absichtlich draußen (kann dem Kanon-Budget zum Opfer
 * fallen und soll dann noch findbar sein).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkDoc = function (string $slug, string $kategorie): void {
        $inhalt = "Regel für {$slug}. " . str_repeat('Text ', 40);
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => (int) $this->rootTeam->id, 'slug' => $slug,
            'title' => 'Titel ' . $slug, 'category' => $kategorie, 'content_md' => $inhalt, 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    // `$mode` seit Spec 52 · F4 explizit: für `regelwerk` gibt es `always` nicht mehr (der
    // dedizierte Zweig ist gelöscht), die Kategorie läuft über die generische Discovery. Die
    // übrigen Kategorien haben ihren always-Handler behalten.
    $this->route = function (string $feature, string $kategorie, string $mode = 'always'): void {
        DB::table('foodalchemist_knowledge_routings')->updateOrInsert(
            ['feature' => $feature, 'category' => $kategorie],
            ['mode' => $mode, 'max_docs' => 1, 'max_chars_per_doc' => 4000, 'created_at' => now(), 'updated_at' => now()],
        );
    };
    $this->kanon = fn (string $scopeKey, string $slug, string $mode = 'pflicht') => app(KnowledgeCanonService::class)
        ->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => $scopeKey, 'slug' => $slug, 'mode' => $mode]);
    $this->slugs = fn (array $wissen) => array_map(fn ($f) => preg_replace('/@v\d+$/', '', $f), $wissen['files_used']);
});

it('schliesst die pflicht-Kanon-Dossiers aus der Regelwerk-Discovery aus — ohne Schlüssel unverändert', function () {
    // ★ Spec 52 · F4: lief bis dahin über `regelwerk:always` und den `->first()`-Block. Beides
    // ist gelöscht, `regelwerk` geht über die generische Discovery. Die AUSSAGE ist dieselbe
    // und wichtiger denn je — der Ausschluss muss auf dem Pfad greifen, den es noch gibt.
    //
    // Die Anfrage nennt «Kanon», damit das a-Dossier per Jaccard vorne liegt: ohne Ausschluss
    // gewinnt es, mit Ausschluss muss das b-Dossier nachrücken. Bei `always` erzwang das
    // vorher `orderBy(slug)`; Discovery braucht dafür einen Token.
    ($this->mkDoc)('regelwerk-foodbook-a-kanon', 'regelwerk');
    ($this->mkDoc)('regelwerk-foodbook-b-frei', 'regelwerk');
    ($this->route)('foodbook.grundgeruest', 'regelwerk', 'discovery');
    ($this->kanon)('foodbook.grundgeruest', 'regelwerk-foodbook-a-kanon');

    $kcs = app(KnowledgeContextService::class);
    $anfrage = 'Regelwerk Foodbook Kanon';

    $ohne = $kcs->contextFor($this->rootTeam, 'foodbook.grundgeruest', $anfrage);
    $mit = $kcs->contextFor($this->rootTeam, 'foodbook.grundgeruest', $anfrage, null, [], ['_kanon_prompt_key' => 'foodbook.grundgeruest']);

    expect(($this->slugs)($ohne))->toBe(['regelwerk-foodbook-a-kanon'])
        ->and(($this->slugs)($mit))->toBe(['regelwerk-foodbook-b-frei'])
        // und der Kanon-Slug taucht auch in der Herkunft nicht als Retrieval-Fund auf
        ->and(array_key_exists('regelwerk-foodbook-a-kanon', $mit['herkunft']))->toBeFalse();
});

it('wenn_platz-Kanon-Dossiers bleiben findbar — sie koennen dem Kanon-Budget zum Opfer fallen', function () {
    ($this->mkDoc)('regelwerk-foodbook-a-optional', 'regelwerk');
    ($this->mkDoc)('regelwerk-foodbook-b-frei', 'regelwerk');
    ($this->route)('foodbook.grundgeruest', 'regelwerk', 'discovery');
    ($this->kanon)('foodbook.grundgeruest', 'regelwerk-foodbook-a-optional', 'wenn_platz');

    $mit = app(KnowledgeContextService::class)
        ->contextFor($this->rootTeam, 'foodbook.grundgeruest', 'Regelwerk Foodbook Optional', null, [], ['_kanon_prompt_key' => 'foodbook.grundgeruest']);

    expect(($this->slugs)($mit))->toBe(['regelwerk-foodbook-a-optional']);
});

it('greift auch fuer generische always-Kategorien und fuer den Kanon eines ANDEREN Prompt-Keys', function () {
    // Der Generator läuft als Feature `ai_generate_recipe`, sein Kanon hängt aber am Prompt-Key
    // `recipe.generator` — genau diese Indirektion trägt der Aufrufer über `_kanon_prompt_key`.
    ($this->mkDoc)('kapazitaet-a-kanon', 'produktion_kapazitat');
    ($this->mkDoc)('kapazitaet-b-frei', 'produktion_kapazitat');
    ($this->route)('ai_generate_recipe', 'produktion_kapazitat');
    ($this->kanon)('recipe.generator', 'kapazitaet-a-kanon');

    $mit = app(KnowledgeContextService::class)
        ->contextFor($this->rootTeam, 'ai_generate_recipe', 'Kartoffelpüree', null, [], ['_kanon_prompt_key' => 'recipe.generator']);

    expect(($this->slugs)($mit))->toContain('kapazitaet-b-frei')
        ->not->toContain('kapazitaet-a-kanon');
});
