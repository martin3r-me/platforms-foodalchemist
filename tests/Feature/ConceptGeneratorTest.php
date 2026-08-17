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

it('Menü-Leitplanken: explizit gesetzter Preis-Korridor je Person überschreibt den KI-Gerüst-Kopf', function () {
    // Provider-Stub liefert einen KI-Vorschlag mit Zielpreis 20 € — die Concept-Tab-Leitplanke gewinnt.
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
                'name' => 'Menü mit Korridor',
                'target_price_pp' => 20,
                'slots' => [['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1]],
            ], 'confidence' => 0.8, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
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
    $this->actingAs($this->makeUser($this->rootTeam));

    // Menü-Achsen wie reglerParams sie liefert (kanonische _pp-Keys); Ziel 35, Spanne 30–45.
    $e = $this->svc->generiereAusBrief(
        $this->rootTeam, 'Galadinner, 60 Gäste, ca. 35 € p. P.', null, 'plan_go', false, false,
        ['menue_preis_ziel_pp' => 35.0, 'menue_preis_min_pp' => 30.0, 'menue_preis_max_pp' => 45.0],
    );

    $frame = $this->frames->find('concept', $e['concept']->id);
    expect($frame)->not->toBeNull()
        ->and((float) $frame->target_price_pp)->toBe(35.0)   // KI-Wert 20 überschrieben
        ->and((float) $frame->price_min_pp)->toBe(30.0)
        ->and((float) $frame->price_max_pp)->toBe(45.0);
});

it('Menü-Leitplanken: ohne Preis-Achsen bleibt der KI-Gerüst-Kopf unangetastet', function () {
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
                'name' => 'Menü ohne Korridor',
                'target_price_pp' => 22,
                'slots' => [['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1]],
            ], 'confidence' => 0.7, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
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
    $this->actingAs($this->makeUser($this->rootTeam));

    // Leere Achsen (Nutzer stellte keinen Korridor ein) → KI-Wert 22 bleibt, keine Spanne.
    $e = $this->svc->generiereAusBrief($this->rootTeam, 'Buffet, 40 Gäste', null, 'plan_go', false, false, []);

    $frame = $this->frames->find('concept', $e['concept']->id);
    expect((float) $frame->target_price_pp)->toBe(22.0)
        ->and($frame->price_min_pp)->toBeNull()
        ->and($frame->price_max_pp)->toBeNull();
});

/** Bindet einen Provider-Stub, der ein KI-Gerüst mit den übergebenen Slots liefert (kein echtes LLM nötig). */
function bindGeruestSlots(array $slots): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($slots) implements LLMProviderContract
    {
        public function __construct(private array $slots) {}

        public function getName(): string
        {
            return 'test-stub';
        }

        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => [
                'name' => 'Gänge-Menü', 'target_price_pp' => 40, 'slots' => $this->slots,
            ], 'confidence' => 0.8, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
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
}

it('Menü-Leitplanken: »Anzahl Gänge« deckelt überzählige gang-Slots auf N (Dramaturgie-Reihenfolge bleibt)', function () {
    // KI liefert 5 Gänge — der Nutzer wollte 3: die ersten drei bleiben in Reihenfolge, der Rest fällt weg.
    bindGeruestSlots([
        ['label' => 'Gruß aus der Küche', 'slot_type' => 'gang', 'target_count' => 1],
        ['label' => 'Vorspeise', 'slot_type' => 'gang', 'target_count' => 1],
        ['label' => 'Zwischengang', 'slot_type' => 'gang', 'target_count' => 1],
        ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1],
        ['label' => 'Dessert', 'slot_type' => 'gang', 'target_count' => 1],
    ]);
    $this->actingAs($this->makeUser($this->rootTeam));

    $e = $this->svc->generiereAusBrief(
        $this->rootTeam, 'Galadinner, 60 Gäste', null, 'plan_go', false, false, ['menue_gaenge' => 3],
    );

    $frame = $this->frames->find('concept', $e['concept']->id);
    expect($frame->slots()->orderBy('position')->pluck('label')->all())
        ->toBe(['Gruß aus der Küche', 'Vorspeise', 'Zwischengang']);
});

it('Menü-Leitplanken: »Anzahl Gänge« lässt station/kapitel-Slots unberührt und ohne Achse bleibt das Gerüst voll', function () {
    // Buffet-Gerüst (2 Stationen) + fehlende Achse: nichts wird gedeckelt (Stationen sind keine Gänge,
    // leeres menue_gaenge = keine Vorgabe). Beweist beide Nicht-Eingriffs-Pfade in einem Lauf.
    bindGeruestSlots([
        ['label' => 'Warme Station', 'slot_type' => 'station', 'target_count' => 3],
        ['label' => 'Süße Station', 'slot_type' => 'station', 'target_count' => 2],
    ]);
    $this->actingAs($this->makeUser($this->rootTeam));

    // menue_gaenge = 1 gesetzt: greift NICHT, weil keine gang-Slots existieren.
    $e = $this->svc->generiereAusBrief(
        $this->rootTeam, 'Buffet, 40 Gäste', null, 'plan_go', false, false, ['menue_gaenge' => 1],
    );

    $frame = $this->frames->find('concept', $e['concept']->id);
    expect($frame->slots()->pluck('label')->sort()->values()->all())
        ->toBe(['Süße Station', 'Warme Station']);
});

it('Concept-Typ Buffet (#35): »Anzahl Stationen« deckelt station-Slots auf N — gang-Slots bleiben unberührt', function () {
    // Buffet-Gerüst mit 3 Stationen + 1 (fehlplatziertem) gang: menue_typ=buffet deckelt die STATIONEN
    // auf 2 (Reihenfolge bleibt), der gang-Slot ist kein Station-Slot und bleibt stehen. Spiegelbild des
    // Gänge-Caps für den Menü-Typ — beweist die typ-abhängige Slot-Wahl (#35).
    bindGeruestSlots([
        ['label' => 'Warme Station', 'slot_type' => 'station', 'target_count' => 3],
        ['label' => 'Kalte Station', 'slot_type' => 'station', 'target_count' => 3],
        ['label' => 'Süße Station', 'slot_type' => 'station', 'target_count' => 2],
        ['label' => 'Gruß aus der Küche', 'slot_type' => 'gang', 'target_count' => 1],
    ]);
    $this->actingAs($this->makeUser($this->rootTeam));

    $e = $this->svc->generiereAusBrief(
        $this->rootTeam, 'Grill-Buffet, 80 Gäste', null, 'plan_go', false, false,
        ['menue_typ' => 'buffet', 'menue_gaenge' => 2],
    );

    $frame = $this->frames->find('concept', $e['concept']->id);
    expect($frame->slots()->orderBy('position')->pluck('label')->all())
        ->toBe(['Warme Station', 'Kalte Station', 'Gruß aus der Küche']);
});

/** Bindet einen Provider-Stub, der ein KI-Gerüst mit den übergebenen Slots UND frame-Ebene-Rules liefert. */
function bindGeruestSlotsRules(array $slots, array $rules): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($slots, $rules) implements LLMProviderContract
    {
        public function __construct(private array $slots, private array $rules) {}

        public function getName(): string
        {
            return 'test-stub';
        }

        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => [
                'name' => 'Quoten-Menü', 'target_price_pp' => 40, 'slots' => $this->slots, 'rules' => $this->rules,
            ], 'confidence' => 0.8, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
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
}

it('Menü-Leitplanken: Diät-Quoten setzen frame-Ebene-diet_quota-Rules und überschreiben die gleichnamige KI-Prozent-Quote', function () {
    // KI-Gerüst bringt drei frame-Regeln mit: vegan 10 % (wird überschrieben), vegan min 1 count (bleibt,
    // andere Einheit), vegi 20 % (wird überschrieben). Nutzer stellt 40 % vegan + 60 % vegetarisch ein.
    bindGeruestSlotsRules(
        [['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1]],
        [
            ['rule_type' => 'diet_quota', 'ref_key' => 'vegan', 'operator' => 'min', 'value_num' => 10, 'unit' => 'percent'],
            ['rule_type' => 'diet_quota', 'ref_key' => 'vegan', 'operator' => 'min', 'value_num' => 1, 'unit' => 'count'],
            ['rule_type' => 'diet_quota', 'ref_key' => 'vegi', 'operator' => 'min', 'value_num' => 20, 'unit' => 'percent'],
        ],
    );
    $this->actingAs($this->makeUser($this->rootTeam));

    $e = $this->svc->generiereAusBrief(
        $this->rootTeam, 'Sommerfest, 80 Gäste', null, 'plan_go', false, false,
        ['menue_quote_vegan_pct' => 40, 'menue_quote_vegetarisch_pct' => 60],
    );

    $frame = $this->frames->find('concept', $e['concept']->id);
    $frameRules = $frame->rules()->whereNull('slot_id')->get();
    // vegan-Prozent überschrieben (40), vegan-count unberührt (1), vegi-Prozent überschrieben (60)
    expect((float) $frameRules->firstWhere(fn ($r) => $r->ref_key === 'vegan' && $r->unit === 'percent')->value_num)->toBe(40.0)
        ->and((float) $frameRules->firstWhere(fn ($r) => $r->ref_key === 'vegan' && $r->unit === 'count')->value_num)->toBe(1.0)
        ->and((float) $frameRules->firstWhere(fn ($r) => $r->ref_key === 'vegi' && $r->unit === 'percent')->value_num)->toBe(60.0)
        // keine Dublette: genau eine vegan-Prozent-Regel
        ->and($frameRules->filter(fn ($r) => $r->ref_key === 'vegan' && $r->unit === 'percent')->count())->toBe(1);
});

it('Menü-Leitplanken: ohne (bzw. mit 0-)Diät-Quote bleiben die KI-Regeln unangetastet', function () {
    // KI-Gerüst bringt eine vegan-10-%-Quote mit; Nutzer stellt vegan=0 (keine Vorgabe) + keine vegi-Achse.
    bindGeruestSlotsRules(
        [['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1]],
        [['rule_type' => 'diet_quota', 'ref_key' => 'vegan', 'operator' => 'min', 'value_num' => 10, 'unit' => 'percent']],
    );
    $this->actingAs($this->makeUser($this->rootTeam));

    $e = $this->svc->generiereAusBrief(
        $this->rootTeam, 'Buffet, 40 Gäste', null, 'plan_go', false, false,
        ['menue_quote_vegan_pct' => 0],
    );

    $frame = $this->frames->find('concept', $e['concept']->id);
    $frameRules = $frame->rules()->whereNull('slot_id')->get();
    expect($frameRules->count())->toBe(1)
        ->and((float) $frameRules->first()->value_num)->toBe(10.0)   // KI-Wert bleibt (0 = keine Vorgabe)
        ->and($frameRules->first()->ref_key)->toBe('vegan');
});

/** Bindet einen Provider-Spy, der die an chat() übergebenen Prompt-Messages festhält (für Prompt-Assertions). */
function bindGeruestSpy(array $slots): object
{
    $spy = new stdClass();
    $spy->messages = [];
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($slots, $spy) implements LLMProviderContract
    {
        public function __construct(private array $slots, private object $spy) {}

        public function getName(): string
        {
            return 'test-stub';
        }

        public function chat(array $messages, array $options = []): array
        {
            $this->spy->messages = $messages;

            return ['content' => json_encode(['werte' => [
                'name' => 'Balance-Menü', 'target_price_pp' => 40, 'slots' => $this->slots,
            ], 'confidence' => 0.8, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
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

    return $spy;
}

/** Klebt alle festgehaltenen Message-Contents zu einem durchsuchbaren Prompt-String zusammen. */
function spyPromptText(object $spy): string
{
    return collect($spy->messages)->pluck('content')->filter()->implode("\n");
}

it('Menü-Leitplanken: »Portfolio-Balance« landet als selbsterklärende Zusammenstellungs-Direktive im Gerüst-Prompt', function () {
    // menue_balance = ausgewogen → ein menue_zusammenstellung-Block mit der AUSGEWOGEN-Direktive
    // steht im KI-Gerüst-Prompt (der Kontext wird als JSON an die Task gehängt). Konzept entsteht als Draft.
    $spy = bindGeruestSpy([['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1]]);
    $this->actingAs($this->makeUser($this->rootTeam));

    $e = $this->svc->generiereAusBrief(
        $this->rootTeam, 'Galadinner, 60 Gäste', null, 'plan_go', false, false, ['menue_balance' => 'ausgewogen'],
    );

    $prompt = spyPromptText($spy);
    expect($prompt)->toContain('menue_zusammenstellung')
        ->and($prompt)->toContain('AUSGEWOGEN')
        ->and($e['concept'])->not->toBeNull()
        ->and($e['concept']->status)->toBe('draft');
});

it('Menü-Leitplanken: fehlende/fremde Balance-Achse lässt den Gerüst-Prompt ohne Zusammenstellungs-Block', function () {
    // Enum-fremder Wert = keine Vorgabe → kein Block (reglerParams ließe ihn ohnehin nicht durch).
    // Beweist den byte-identischen Pfad, damit die Achse den Prompt nur bei gültigem Enum verändert.
    $spy = bindGeruestSpy([['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1]]);
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->svc->generiereAusBrief(
        $this->rootTeam, 'Buffet, 40 Gäste', null, 'plan_go', false, false, ['menue_balance' => 'quatsch'],
    );

    expect(spyPromptText($spy))->not->toContain('menue_zusammenstellung');
});

/**
 * Bindet einen Provider-Stub für den Kreativ-Kopf: der Gerüst-Call (concept.brief_geruest)
 * liefert Slots/Preis; der Plan-Call (concept.plan) liefert die kreative Canvas. Diskriminiert
 * am Task-Text ('Concept-Canvas' steht nur im Plan-Prompt). $planWerte=null ⇒ Plan-Call wirft
 * (KI-aus-Pfad, fail-soft).
 */
function bindPlanStub(array $slots, ?array $planWerte): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($slots, $planWerte) implements LLMProviderContract
    {
        public function __construct(private array $slots, private ?array $planWerte) {}

        public function getName(): string
        {
            return 'test-stub';
        }

        public function chat(array $messages, array $options = []): array
        {
            $prompt = collect($messages)->pluck('content')->filter()->implode("\n");
            if (str_contains($prompt, 'Concept-Canvas')) {   // concept.plan
                if ($this->planWerte === null) {
                    return ['content' => 'kein JSON — Plan-Call scheitert', 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
                }

                return ['content' => json_encode(['werte' => $this->planWerte, 'confidence' => 0.77, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
            }

            // concept.brief_geruest
            return ['content' => json_encode(['werte' => [
                'name' => 'KI-Plan-Menü', 'target_price_pp' => 40, 'slots' => $this->slots,
            ], 'confidence' => 0.82, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
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
}

it('Kreativ-Kopf planAusBrief: Draft + Gerüst + kreative Canvas + LEERE Fan-out-Slots (nicht befüllt)', function () {
    // Gerüst: Vorspeise (1) + Hauptgang (2) = 3 erfindbare Positionen. Plan: volle Canvas.
    bindPlanStub(
        [
            ['label' => 'Vorspeise', 'slot_type' => 'gang', 'target_count' => 1],
            ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 2],
        ],
        [
            'name_claim' => 'Alpenglühen — der Berg auf dem Teller',
            'leitidee' => 'Ein Menü, das die Aromen der Almwiese in Gänge übersetzt.',
            'usp_eignung' => 'Regional, saisonal — passt zum Herbst-Galadinner.',
            'inszenierung' => 'Auf Schieferplatten, Gang für Gang moderiert.',
            'geschmackswelten' => [
                ['claim' => 'Kräuterwiese', 'description' => 'Grün, frisch, heuartig.'],
                ['claim' => 'Waldboden', 'description' => 'Erdig, pilzig, dunkel.'],
                ['claim' => '', 'description' => ''],   // leere Welt wird übersprungen
            ],
        ],
    );
    $this->actingAs($this->makeUser($this->rootTeam));

    $e = $this->svc->planAusBrief($this->rootTeam, 'Herbst-Galadinner, 40 Gäste, regional, ca. 40 € p. P.');
    $concept = $e['concept'];

    // Draft + Lineage + KI-Name übernommen (kein Nutzer-Name gesetzt)
    expect($concept->status)->toBe('draft')
        ->and($concept->created_via)->toBe('concept_plan_ui')
        ->and($concept->name)->toBe('KI-Plan-Menü')
        ->and($concept->description)->toContain('Herbst-Galadinner')
        ->and($e['geruest_confidence'])->toBe(0.82)
        ->and($e['plan_confidence'])->toBe(0.77);

    // Frame hängt am Konzept (Reuse geruestAusBriefFuerOwner)
    $frame = $this->frames->find('concept', $concept->id);
    expect($frame)->not->toBeNull()
        ->and($frame->slots()->count())->toBe(2)
        ->and((float) $frame->target_price_pp)->toBe(40.0);

    // LEERE Fan-out-Slots: 1 + 2 = 3 Positionen, alle leer (kein Gericht/Paket), Typ-Default 'gericht'
    // → exakt der fanoutConceptInvention-Filter. NICHTS wurde vom Assembler befüllt.
    expect($e['slots'])->toBe(3);
    $fanoutZiele = FoodAlchemistConceptSlot::where('concept_id', $concept->id)
        ->whereNull('sales_recipe_id')->whereNull('package_id')
        ->whereNotIn('type', ['text', 'spacer', 'header', 'header_preis'])
        ->get();
    expect($fanoutZiele->count())->toBe(3)
        ->and($fanoutZiele->pluck('role')->sort()->values()->all())->toBe(['Hauptgang', 'Hauptgang', 'Vorspeise'])
        ->and(FoodAlchemistConceptSlot::where('concept_id', $concept->id)->whereNotNull('sales_recipe_id')->count())->toBe(0);

    // Kreative Canvas gefüllt: Skalare + Geschmackswelten (leere übersprungen, description im meta)
    $canvas = app(\Platform\FoodAlchemist\Services\CanvasService::class);
    $cv = $canvas->find('concept', 'concept', $concept->id);
    $werte = $canvas->werte($cv);
    expect($werte['name_claim'])->toContain('Alpenglühen')
        ->and($werte['leitidee'])->toContain('Almwiese')
        ->and($werte['inszenierung'])->toContain('Schieferplatten')
        ->and($werte['geschmackswelten'])->toHaveCount(2)
        ->and($werte['geschmackswelten'][0]['value'])->toBe('Kräuterwiese')
        ->and($werte['geschmackswelten'][0]['meta']['description'])->toBe('Grün, frisch, heuartig.');
});

it('Kreativ-Kopf planAusBrief: fail-soft — scheiternder concept.plan lässt Concept/Frame/Slots stehen, Canvas leer', function () {
    bindPlanStub(
        [['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1]],
        null,   // Plan-Call scheitert (kein valides JSON)
    );
    $this->actingAs($this->makeUser($this->rootTeam));

    // Eigener Name gesetzt → KI-Name greift nicht.
    $e = $this->svc->planAusBrief($this->rootTeam, 'Buffet, 30 Gäste', [], 'Mein Konzept');
    $concept = $e['concept'];

    expect($concept->status)->toBe('draft')
        ->and($concept->name)->toBe('Mein Konzept')
        ->and($e['plan_confidence'])->toBeNull()               // Plan-Call scheiterte → null
        ->and($e['slots'])->toBe(1);

    // Gerüst + leerer Fan-out-Slot stehen trotz gescheitertem Plan
    expect($this->frames->find('concept', $concept->id))->not->toBeNull()
        ->and(FoodAlchemistConceptSlot::where('concept_id', $concept->id)->whereNull('sales_recipe_id')->count())->toBe(1);

    // Canvas blieb leer (kein Entry angelegt)
    $canvas = app(\Platform\FoodAlchemist\Services\CanvasService::class);
    expect($canvas->find('concept', 'concept', $concept->id))->toBeNull();
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
