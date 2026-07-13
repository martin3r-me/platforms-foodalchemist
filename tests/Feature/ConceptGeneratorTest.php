<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Symfony\Component\Uid\UuidV7;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R6.1 — Brief → Konzept mit Kohäsions-Beweis: ausschließlich echte VK-Gerichte,
 * Slot ohne Treffer bleibt leer mit Begründung, No-Gos hart, Pairing-Graph rankt,
 * Gerüst wandert als Kopie ans Konzept, Coverage läuft automatisch, Draft+Lineage.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ConceptGeneratorService::class);
    $this->frames = app(PlanningFrameService::class);

    // Anker + Kante: tomate ↔ basilikum (erprobt), vanille isoliert
    $mkAnker = function (string $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $tomate = $mkAnker('tomate');
    $basilikum = $mkAnker('basilikum');
    $vanille = $mkAnker('vanille');
    foreach ([[$tomate, $basilikum], [$basilikum, $tomate]] as [$a, $b]) {
        DB::table('foodalchemist_pairing_anchor_edges')->insert([
            'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $a, 'anchor_b_id' => $b,
            'type' => 'erprobt', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // GPs mit Kern-Anker + VK-Gerichte mit je einer Zutat
    $this->g = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $hg = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'HG', 'label' => 'Hauptgericht']);
    $this->klasseVegan = FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'dish_main_group_id' => $hg->id, 'code' => 'HG_V', 'label' => 'Vegan', 'diet_form' => 'vegan']);
    $this->klasseFleisch = FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'dish_main_group_id' => $hg->id, 'code' => 'HG_F', 'label' => 'Fleisch', 'diet_form' => 'fleisch']);

    $mkGericht = function (string $key, string $name, string $gpName, ?int $ankerId, int $klasseId, float $preis) {
        $gp = $this->makeGp($this->rootTeam, $gpName);
        if ($ankerId !== null) {
            DB::table('foodalchemist_gp_anchor_mappings')->insert([
                'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
                'gp_id' => $gp->id, 'anchor_id' => $ankerId, 'role' => 'kern',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $r = FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name, 'status' => 'approved',
            'is_sales_recipe' => true, 'sales_net' => $preis, 'dish_class_id' => $klasseId,
        ]);
        $r->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $gp->id, 'raw_text' => $gpName, 'quantity' => 100, 'unit_vocab_id' => $this->g->id]);

        return $r;
    };
    $this->gnocchi = $mkGericht('gnocchi', 'HG: Basilikum-Gnocchi', 'Basilikum', $basilikum, $this->klasseVegan->id, 13.00);
    $this->risotto = $mkGericht('risotto', 'HG: Tomaten-Risotto', 'Tomate', $tomate, $this->klasseFleisch->id, 14.00);
    $this->haehnchen = $mkGericht('haehnchen', 'HG: Vanille-Hähnchen', 'Vanille', $vanille, $this->klasseFleisch->id, 14.00);
    $this->leber = $mkGericht('leber', 'HG: Kalbsleber Berliner Art', 'Kalbsleber', null, $this->klasseFleisch->id, 18.00);

    // Quell-Gerüst am Foodbook: Hauptgang 2 (mind. 1 vegan) + unerfüllbarer Pflicht-Slot + No-Go Leber
    $this->fb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Sommer-FB']);
    $this->frame = $this->frames->frameFor($this->rootTeam, 'foodbook', $this->fb->id);
    $slot = $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 2]);
    $this->frames->addRule($this->rootTeam, $this->frame, ['slot_id' => $slot->id, 'rule_type' => 'diet_quota', 'ref_key' => 'vegan', 'operator' => 'min', 'value_num' => 1, 'unit' => 'count']);
    $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Dessert', 'slot_type' => 'gang', 'is_pflicht' => true, 'price_min' => 50]);
    $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'nogo_ingredient', 'value_text' => 'Leber']);
});

it('Gerüst-Pfad: nur echte Gerichte, No-Go hart, Graph rankt, leerer Slot mit Begründung, Draft+Lineage', function () {
    $e = $this->svc->generiereAusGeruest($this->rootTeam, $this->frame, 'Sommer-Konzept');
    $concept = $e['concept'];

    // Draft + Lineage
    expect($concept->status)->toBe('draft')
        ->and($concept->created_via)->toBe('concept_generator_ui')
        ->and($concept->name)->toBe('Sommer-Konzept');

    // Hauptgang: 2 Gerichte — Quote vegan zuerst (Gnocchi), dann Graph-Ranking:
    // Risotto (tomate↔basilikum-Kante) schlägt das gleich teure Vanille-Hähnchen (keine Kante)
    $hauptgang = collect($e['protokoll'])->firstWhere('slot', 'Hauptgang');
    $namen = collect($hauptgang['gerichte'])->pluck('name')->all();
    expect($hauptgang['status'])->toBe('befuellt')
        ->and($namen)->toContain('HG: Basilikum-Gnocchi')
        ->and($namen)->toContain('HG: Tomaten-Risotto')
        ->and($namen)->not->toContain('HG: Vanille-Hähnchen')
        ->and($namen)->not->toContain('HG: Kalbsleber Berliner Art'); // No-Go hart

    // Dessert: kein Gericht ≥50 € → LEER mit Begründung am Slot (nie halluziniert)
    $dessert = collect($e['protokoll'])->firstWhere('slot', 'Dessert');
    expect($dessert['status'])->toBe('leer')->and($dessert['begruendung'])->toContain('bewusst leer');
    $leererSlot = FoodAlchemistConceptSlot::where('concept_id', $concept->id)->whereNull('sales_recipe_id')->whereNull('package_id')->first();
    expect($leererSlot->note)->toContain('Kein VK-Gericht erfüllt');

    // Alle befüllten Slots referenzieren echte Rezepte des Teams
    $ids = FoodAlchemistConceptSlot::where('concept_id', $concept->id)->whereNotNull('sales_recipe_id')->pluck('sales_recipe_id');
    expect(FoodAlchemistRecipe::whereIn('id', $ids)->count())->toBe($ids->count());

    // Gerüst-Kopie hängt am Konzept, Coverage lief automatisch, Kohäsion trägt den Kanten-Beweis
    $kopie = $this->frames->find('concept', $concept->id);
    expect($kopie)->not->toBeNull()
        ->and($kopie->slots()->count())->toBe(2)
        ->and($e['coverage']['hat_geruest'])->toBeTrue()
        ->and($e['kohaesion']['score'])->toBeGreaterThan(0)
        ->and($e['kohaesion']['rated_pairs'])->toBeGreaterThan(0);
});

it('menuCohesion: Kanten-Paar ergibt Score + schwächstes Paar, isoliertes Gericht wird ehrlich unbewertet', function () {
    $koh = app(PairingService::class)->menuCohesion([$this->gnocchi, $this->risotto, $this->haehnchen]);
    expect($koh['score'])->toBeGreaterThan(0)
        ->and($koh['rated_pairs'])->toBe(1)                 // nur gnocchi↔risotto hat eine Kante
        ->and($koh['total_pairs'])->toBe(3)
        ->and(count($koh['unrated_pairs']))->toBe(2);       // hähnchen-Paare: keine Graph-Daten
});

it('Brief-Pfad: KI baut das Gerüst (Provider-Stub), Assembler bleibt deterministisch, alles Draft', function () {
    // Provider-Stub liefert ein kontrolliertes Gerüst-JSON (kein echter LLM)
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class implements LLMProviderContract
    {
        public function getName(): string
        {
            return 'test-stub';
        }

        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => [
                'name' => 'Gartenfest',
                'target_price_pp' => 20,
                'slots' => [[
                    'label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1,
                    'rules' => [['rule_type' => 'diet_quota', 'ref_key' => 'vegan', 'operator' => 'min', 'value_num' => 1, 'unit' => 'count']],
                ]],
                'rules' => [
                    ['rule_type' => 'nogo_ingredient', 'value_text' => 'Leber', 'severity' => 'hart'],
                    ['rule_type' => 'kaputt_erfunden', 'value_text' => 'wird verworfen'],
                ],
            ], 'confidence' => 0.9, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void {}

        public function getAvailableModels(): array
        {
            return ['stub'];
        }

        public function getDefaultModel(): string
        {
            return 'stub';
        }

        public function isAvailable(): bool
        {
            return true;
        }
    });
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);

    $e = $this->svc->generiereAusBrief($this->rootTeam, 'Sommerfest, 80 Gäste, vegan wichtig, bitte keine Leber, ca. 20 € p. P.');
    $concept = $e['concept'];

    expect($concept->status)->toBe('draft')
        ->and($concept->created_via)->toBe('concept_generator_brief_ui')
        ->and($concept->name)->toBe('Gartenfest')
        ->and($concept->description)->toContain('Sommerfest');

    // Gerüst hängt am Konzept (KI-Rahmen), kaputte KI-Regel wurde verworfen, gültige blieb
    $frame = $this->frames->find('concept', $concept->id);
    expect($frame)->not->toBeNull()
        ->and((float) $frame->target_price_pp)->toBe(20.0)
        ->and($frame->rules()->whereNull('slot_id')->count())->toBe(1);

    // Deterministische Auswahl: vegan-Quote → Gnocchi, echte Gericht-ID
    $hauptgang = collect($e['protokoll'])->firstWhere('slot', 'Hauptgang');
    expect(collect($hauptgang['gerichte'])->pluck('name')->all())->toBe(['HG: Basilikum-Gnocchi'])
        ->and($e['brief_confidence'])->toBe(0.9);
});

it('Slot-Semantik: Dessert-Slot bevorzugt die Dessert-Hauptgruppe vor besser bepreisten HG-Gerichten', function () {
    // Dessert-HG + Dessert-Gericht (ohne Anker, ohne Preisvorteil) — Semantik muss stechen
    $desHg = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'DES', 'label' => 'Dessert']);
    $desKlasse = FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'dish_main_group_id' => $desHg->id, 'code' => 'DES_V', 'label' => 'Dessert vegi', 'diet_form' => 'vegi']);
    FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'pannacotta', 'name' => 'DES: Vanille-Pannacotta', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 6.00, 'dish_class_id' => $desKlasse->id,
    ]);

    $frame2 = $this->frames->frameFor($this->rootTeam, 'concept', app(\Platform\FoodAlchemist\Services\ConceptService::class)->create($this->rootTeam, ['name' => 'Träger'])->id);
    $this->frames->addSlot($this->rootTeam, $frame2, ['label' => 'Dessert', 'slot_type' => 'gang', 'target_count' => 1]);

    $e = $this->svc->generiereAusGeruest($this->rootTeam, $frame2->refresh());
    $dessert = collect($e['protokoll'])->firstWhere('slot', 'Dessert');
    expect(collect($dessert['gerichte'])->pluck('name')->all())->toBe(['DES: Vanille-Pannacotta']);

    // Heuristik-Kernfälle: Hauptgang↔Hauptgericht (Präfix), kein False-Positive bei freien Labels
    expect(\Platform\FoodAlchemist\Services\ConceptGeneratorService::slotSemantik('Hauptgang', 'hauptgericht'))->toBe(1)
        ->and(\Platform\FoodAlchemist\Services\ConceptGeneratorService::slotSemantik('Buffet-Station Süß', 'hauptgericht'))->toBe(0);
});

it('MCP: concepts.GENERATE über Gerüst-Owner + typisierte Fehler ohne Input', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($user, $this->rootTeam);

    $res = $registry->get('foodalchemist.concepts.GENERATE')->execute([
        'geruest_owner_type' => 'foodbook', 'geruest_owner_id' => $this->fb->id, 'name' => 'MCP-Konzept',
    ], $kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['status'])->toBe('draft')
        ->and($res->data['created_via'])->toBe('concept_generator_mcp')
        ->and($res->data['kohaesion']['score'])->toBeGreaterThan(0)
        ->and($res->data['coverage']['ampel_gesamt'])->not->toBeNull()
        ->and(collect($res->data['protokoll'])->firstWhere('slot', 'Dessert')['status'])->toBe('leer');

    $leer = $registry->get('foodalchemist.concepts.GENERATE')->execute([], $kontext);
    expect($leer->success)->toBeFalse();
});
