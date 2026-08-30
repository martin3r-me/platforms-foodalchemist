<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Composer per MCP: ANKER_SUCHE / KOHAESION / MENUE_KOHAESION (read-only) + seed_anker in recipes.GENERATE. */

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);

    $this->ankerId = [];
    $this->mkAnker = function (string $slug, string $label, ?string $cat = null): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => $label,
            'category' => $cat, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->ankerId[$slug] = (int) DB::getPdo()->lastInsertId();
    };
    $this->mkEdge = function (string $a, string $b, int $level, float $weight): void {
        DB::table('foodalchemist_pairing_anchor_edges')->insert([
            'uuid' => (string) UuidV7::generate(),
            'anchor_a_id' => $this->ankerId[$a], 'anchor_b_id' => $this->ankerId[$b],
            'type' => 'aroma', 'level' => $level, 'weight' => $weight,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->mkGpAmAnker = function (string $key, string $gpName, string $ankerSlug): FoodAlchemistGp {
        $gp = FoodAlchemistGp::create([
            'team_id' => $this->rootTeam->id, 'gp_key' => 'composer|' . $key, 'name' => $gpName, 'status' => 'approved',
        ]);
        DB::table('foodalchemist_gp_anchor_mappings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'gp_id' => $gp->id, 'anchor_id' => $this->ankerId[$ankerSlug], 'role' => 'kern',
            'source' => 'ai_inferred', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $gp;
    };
});

// ── Registry-Smoke ──────────────────────────────────────────────────────────

it('Registry-Smoke: die drei Composer-Tools sind registriert + read-only/safe', function () {
    foreach (['foodalchemist.composer.ANKER_SUCHE', 'foodalchemist.composer.KOHAESION', 'foodalchemist.composer.MENUE_KOHAESION'] as $name) {
        $tool = $this->registry->get($name);
        expect($tool)->not->toBeNull()
            ->and($tool->getName())->toBe($name)
            ->and($tool->getMetadata()['read_only'])->toBeTrue()
            ->and($tool->getMetadata()['risk_level'])->toBe('safe');
    }
});

// ── ANKER_SUCHE ─────────────────────────────────────────────────────────────

it('composer.ANKER_SUCHE: findet Anker per Freitext (id+slug) und schließt Gewählte aus', function () {
    ($this->mkAnker)('rauch', 'Rauch', 'prozess');
    ($this->mkAnker)('vanille', 'Vanille', 'suess');
    ($this->mkAnker)('apfel', 'Apfel', 'frucht');

    $res = $this->registry->get('foodalchemist.composer.ANKER_SUCHE')->execute(['q' => 'rauch'], $this->ctx);
    expect($res->success)->toBeTrue();
    $slugs = array_column($res->data['items'], 'slug');
    expect($slugs)->toContain('rauch')
        ->and($res->data['items'][0])->toHaveKeys(['id', 'slug', 'label'])
        ->and($res->data['kategorien'])->toContain('prozess');

    // Gewählte werden ausgeschlossen
    $res2 = $this->registry->get('foodalchemist.composer.ANKER_SUCHE')
        ->execute(['q' => '', 'gewaehlte_ids' => [$this->ankerId['rauch']]], $this->ctx);
    expect(array_column($res2->data['items'], 'id'))->not->toContain($this->ankerId['rauch']);
});

// ── KOHAESION ───────────────────────────────────────────────────────────────

it('composer.KOHAESION: bewertet eine Anker-Menge + erdet sie auf echte GPs', function () {
    ($this->mkAnker)('rauch', 'Rauch');
    ($this->mkAnker)('vanille', 'Vanille');
    ($this->mkEdge)('rauch', 'vanille', 3, 0.8);            // eine bewertete Kante
    ($this->mkGpAmAnker)('g-rauch', 'Räucherlachs', 'rauch');
    ($this->mkGpAmAnker)('g-vanille', 'Bourbon-Vanille', 'vanille');

    $res = $this->registry->get('foodalchemist.composer.KOHAESION')
        ->execute(['anker_ids' => [$this->ankerId['rauch'], $this->ankerId['vanille']]], $this->ctx);

    expect($res->success)->toBeTrue()
        ->and($res->data['kohaesion']['rated_pairs'])->toBe(1)
        ->and($res->data['kohaesion']['score'])->toBeGreaterThan(0)
        ->and($res->data['bruecken'])->toHaveKey('pairs_total')
        ->and($res->data['hinweis'])->toBeNull();

    // Erdung: beide Anker tragen je einen GP
    $erdungNamen = collect($res->data['erdung'])->flatMap(fn ($e) => array_column($e['gps'], 'name'))->all();
    expect($erdungNamen)->toContain('Räucherlachs')->toContain('Bourbon-Vanille');
});

it('composer.KOHAESION: ein einzelner Anker → nur Erdung + Hinweis, keine Kohäsion', function () {
    ($this->mkAnker)('rauch', 'Rauch');
    ($this->mkGpAmAnker)('g-rauch', 'Räucherlachs', 'rauch');

    $res = $this->registry->get('foodalchemist.composer.KOHAESION')
        ->execute(['anker_ids' => [$this->ankerId['rauch']]], $this->ctx);

    expect($res->success)->toBeTrue()
        ->and($res->data['bruecken'])->toBeNull()
        ->and($res->data['hinweis'])->not->toBeNull()
        ->and($res->data['erdung'][0]['gp_count'])->toBeGreaterThan(0);
});

it('composer.KOHAESION: leere anker_ids → VALIDATION_ERROR', function () {
    $res = $this->registry->get('foodalchemist.composer.KOHAESION')->execute(['anker_ids' => []], $this->ctx);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('VALIDATION_ERROR');
});

// ── MENUE_KOHAESION ─────────────────────────────────────────────────────────

it('composer.MENUE_KOHAESION: misst die Kohäsion mehrerer Gerichte', function () {
    ($this->mkAnker)('rauch', 'Rauch');
    ($this->mkAnker)('vanille', 'Vanille');
    ($this->mkEdge)('rauch', 'vanille', 3, 0.7);
    $gpR = ($this->mkGpAmAnker)('g-rauch', 'Räucherlachs', 'rauch');
    $gpV = ($this->mkGpAmAnker)('g-vanille', 'Bourbon-Vanille', 'vanille');

    $r1 = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'c-r1', 'name' => 'HG: Räucherlachs', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 18.0]);
    $this->makeIngredient($r1, 'Räucherlachs', $gpR, '120', 1);
    $r2 = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'c-r2', 'name' => 'DES: Vanille-Creme', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 9.0]);
    $this->makeIngredient($r2, 'Bourbon-Vanille', $gpV, '80', 1);

    $res = $this->registry->get('foodalchemist.composer.MENUE_KOHAESION')
        ->execute(['recipe_ids' => [$r1->id, $r2->id]], $this->ctx);

    expect($res->success)->toBeTrue()
        ->and($res->data['recipe_ids'])->toHaveCount(2)
        ->and($res->data['fehlende_ids'])->toBe([])
        ->and($res->data['kohaesion'])->toHaveKeys(['score', 'rated_pairs', 'komponenten']);
});

it('composer.MENUE_KOHAESION: weniger als zwei IDs → VALIDATION_ERROR', function () {
    $res = $this->registry->get('foodalchemist.composer.MENUE_KOHAESION')->execute(['recipe_ids' => [1]], $this->ctx);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('VALIDATION_ERROR');
});

// ── seed_anker in recipes.GENERATE ──────────────────────────────────────────

it('recipes.GENERATE: Schema trägt seed_anker (String-Liste)', function () {
    $schema = $this->registry->get('foodalchemist.recipes.GENERATE')->getSchema();
    expect($schema['properties'])->toHaveKey('seed_anker')
        ->and($schema['properties']['seed_anker']['type'])->toBe('array')
        ->and($schema['properties']['seed_anker']['items']['type'])->toBe('string');
});

it('recipes.GENERATE: seed_anker-Slugs landen (gefiltert) im Generierungs-Parameter', function () {
    // Spy-Instanz statt echtem Generator: fängt $parameter ab und bricht nach dem Bau ab.
    $spy = new class
    {
        public $p = null;

        public function generiere($team, $description, $parameter, $x, $vk, $via)
        {
            $this->p = $parameter;
            throw new \RuntimeException('stub-stop');
        }
    };
    app()->instance(RecipeGeneratorService::class, $spy);

    $res = $this->registry->get('foodalchemist.recipes.GENERATE')
        ->execute(['description' => 'Test', 'seed_anker' => ['rauch', ' vanille ', '']], $this->ctx);

    // Der Stub bricht bewusst ab → Tool meldet einen Fehler, aber der Parameter ist gebaut.
    expect($res->success)->toBeFalse()
        ->and($spy->p)->not->toBeNull()
        ->and($spy->p['seed_anker'])->toBe(['rauch', 'vanille']);   // getrimmt, Leerstring raus
});
