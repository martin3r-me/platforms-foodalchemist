<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 / Welle 2 — der KANON kommt in den Prompt. Seit Spec 52 · F2: **nur** der Kanon.
 *
 * Bis Welle 2 war der Kanon eine Packliste ohne Packer: `documentsFor()` las nur das
 * MCP-Tool, der Generator zog sein „VERBINDLICHES REGELWERK" aus den always-Bindings der
 * Original-Dossiers. Welle 2 drehte die Rangfolge um (Kanon gewinnt, Bindungen stumm), F2
 * hat den zweiten Weg dann ganz entfernt.
 *
 * Der Unterschied ist keine Kosmetik: „stumm, solange ein Kanon steht" hiess, dass ein
 * verlorener Kanon die Bindungen STILL wieder scharf schaltete — ein Prompt-Key wechselte
 * lautlos seine Wissensquelle. Heute bleibt der Regelwerk-Block einfach leer, und das ist
 * ein Zustand, den `wissen-profil` und `wissen-steuerdaten-w0 --verify` melden können.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);

    // Mitschnitt der Messages — nur so ist die Position des Blocks prüfbar, nicht nur seine Größe.
    $this->messages = [];
    $test = $this;
    app()->singleton(FakeAiProvider::class, fn () => new class($test) extends FakeAiProvider
    {
        public function __construct(private $test) {}

        public function chat(array $messages, array $options = []): array
        {
            $this->test->messages = $messages;

            return ['content' => json_encode(['werte' => ['description' => 'x'], 'confidence' => 0.9]), 'model' => 'fake', 'usage' => []];
        }
    });

    $this->mkDoc = function (string $slug, int $chars, string $wort = 'Regel'): void {
        $inhalt = mb_substr(str_repeat($wort . ' ', (int) ceil($chars / (mb_strlen($wort) + 1))), 0, $chars);
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => (int) $this->rootTeam->id, 'slug' => $slug,
            'title' => 'Titel '.$slug, 'category' => 'regelwerk', 'content_md' => $inhalt, 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'char_count' => $chars,
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    // Bindungen entstehen nur noch per Insert — die Schreibpfade sind zu (F3). Die Fixture
    // stellt damit den ALTBESTAND, so wie er auf demo liegt.
    $this->bind = function (string $slug, string $targetKey): void {
        DB::table('foodalchemist_knowledge_bindings')->insert([
            'uuid' => (string) UuidV7::generate(),
            'knowledge_document_id' => DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->value('id'),
            'binding_type' => 'layer', 'target_key' => $targetKey, 'mode' => 'always', 'weight' => 0,
            'active' => 1, 'source' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->kanon = fn (string $slug, int $ord, string $mode = 'pflicht') => app(KnowledgeCanonService::class)
        ->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'recipe.description', 'slug' => $slug, 'ord' => $ord, 'mode' => $mode]);
    $this->log = fn () => DB::table('foodalchemist_ai_call_log')->where('feature', 'recipe.description')->latest('id')->first();
});

it('baut den Regelwerk-Block aus dem Kanon — eine Alt-Bindung daneben ändert nichts', function () {
    ($this->mkDoc)('k2-alt-ganz', 900, 'Altes Ganzdossier');
    ($this->bind)('k2-alt-ganz', 'recipe.description');
    ($this->mkDoc)('k2-p10', 700, 'Anti Pattern');
    ($this->mkDoc)('k2-p1', 500, 'Naming');
    ($this->kanon)('k2-p10', 20);
    ($this->kanon)('k2-p1', 10);

    app(AiGatewayService::class)->propose('recipe.description', ['description' => 'Klarer Fond.'], ['knowledge' => "# RETRIEVAL\n\nVariables Wissen."]);

    $log = ($this->log)();
    $parts = json_decode((string) $log->prompt_parts, true);

    // Kanon ist da (500 + 700 + Header), der Bound-Kanal existiert nicht mehr.
    expect($parts['kanon'])->toBeGreaterThan(1200)->and($parts['kanon'])->toBeLessThan(1400)
        ->and($parts['bound'])->toBe(0)
        ->and($parts['dropped'])->toBe(0)
        // Die Zerlegung geht weiter exakt auf — sonst ist die Sonde als Budget-Grundlage wertlos.
        ->and((int) $log->prompt_chars)->toBe(
            $parts['huelle'] + $parts['kanon'] + $parts['bound'] + $parts['task'] + 2 + $parts['retrieval'] + 11 + $parts['kontext']
        );

    // Herkunft im Audit: Kanon-Slugs mit Version, das Binding-Dossier nicht.
    $used = json_decode((string) $log->knowledge_used, true);
    expect($used)->toContain('k2-p1@v1')->toContain('k2-p10@v1')->not->toContain('k2-alt-ganz@v1');

    // Position: EIN Regelwerk-Block als system-Message direkt vor dem User-Content, in ord-Reihenfolge,
    // und das alte Ganzdossier steht nirgends im Prompt.
    $systems = array_values(array_filter($this->messages, fn ($m) => $m['role'] === 'system'));
    $block = end($systems)['content'];
    expect($block)->toStartWith('# VERBINDLICHES REGELWERK')
        ->and(mb_strpos($block, '## KANON: k2-p1'))->toBeLessThan((int) mb_strpos($block, '## KANON: k2-p10'))
        ->and(collect($this->messages)->last()['role'])->toBe('user')
        ->and(json_encode($this->messages))->not->toContain('GEBUNDEN')->not->toContain('Altes Ganzdossier');
});

it('ohne Kanon bleibt der Regelwerk-Block LEER — auch mit Alt-Bindung', function () {
    // ★ Die Umkehrung des alten Tests, und der eigentliche Gewinn von F2.
    //
    // Vorher stand hier: „ohne Kanon-Zeilen tragen die Bindings den Block" — und genau das
    // war die Falle. Ein Prompt-Key, der seinen Kanon verlor (Dossier hart geloescht, FK
    // `cascade`), wechselte damit STILL auf ein veraltetes Ganzdossier. Von aussen sah der
    // Lauf normal aus.
    //
    // Heute ist der Block leer. Das ist nicht „schlechter", sondern EHRLICH: ein leerer
    // Regelwerk-Block ist ein Zustand, den der Waechter melden kann; ein still getauschter
    // ist es nicht.
    ($this->mkDoc)('k2-alt-ganz', 900, 'Altes Ganzdossier');
    ($this->bind)('k2-alt-ganz', 'recipe.description');
    ($this->bind)('k2-alt-ganz', 'recipe');                    // auch der Bereichs-Praefix zieht nicht mehr

    app(AiGatewayService::class)->propose('recipe.description', ['description' => 'Klarer Fond.']);

    $parts = json_decode((string) ($this->log)()->prompt_parts, true);
    expect($parts['kanon'])->toBe(0)
        ->and($parts['bound'])->toBe(0)
        ->and(json_encode($this->messages))->not->toContain('Altes Ganzdossier')
        ->not->toContain('GEBUNDEN');

    // `bound` bleibt als Schluessel im Call-Log — strukturell 0, nicht verschwunden. Ein
    // fehlender Schluessel machte alte und neue Laeufe unvergleichbar.
    expect($parts)->toHaveKey('bound');
});

it('pflicht kommt immer ganz, wenn_platz nur im Budget und nie angeschnitten', function () {
    config()->set('foodalchemist.ai.knowledge_budget', ['recipe.description' => 4200]);
    ($this->mkDoc)('k2-pflicht', 3000, 'Pflicht');
    ($this->mkDoc)('k2-platz-ok', 1000, 'Platz');
    ($this->mkDoc)('k2-platz-zu-gross', 1500, 'Zuviel');
    ($this->mkDoc)('k2-platz-schon-da', 200, 'Schon');
    ($this->kanon)('k2-pflicht', 10);
    ($this->kanon)('k2-platz-ok', 20, 'wenn_platz');
    ($this->kanon)('k2-platz-zu-gross', 30, 'wenn_platz');     // 3000+1000+1500 > 4200 → verworfen, nicht gekürzt
    ($this->kanon)('k2-platz-schon-da', 40, 'wenn_platz');     // passt, kam aber schon per Retrieval

    app(AiGatewayService::class)->propose('recipe.description', ['description' => 'Fond.'],
        ['knowledge' => 'x', 'knowledge_used' => ['k2-platz-schon-da@v1']]);

    $log = ($this->log)();
    $parts = json_decode((string) $log->prompt_parts, true);
    $systems = array_values(array_filter($this->messages, fn ($m) => $m['role'] === 'system'));
    $block = (string) (end($systems)['content'] ?? '');

    expect($block)->toContain('## KANON: k2-pflicht')->toContain('## KANON: k2-platz-ok')
        ->not->toContain('k2-platz-zu-gross')->not->toContain('k2-platz-schon-da')
        ->not->toContain('[…gekürzt')
        ->and($parts['dropped'])->toBe(mb_strlen("## KANON: k2-platz-zu-gross\n\n") + 1500 + 7)
        ->and(json_decode((string) $log->knowledge_used, true))->toContain('k2-platz-schon-da@v1')->toContain('k2-pflicht@v1');
});

it('stoppt vor dem Modellaufruf wenn Pflichtwissen das gemeinsame Budget überschreitet', function () {
    config()->set('foodalchemist.ai.knowledge_budget', ['recipe.description' => 4200]);
    ($this->mkDoc)('k2-pflicht-a', 3900, 'Alpha');
    ($this->mkDoc)('k2-pflicht-b', 3900, 'Beta');
    ($this->kanon)('k2-pflicht-a', 10);
    ($this->kanon)('k2-pflicht-b', 20);

    expect(fn () => app(AiGatewayService::class)->propose('recipe.description', ['description' => 'Fond.']))
        ->toThrow(\Platform\FoodAlchemist\Services\Ai\KnowledgeBudgetExceeded::class);
    expect($this->messages)->toBe([]);
});
