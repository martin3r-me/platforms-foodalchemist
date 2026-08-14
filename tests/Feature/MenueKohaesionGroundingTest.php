<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Roadmap Planung-Leitstelle · Etappe 1 »LLM-Foodpairing als Assist«.
 *
 * Der erste Chunk erdet die KI-Ideen-Erfindung ({@see IdeenService::kiDivergenzConcept})
 * am Pairing-Graphen: die schon gesetzten Gerichte einer Menüfolge tragen ein Aroma-Profil
 * (Anker + harmonische Partner), das als Leitplanke in den Prompt fließt, statt frei zu raten.
 * Zwei Ebenen geprüft: der deterministische Kern ({@see PairingService::menueAromaProfil})
 * und die Verdrahtung (der Block landet wirklich im LLM-Kontext, fehlt sauber bei leerem Menü).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->ankerId = [];
    $this->mkAnker = function (string $slug, string $label): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => $label,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->ankerId[$slug] = (int) DB::getPdo()->lastInsertId();
    };
    $this->mkGpMapping = function (int $gpId, string $ankerSlug): void {
        DB::table('foodalchemist_gp_anchor_mappings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'gp_id' => $gpId, 'anchor_id' => $this->ankerId[$ankerSlug], 'role' => 'kern',
            'source' => 'ai_inferred', 'ai_confidence' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->mkEdge = function (string $a, string $b, int $level, float $weight): void {
        DB::table('foodalchemist_pairing_anchor_edges')->insert([
            'uuid' => (string) UuidV7::generate(),
            'anchor_a_id' => $this->ankerId[$a], 'anchor_b_id' => $this->ankerId[$b],
            'type' => 'aroma', 'level' => $level, 'weight' => $weight,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    // Kleiner Graph: zwei Menü-Anker + ein Partner ausserhalb des Sets.
    ($this->mkAnker)('raeucherwaren', 'Räucherwaren');
    ($this->mkAnker)('zitrus', 'Zitrus');
    ($this->mkAnker)('dill', 'Dill');
    ($this->mkEdge)('raeucherwaren', 'dill', 3, 0.9);   // Expansion: Räucherwaren → Dill

    // Zwei gesetzte Gerichte der Folge, je über einen GP am Anker.
    $this->mkGericht = function (string $key, string $name, string $gpName, string $ankerSlug): FoodAlchemistRecipe {
        $gp = FoodAlchemistGp::create([
            'team_id' => $this->rootTeam->id, 'gp_key' => 'kohaesion|' . $key, 'name' => $gpName, 'status' => 'approved',
        ]);
        ($this->mkGpMapping)($gp->id, $ankerSlug);
        $r = FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name,
            'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 18.00,
        ]);
        $this->makeIngredient($r, $gpName, $gp, '120', 1);

        return $r;
    };
});

// ── PairingService::menueAromaProfil — der deterministische Kern ─────────────

it('menueAromaProfil zieht die Menü-Anker + harmonische Partner aus dem Graphen', function () {
    $lachs = ($this->mkGericht)('r-lachs', 'HG: Räucherlachs', 'Räucherlachs', 'raeucherwaren');
    $ceviche = ($this->mkGericht)('r-ceviche', 'VS: Zitrus-Ceviche', 'Limette', 'zitrus');

    $profil = app(PairingService::class)->menueAromaProfil([$lachs, $ceviche]);

    expect($profil)->not->toBeNull()
        ->and($profil['anker'])->toContain('Räucherwaren')
        ->and($profil['anker'])->toContain('Zitrus')
        ->and($profil['partner'])->toContain('Dill')          // Expansion aus der Kante
        ->and($profil['partner'])->not->toContain('Räucherwaren'); // Set-Anker nie als Partner
});

it('menueAromaProfil liefert null, wenn die Folge keine auflösbaren Anker trägt', function () {
    // Gericht ohne Anker-Mapping (roher raw_text, kein GP).
    $r = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'r-blank', 'name' => 'HG: Ungemappt',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 10.00,
    ]);
    $this->makeIngredient($r, 'Irgendwas ohne GP', null, '100', 1);

    expect(app(PairingService::class)->menueAromaProfil([$r]))->toBeNull()
        ->and(app(PairingService::class)->menueAromaProfil([]))->toBeNull();
});

// ── PairingService::menuKohaesionWarnung — das Kohärenz-Gate (Roadmap E1) ────

it('menuKohaesionWarnung stuft den Score in gut/schwach/kritisch (Panel-Schwellen)', function () {
    $svc = app(PairingService::class);
    $mk = fn (int $score) => ['score' => $score, 'rated_pairs' => 1, 'total_pairs' => 1,
        'weakest_pair' => ['a' => 'Gang A', 'b' => 'Gang B', 'score' => $score, 'type' => 'aroma']];

    expect($svc->menuKohaesionWarnung($mk(72))['stufe'])->toBe('gut')       // ≥ 60
        ->and($svc->menuKohaesionWarnung($mk(60))['stufe'])->toBe('gut')     // Grenze inklusiv
        ->and($svc->menuKohaesionWarnung($mk(45))['stufe'])->toBe('schwach') // 35..59
        ->and($svc->menuKohaesionWarnung($mk(35))['stufe'])->toBe('schwach') // Grenze inklusiv
        ->and($svc->menuKohaesionWarnung($mk(20))['stufe'])->toBe('kritisch'); // < 35
});

it('menuKohaesionWarnung nennt die schwächste Brücke bei schwach/kritisch, nicht bei gut', function () {
    $svc = app(PairingService::class);
    $mk = fn (int $score) => ['score' => $score, 'rated_pairs' => 2, 'total_pairs' => 3,
        'weakest_pair' => ['a' => 'Zitrus-Ceviche', 'b' => 'Schoko-Tarte', 'score' => 12, 'type' => 'aroma']];

    expect($svc->menuKohaesionWarnung($mk(30))['text'])->toContain('Zitrus-Ceviche ↔ Schoko-Tarte')
        ->and($svc->menuKohaesionWarnung($mk(80))['text'])->not->toContain('Schwächste Brücke');
});

it('menuKohaesionWarnung liefert null, wenn nichts zu beurteilen ist (T9)', function () {
    $svc = app(PairingService::class);

    // zu wenig Gerichte (Panel-Fallback-Dict)
    expect($svc->menuKohaesionWarnung(['zu_wenig' => true, 'score' => 0, 'rated_pairs' => 0]))->toBeNull()
        // kein bewertetes Paar (Graph sieht die Folge nicht) — keine Aussage, nicht „schlecht"
        ->and($svc->menuKohaesionWarnung(['score' => 0, 'rated_pairs' => 0, 'total_pairs' => 1, 'weakest_pair' => null]))->toBeNull();
});

it('Gate über echte Menü-Daten: unverbundene Gänge = kein bewertetes Paar = keine Warnung', function () {
    // Räucherwaren (Gang 1) und Zitrus (Gang 2) haben KEINE Kante zueinander im
    // Test-Graph → menuCohesion rated_pairs=0 → das Gate schweigt ehrlich.
    $lachs = ($this->mkGericht)('r-lachs', 'HG: Räucherlachs', 'Räucherlachs', 'raeucherwaren');
    $ceviche = ($this->mkGericht)('r-ceviche', 'VS: Zitrus-Ceviche', 'Limette', 'zitrus');

    $svc = app(PairingService::class);
    $kohaesion = $svc->menuCohesion([$lachs, $ceviche]);

    expect($kohaesion['rated_pairs'])->toBe(0)
        ->and($svc->menuKohaesionWarnung($kohaesion))->toBeNull();
});

// ── IdeenService::kiDivergenzConcept — die Verdrahtung in den LLM-Kontext ────

/** Provider-Stub, der jede User-Nachricht (= JSON-Kontext) über $capture mitschneidet. */
function bindKohaesionSpy(callable $capture): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(\Platform\Core\Contracts\LLMProviderContract::class, fn () => new class($capture) implements \Platform\Core\Contracts\LLMProviderContract
    {
        /** @var callable */
        private $capture;

        public function __construct(callable $capture)
        {
            $this->capture = $capture;
        }

        public function chat(array $messages, array $options = []): array
        {
            foreach ($messages as $m) {
                if (($m['role'] ?? null) === 'user') {
                    ($this->capture)((string) ($m['content'] ?? ''));
                }
            }

            return ['content' => json_encode(['werte' => ['ideen' => [['titel' => 'Fenchel-Dill-Sud']]], 'confidence' => 0.8]),
                'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }

        public function getName(): string { return 'kohaesion-spy'; }
        public function streamChat(array $messages, callable $onDelta, array $options = []): void {}
        public function getAvailableModels(): array { return ['stub']; }
        public function getDefaultModel(): string { return 'stub'; }
        public function isAvailable(): bool { return true; }
    });
}

it('kiDivergenzConcept spielt das Menü-Aroma-Profil in den Prompt, wenn Slots belegt sind', function () {
    $lachs = ($this->mkGericht)('r-lachs', 'HG: Räucherlachs', 'Räucherlachs', 'raeucherwaren');
    $concept = $this->makeConcept($this->rootTeam, 'Fjord-Menü');
    $this->makeConceptSlot($concept, ['position' => 1, 'sales_recipe_id' => $lachs->id]);

    $prompts = [];
    bindKohaesionSpy(function (string $c) use (&$prompts) { $prompts[] = $c; });
    app(IdeenService::class)->kiDivergenzConcept($this->rootTeam, $concept->id, 3);

    $joined = implode("\n", $prompts);
    expect($prompts)->not->toBeEmpty()                 // der Provider wurde wirklich gerufen
        ->and($joined)->toContain('menue_kohaesion')
        ->and($joined)->toContain('Räucherwaren')      // getragener Anker
        ->and($joined)->toContain('Dill');             // harmonischer Partner
});

it('kiDivergenzConcept bleibt ohne Kohäsions-Block, wenn das Menü noch leer ist', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Leeres Menü');
    $this->makeConceptSlot($concept, ['position' => 1, 'sales_recipe_id' => null, 'wording' => null]);

    $prompts = [];
    bindKohaesionSpy(function (string $c) use (&$prompts) { $prompts[] = $c; });
    app(IdeenService::class)->kiDivergenzConcept($this->rootTeam, $concept->id, 2);

    expect($prompts)->not->toBeEmpty()                 // Provider lief — Abwesenheit ist echt, nicht vakuant
        ->and(implode("\n", $prompts))->not->toContain('menue_kohaesion');
});
