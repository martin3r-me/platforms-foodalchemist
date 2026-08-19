<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Services\SensorikService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 03 L7a — der Kaskaden-Motor der One-Shot-Vollerstellung.
 *
 * Zu beweisen ist die Grenze, an der L7 sich von „✨ Alles anreichern" unterscheidet:
 * die Kaskade übernimmt SELBST, darf dafür aber nur LEERE Ziel-Felder anfassen
 * (GL-07 — Auto-Persistenz ist gegen menschliche Pflege verboten, nicht gegen
 * Leerstellen), fährt dabei die bestehende BulkEnrichService-Strecke inklusive
 * Vorschlags-Speicher, und bringt bei Provider-Ausfall nie das Rezept mit runter.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->svc = app(RecipeOneShotService::class);

    $hg = FoodAlchemistRecipeMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'FND', 'label' => 'Fonds & Saucen']);
    $this->kategorie = FoodAlchemistRecipeCategory::create([
        'team_id' => $this->rootTeam->id, 'main_group_id' => $hg->id, 'code' => 'RED', 'label' => 'Reduktionen',
    ]);

    // Ein Stub, der die Ober-Menge aller Feld-Keys liefert; jeder Schritt zieht
    // sich mit seinem eigenen `$extract` heraus, was er braucht.
    $katId = $this->kategorie->id;
    $this->stub = function () use ($katId) {
        app()->singleton(FakeAiProvider::class, fn () => new class($katId) extends FakeAiProvider
        {
            public function __construct(private int $katId)
            {
            }

            public function chat(array $messages, array $options = []): array
            {
                return ['content' => json_encode(['werte' => [
                    'description' => 'Dunkle, sirupartige Saucenbasis.',
                    'category_id' => $this->katId,
                    'taste_direction' => 'herzhaft',
                ], 'confidence' => 0.82]), 'model' => 'fake-oneshot', 'usage' => []];
            }
        });
    };

    $this->basis = fn (array $attr = []) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'oneshot-' . bin2hex(random_bytes(4)),
        'name' => 'Reduktion: Rotwein-Schalotte', 'status' => 'draft', ...$attr,
    ]);
});

it('L7a: die Schrittfolge wird auf LÜCKEN geschnitten — was der Generator schon schrieb, wird nicht erneut bezahlt', function () {
    // So sieht ein frisch generiertes Basisrezept aus: description steht (Lineage ki),
    // Kategorie und Geschmacksrichtung sind offen.
    $r = ($this->basis)(['description' => 'Vom Generator.', 'description_source' => 'ki']);

    $offen = app(BulkEnrichService::class)->luecken($r, BulkEnrichService::SCHRITTE);

    expect($offen)->toBe(['category', 'geschmack'])
        ->and(BulkEnrichService::ZIELFELDER['category']['feld'])->toBe('category_id');
});

it('L7a: die Kaskade füllt die Lücken selbst — mit Lineage, ohne die Bestandsfelder anzufassen', function () {
    ($this->stub)();
    $r = ($this->basis)(['description' => 'Vom Generator.', 'description_source' => 'ki']);

    $erg = $this->svc->anreichern($this->rootTeam, $r);

    expect($erg['schritte'])->toBe(['category', 'geschmack'])
        ->and($erg['uebersprungen'])->toBe(['description'])
        ->and($erg['uebernommen'])->toBe(2)
        ->and($erg['offen'])->toBe(0)
        ->and($erg['fehler'])->toBeNull();

    $frisch = $r->fresh();
    expect((int) $frisch->category_id)->toBe((int) $this->kategorie->id)
        ->and($frisch->category_source)->toBe('ki')
        ->and($frisch->taste_direction)->toBe('herzhaft')
        ->and($frisch->description)->toBe('Vom Generator.')            // unangetastet
        ->and($frisch->status->value)->toBe('draft');                     // Vollerstellung ≠ Freigabe

    // Die Strecke ist die bestehende: Lauf-Zeile + Vorschlags-Speicher (Audit),
    // nur ohne den zweiten Job.
    $lauf = app(BulkEnrichService::class)->status($this->rootTeam, $erg['run_id']);
    expect($lauf->type)->toBe(BulkRunType::Enrich)
        ->and($lauf->status)->toBe(BulkRunStatus::Done)
        ->and((int) $lauf->failed)->toBe(0)
        ->and(DB::table('foodalchemist_bulk_proposals')->where('run_id', $erg['run_id'])
            ->where('status', 'uebernommen')->count())->toBe(2);
});

it('L7a: ein von Hand gepflegtes Feld erzeugt gar keinen Vorschlag — es kostet nicht einmal einen Call', function () {
    ($this->stub)();
    $r = ($this->basis)([
        'description' => 'Handarbeit.', 'description_source' => 'manual',
        'category_id' => $this->kategorie->id, 'category_source' => 'manual',
        'taste_direction' => 'suess',
    ]);

    $erg = $this->svc->anreichern($this->rootTeam, $r);

    expect($erg['schritte'])->toBe([])
        ->and($erg['run_id'])->toBeNull()                               // kein Lauf ⇒ kein Provider-Call
        ->and($erg['uebernommen'])->toBe(0)
        ->and(DB::table('foodalchemist_bulk_runs')->count())->toBe(0)
        ->and(DB::table('foodalchemist_ai_call_log')->count())->toBe(0);

    expect($r->fresh()->description)->toBe('Handarbeit.')
        ->and($r->fresh()->taste_direction)->toBe('suess');
});

it('KI-Erstellknopf: Complete-Coverage zieht fehlende Schritte und Sensorik gezielt nach', function () {
    $this->mock(AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->once()->with('recipe.production_depth', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal(['production_depth' => 'from_scratch'], 0.75, 'Mock', [], 'fertigung-mock'));
        $mock->shouldReceive('propose')->once()->with('recipe.eigenschaften', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal(['work_time_min' => 25, 'temperature' => 'warm', 'function' => 'Saucenbasis'], 0.78, 'Mock', [], 'eigenschaften-mock'));
        $mock->shouldReceive('propose')->once()->with('recipe.equipment', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal(['equipment_slugs' => []], 0.72, 'Mock', [], 'equipment-mock'));
        $mock->shouldReceive('propose')->once()->with('recipe.steps', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal([
                'steps' => [
                    ['phase' => 'Mise en Place', 'text' => 'Schalotten fein schneiden.'],
                    ['phase' => 'Kochen', 'text' => 'Mit Rotwein sirupartig reduzieren.'],
                ],
            ], 0.88, 'Mock', [], 'steps-mock'));
        $mock->shouldReceive('propose')->once()->with('recipe.sensorik', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal([
                'geschmack' => array_merge(array_fill_keys(SensorikService::DIMS, 0.0), [
                    'sauer' => 0.35, 'umami' => 0.55,
                ]),
                'texturen' => ['sirupartig'],
            ], 0.81, 'Mock', [], 'sensorik-mock'));
    });

    $r = $this->makeRecipe($this->rootTeam, 'Reduktion: Rotwein-Schalotte', [
        'status' => 'draft',
        'description' => 'Steht.',
        'description_source' => 'ki',
        'category_id' => $this->kategorie->id,
        'category_source' => 'ki',
        'taste_direction' => 'herzhaft',
    ]);
    $this->makeIngredient($r, 'Schalotte', $this->makeGp($this->rootTeam, 'Schalotte-coverage'), '200', 1);

    $erg = app(RecipeOneShotService::class)->anreichern($this->rootTeam, $r->refresh(), completeCoverage: true);

    expect($erg['schritte'])->toBe([])
        ->and($erg['coverage']['steps']['status'])->toBe('erstellt')
        ->and($erg['coverage']['steps']['n_steps'])->toBe(2)
        ->and($erg['coverage']['fertigung']['status'])->toBe('aktualisiert')
        ->and($erg['coverage']['eigenschaften']['status'])->toBe('aktualisiert')
        ->and($erg['coverage']['sensorik']['status'])->toBe('bewertet');

    expect(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::where('recipe_id', $r->id)->count())->toBe(2)
        ->and(DB::table('foodalchemist_recipe_taste_vectors')->where('recipe_id', $r->id)->exists())->toBeTrue()
        ->and($r->fresh()->description)->toBe('Steht.');
});

it('Voll anreichern synchronisiert bestehende Step-by-step-Anleitung und Sensorik neu', function () {
    $this->mock(AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->once()->with('recipe.production_depth', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal(['production_depth' => 'teilfertig'], 0.75, 'Mock', [], 'fertigung-refresh'));
        $mock->shouldReceive('propose')->once()->with('recipe.eigenschaften', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal(['work_time_min' => 12, 'temperature' => 'kalt', 'function' => 'Finish'], 0.78, 'Mock', [], 'eigenschaften-refresh'));
        $mock->shouldReceive('propose')->once()->with('recipe.equipment', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal(['equipment_slugs' => []], 0.72, 'Mock', [], 'equipment-refresh'));
        $mock->shouldReceive('propose')->once()->with('recipe.steps', \Mockery::on(function (array $payload) {
            return ($payload['modus'] ?? null) === 'voll_anreichern_ueberschreiben'
                && ($payload['schritte_bestand'][0]['text'] ?? null) === 'Alte Anleitung.';
        }), \Mockery::any())
            ->andReturn(new AiProposal([
                'steps' => [
                    ['phase' => 'Neu', 'text' => 'Neue Zutaten vorbereiten.'],
                    ['phase' => 'Neu', 'text' => 'Neue Zubereitung abschmecken.'],
                ],
            ], 0.9, 'Mock', [], 'steps-refresh'));
        $mock->shouldReceive('propose')->once()->with('recipe.sensorik', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal([
                'geschmack' => array_merge(array_fill_keys(SensorikService::DIMS, 0.0), [
                    'salzig' => 0.7, 'umami' => 0.4,
                ]),
                'texturen' => ['knackig'],
            ], 0.82, 'Mock', [], 'sensorik-refresh'));
    });

    $r = $this->makeRecipe($this->rootTeam, 'Aktualisiertes Rezept', [
        'status' => 'draft',
        'description' => 'Bleibt.',
        'description_source' => 'ki',
        'category_id' => $this->kategorie->id,
        'category_source' => 'ki',
        'taste_direction' => 'herzhaft',
        'preparation' => 'Alte Freitext-Zubereitung.',
    ]);
    $this->makeIngredient($r, 'Fleur de Sel', $this->makeGp($this->rootTeam, 'Fleur de Sel-refresh'), '2', 1);
    app(\Platform\FoodAlchemist\Services\RecipeStepService::class)->sync($r, [['phase' => 'Alt', 'text' => 'Alte Anleitung.']]);
    DB::table('foodalchemist_recipe_taste_vectors')->insert([
        'recipe_id' => $r->id,
        'source' => 'manual',
        'source_hash' => 'old',
        'suess' => 1,
        'salzig' => 0,
        'sauer' => 0,
        'bitter' => 0,
        'umami' => 0,
        'fettig' => 0,
        'scharf' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $erg = app(RecipeOneShotService::class)->anreichern($this->rootTeam, $r->refresh(), completeCoverage: true);

    expect($erg['coverage']['steps']['status'])->toBe('aktualisiert')
        ->and($erg['coverage']['steps']['n_steps'])->toBe(2)
        ->and($erg['coverage']['fertigung']['status'])->toBe('aktualisiert')
        ->and($erg['coverage']['eigenschaften']['status'])->toBe('aktualisiert')
        ->and($erg['coverage']['sensorik']['status'])->toBe('bewertet');

    $steps = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::where('recipe_id', $r->id)
        ->orderBy('position')->pluck('text')->all();
    $taste = DB::table('foodalchemist_recipe_taste_vectors')->where('recipe_id', $r->id)->first();

    expect($steps)->toBe(['Neue Zutaten vorbereiten.', 'Neue Zubereitung abschmecken.'])
        ->and($taste->source)->toBe('ai')
        ->and((float) $taste->salzig)->toBe(0.7)
        ->and((float) $taste->suess)->toBe(0.0)
        ->and($r->fresh()->work_time_min)->toBe(12)
        ->and($r->fresh()->production_depth)->toBe('teilfertig')
        ->and($r->fresh()->description)->toBe('Bleibt.');
});

it('Voll anreichern synchronisiert operative Detail-Felder: Equipment, Posten und Prozessanker', function () {
    \Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment::create([
        'team_id' => $this->rootTeam->id,
        'slug' => 'kombi',
        'name' => 'Kombidämpfer',
    ]);
    $posten = \Platform\FoodAlchemist\Models\FoodAlchemistProductionStation::create([
        'team_id' => $this->rootTeam->id,
        'slug' => 'warme-kueche',
        'name' => 'Warme Küche',
        'batch_max_kg' => 8,
    ]);
    foreach (['roestaromen', 'karamell', 'rauch', 'ferment', 'basilikum'] as $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->updateOrInsert(
            ['slug' => $slug],
            [
                'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
                'display_de' => ucfirst($slug),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
    $roest = DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', 'roestaromen')->value('id');
    $basilikum = DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', 'basilikum')->value('id');
    DB::table('foodalchemist_pairing_anchor_edges')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'anchor_a_id' => $roest, 'anchor_b_id' => $basilikum, 'type' => 'aroma',
        'evidence' => 'Test-Grounding', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->mock(AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->once()->with('recipe.production_depth', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal(['production_depth' => 'from_scratch'], 0.75, 'Mock', [], 'fertigung-op'));
        $mock->shouldReceive('propose')->once()->with('recipe.eigenschaften', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal(['work_time_min' => 90, 'setup_time_min' => 12, 'max_vorlauf_tage' => 3, 'temperature' => 'warm', 'function' => 'Warme Küche Ansatz'], 0.78, 'Mock', [], 'eigenschaften-op'));
        $mock->shouldReceive('propose')->once()->with('recipe.equipment', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal(['equipment_slugs' => ['kombi']], 0.72, 'Mock', [], 'equipment-op'));
        $mock->shouldReceive('propose')->once()->with('recipe.steps', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal(['steps' => [
                ['phase' => 'Garen', 'text' => 'Im Kombidämpfer anbraten und schmoren.'],
            ]], 0.9, 'Mock', [], 'steps-op'));
        $mock->shouldReceive('propose')->once()->with('recipe.sensorik', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal([
                'geschmack' => array_merge(array_fill_keys(SensorikService::DIMS, 0.0), ['umami' => 0.6]),
                'texturen' => ['weich'],
            ], 0.82, 'Mock', [], 'sensorik-op'));
        $mock->shouldReceive('propose')->once()->with('recipe.anker', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal(['anker_slugs' => ['roestaromen']], 0.86, 'Mock', [], 'anker-op'));
        $mock->shouldReceive('propose')->once()->with('recipe.pairing', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal(['pairings' => [['slug' => 'basilikum', 'typ' => 'aroma', 'konfidenz' => 'hoch']]], 0.84, 'Mock', [], 'pairing-op'));
        $mock->shouldReceive('propose')->once()->with('recipe.sektor', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal(['sektoren' => ['restaurant' => ['eignung' => 'geeignet', 'grund' => 'Produktionsstabil.'], 'care' => ['eignung' => 'ungeeignet']]], 0.8, 'Mock', [], 'sektor-op'));
        $mock->shouldReceive('propose')->once()->with('recipe.level', \Mockery::any(), \Mockery::any())
            ->andReturn(new AiProposal(['niveaus' => ['klassisch' => ['eignung' => 'geeignet', 'grund' => 'Klassische Technik.']]], 0.8, 'Mock', [], 'level-op'));
    });

    $r = $this->makeRecipe($this->rootTeam, 'Brauner Fond', [
        'status' => 'draft',
        'description' => 'Steht.',
        'description_source' => 'ki',
        'category_id' => $this->kategorie->id,
        'category_source' => 'ki',
        'taste_direction' => 'herzhaft',
    ]);
    $this->makeIngredient($r, 'Knochen', $this->makeGp($this->rootTeam, 'Knochen-op'), '1000', 1);

    $erg = app(RecipeOneShotService::class)->anreichern($this->rootTeam, $r->refresh(), completeCoverage: true);
    $frisch = $r->fresh();

    expect($erg['coverage']['equipment']['status'])->toBe('aktualisiert')
        ->and($erg['coverage']['posten']['status'])->toBe('aktualisiert')
        ->and($erg['coverage']['prozessanker']['matched'])->toContain('roestaromen')
        ->and($erg['coverage']['aromaanker']['n_anker'])->toBe(1)
        ->and($erg['coverage']['pairings']['n_pairings'])->toBe(1)
        ->and($erg['coverage']['eignung']['n_level'])->toBe(1)
        ->and($erg['coverage']['eignung']['n_sektor'])->toBe(1)
        ->and($frisch->equipment()->pluck('slug')->all())->toBe(['kombi'])
        ->and((int) $frisch->default_station_id)->toBe((int) $posten->id)
        ->and((float) $frisch->batch_max_kg)->toBe(8.0)
        ->and($frisch->work_time_min)->toBe(90)
        ->and($frisch->setup_time_min)->toBe(12)
        ->and($frisch->max_vorlauf_tage)->toBe(3)
        ->and(DB::table('foodalchemist_recipe_pairings')->where('recipe_id', $r->id)->whereNull('deleted_at')->value('created_via'))->toBe('ai_gateway')
        ->and(DB::table('foodalchemist_recipe_sector_suitability')->where('recipe_id', $r->id)->whereNull('deleted_at')->value('sector_slug'))->toBe('restaurant')
        ->and(DB::table('foodalchemist_recipe_level_suitability')->where('recipe_id', $r->id)->whereNull('deleted_at')->value('level_slug'))->toBe('klassisch');
});

it('L7a: die Ebene entscheidet das is_sales_recipe-Flag — ein Gericht bekommt die VK-Schrittfolge', function () {
    $vk = ($this->basis)(['is_sales_recipe' => true, 'name' => 'TEL: Rinderrücken | Jus']);

    $offen = app(BulkEnrichService::class)->luecken($vk, BulkEnrichService::SCHRITTE_VK);

    expect($offen)->toBe(['description', 'wording', 'plating', 'speisen_klasse'])
        ->and($offen)->not->toContain('category');                      // 186er-Kategorie ist Basisrezept-Ebene
});

it('L7a: Provider-Ausfall mitten in der Kaskade lässt das Rezept vollständig zurück — nie ein halbes Wrack', function () {
    app()->singleton(FakeAiProvider::class, fn () => new class extends FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            throw new \RuntimeException('Provider weg.');
        }
    });
    $r = ($this->basis)(['description' => 'Vom Generator.', 'description_source' => 'ki']);

    $erg = $this->svc->anreichern($this->rootTeam, $r);

    expect($erg['fehler'])->toBeNull()                                  // der Pass wirft nicht nach außen
        ->and($erg['uebernommen'])->toBe(0)
        ->and((int) app(BulkEnrichService::class)->status($this->rootTeam, $erg['run_id'])->failed)->toBe(1)
        ->and(DB::table('foodalchemist_bulk_proposals')->where('run_id', $erg['run_id'])
            ->whereNotNull('error')->count())->toBe(2);

    $frisch = $r->fresh();
    expect($frisch)->not->toBeNull()
        ->and($frisch->description)->toBe('Vom Generator.')
        ->and($frisch->status->value)->toBe('draft');
});

// ── L7b-2: das Kohärenz-Glied am Ende der Kaskade ────────────────────────────
//
// Spec-Kaskade: „… → Auto-Enrichment → Kohärenz-Check (VK) → fertig". Die
// Fixtures füllen die vier VK-Ziel-Felder absichtlich vor, damit der
// Anreicherungs-Pass leer läuft (`schritte === []`) — dann steht in den Tests
// wirklich nur das Urteil und nicht der halbe Bulk-Pass.

/** VK-Gericht mit vollständigen Text-Feldern (⇒ keine Anreicherungs-Lücke) + n Komponenten. */
$vkFertig = function (object $t, int $komponenten = 2): \Platform\FoodAlchemist\Models\FoodAlchemistRecipe {
    $bau = \Closure::bind(function (int $komponenten) {
        $hg = \Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup::firstOrCreate(['code' => 'TEL'], ['label' => 'Tellergericht']);
        $klasse = \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::firstOrCreate(
            ['dish_main_group_id' => $hg->id, 'code' => 'TEL-OMN'],
            ['label' => 'Teller omnivor', 'diet_form' => 'omnivor'],
        );

        $vk = $this->makeRecipe($this->rootTeam, 'TEL: Rinderrücken | Jus', [
            'is_sales_recipe' => true, 'status' => 'draft',
            'description' => 'Steht.', 'sales_wording_standard' => 'Steht.',
            'plating_text' => 'Steht.', 'dish_class_id' => $klasse->id,
            'taste_direction' => 'herzhaft',
        ]);
        for ($i = 1; $i <= $komponenten; $i++) {
            $this->makeIngredient($vk, 'Komponente ' . $i, $this->makeGp($this->rootTeam, 'Komponente ' . $i . '-' . $vk->id), '100', $i);
        }

        return $vk->refresh();
    }, $t, $t::class);

    return $bau($komponenten);
};

it('L7b-2: das Kohärenz-Urteil läuft auch dann, wenn es NICHTS anzureichern gibt — es hängt an den Komponenten, nicht am Pass', function () use ($vkFertig) {
    $this->mock(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->once()->with('vk.kohaerenz', \Mockery::any())
            ->andReturn(new \Platform\FoodAlchemist\Services\Ai\AiProposal(
                ['score' => 78, 'label' => 'Klassisch geschlossen', 'reasoning' => 'Jus bindet.', 'schwachstelle' => 'Säure fehlt'],
                0.9, 'Mock', [], 'judge-mock',
            ));
    });
    $vk = $vkFertig($this);

    $erg = app(RecipeOneShotService::class)->anreichern($this->rootTeam, $vk);

    // Kein Anreicherungs-Lauf (alle Ziel-Felder belegt) — und trotzdem ein Urteil.
    expect($erg['schritte'])->toBe([])
        ->and($erg['run_id'])->toBeNull()
        ->and($erg['kohaerenz_urteil'])->toBe([
            'score' => 78, 'label' => 'Klassisch geschlossen', 'schwachstelle' => 'Säure fehlt', 'fehler' => null,
        ]);

    // Das Urteil ist gecacht (GL-10-Cache, keine zweite Wahrheit) und frisch.
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeCulinaryCoherence::where('recipe_id', $vk->id)->count())->toBe(1)
        ->and(app(\Platform\FoodAlchemist\Services\CoherenceService::class)->status($this->rootTeam, $vk->id)['stale'])->toBeFalse();
});

it('L7b-2: ein Basisrezept bekommt kein Teller-Urteil — und bezahlt dafür auch keinen Call', function () {
    $this->mock(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->never();
    });
    $basis = $this->makeRecipe($this->rootTeam, 'Reduktion: Rotwein-Schalotte', [
        'description' => 'Steht.', 'category_id' => $this->kategorie->id, 'taste_direction' => 'herzhaft',
    ]);
    $this->makeIngredient($basis, 'Schalotte', $this->makeGp($this->rootTeam, 'Schalotte-basis'), '200', 1);
    $this->makeIngredient($basis, 'Rotwein', $this->makeGp($this->rootTeam, 'Rotwein-basis'), '500', 2);

    $erg = app(RecipeOneShotService::class)->anreichern($this->rootTeam, $basis->refresh());

    expect($erg['kohaerenz_urteil'])->toBeNull()
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeCulinaryCoherence::count())->toBe(0);
});

it('L7b-2: ein Gericht mit EINER Komponente hat kein Zusammenspiel — kein Urteil, kein Call', function () use ($vkFertig) {
    $this->mock(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->never();
    });
    $vk = $vkFertig($this, 1);

    $erg = app(RecipeOneShotService::class)->anreichern($this->rootTeam, $vk);

    expect($erg['kohaerenz_urteil'])->toBeNull()
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeCulinaryCoherence::count())->toBe(0);
});

it('L7b-2: ein Judge ohne verwertbaren Score wird zur ehrlichen Lücke — nicht zum Generierungs-Fehler', function () use ($vkFertig) {
    // FakeProvider-Grenze: `judge()` wirft absichtlich und cacht nichts.
    $this->mock(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->andReturn(
            new \Platform\FoodAlchemist\Services\Ai\AiProposal(['label' => 'ohne Score'], 0.4, 'Mock', [], 'judge-mock'),
        );
    });
    $vk = $vkFertig($this);

    $erg = app(RecipeOneShotService::class)->anreichern($this->rootTeam, $vk);

    expect($erg['fehler'])->toBeNull()                                  // die Kaskade selbst ist nicht gescheitert
        ->and($erg['kohaerenz_urteil']['score'])->toBeNull()
        ->and($erg['kohaerenz_urteil']['fehler'])->toContain('score')
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeCulinaryCoherence::count())->toBe(0);

    expect($vk->fresh()->status->value)->toBe('draft');                 // Rezept unversehrt
});
