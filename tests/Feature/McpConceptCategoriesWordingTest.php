<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D5c: Konzept-Kategorien (POST/PUT/DELETE) + concept_wording.GENERATE
 * (W-Grounding) + concepts.COHESION (Read). Web↔MCP-Parität über ConceptService.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->svc = app(ConceptService::class);
    $this->concept = $this->svc->create($this->rootTeam, ['name' => 'Grill-Buffet', 'occasion' => 'Sommerfest']);
});

it('Registry-Smoke: 5 D5c-Tools registriert mit type=object', function () {
    foreach (['concept_categories.POST', 'concept_categories.PUT', 'concept_categories.DELETE', 'concept_wording.GENERATE', 'concepts.COHESION'] as $n) {
        $tool = $this->registry->get("foodalchemist.{$n}");
        expect($tool)->not->toBeNull($n);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $n);
    }
});

it('concept_categories: POST (+Unterkategorie) / PUT / DELETE(confirm)', function () {
    $post = ($this->run)('foodalchemist.concept_categories.POST', ['name' => 'Sommer-Menüs']);
    expect($post->success)->toBeTrue('post: ' . ($post->error ?? ''));
    $catId = $post->data['id'];

    $sub = ($this->run)('foodalchemist.concept_categories.POST', ['name' => 'Grill', 'parent_id' => $catId]);
    expect($sub->success)->toBeTrue('sub: ' . ($sub->error ?? ''))->and($sub->data['parent_id'])->toBe($catId);

    $put = ($this->run)('foodalchemist.concept_categories.PUT', ['id' => $catId, 'name' => 'Sommer 2027']);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''));
    expect(FoodAlchemistConceptCategory::find($catId)->name)->toBe('Sommer 2027');

    // DELETE ohne confirm → CONFIRM_REQUIRED
    expect(($this->run)('foodalchemist.concept_categories.DELETE', ['id' => $catId])->errorCode)->toBe('CONFIRM_REQUIRED');

    // Unterkategorie wandert an den Eltern (hier: null), Zeile weg
    $del = ($this->run)('foodalchemist.concept_categories.DELETE', ['id' => $catId, 'confirm' => true]);
    expect($del->success)->toBeTrue('del: ' . ($del->error ?? ''));
    expect(FoodAlchemistConceptCategory::find($catId))->toBeNull()
        ->and(FoodAlchemistConceptCategory::find($sub->data['id'])->parent_id)->toBeNull();
});

it('concept_categories: Guards — unbekannt NOT_FOUND, fremd ACCESS_DENIED, leer VALIDATION_ERROR', function () {
    $post = ($this->run)('foodalchemist.concept_categories.POST', ['name' => 'X']);
    $catId = $post->data['id'];

    expect(($this->run)('foodalchemist.concept_categories.PUT', ['id' => 999999, 'name' => 'Y'])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.concept_categories.PUT', ['id' => $catId, 'name' => 'Y'], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
    expect(($this->run)('foodalchemist.concept_categories.POST', ['name' => ''])->errorCode)->toBe('VALIDATION_ERROR');
    expect(($this->run)('foodalchemist.concept_categories.DELETE', ['id' => $catId, 'confirm' => true], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
});

it('concept_wording.GENERATE: Intro→Beschreibung + Positions-Texte (Spy-Provider)', function () {
    $dish = $this->makeRecipe($this->rootTeam, 'DES: Hot-Dog', ['is_sales_recipe' => true, 'sales_net' => 2.0]);
    $slot = $this->svc->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Snack']);
    $slot = $this->svc->fillSlot($this->rootTeam, $slot->id, ['sales_recipe_id' => $dish->id]);

    $spy = new class($slot->id) extends FakeAiProvider
    {
        public function __construct(public int $slotId) {}

        public function chat(array $messages, array $options = []): array
        {
            return [
                'content' => json_encode(['werte' => [
                    'intro' => 'Ein sommerliches Grill-Erlebnis.',
                    'slots' => [$this->slotId => 'Knuspriger Hot-Dog-Traum'],
                ], 'confidence' => 0.9]),
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'spy', 'tool_calls' => null,
            ];
        }
    };
    app()->instance(FakeAiProvider::class, $spy);

    $gen = ($this->run)('foodalchemist.concept_wording.GENERATE', ['concept_id' => $this->concept->id]);
    expect($gen->success)->toBeTrue('gen: ' . ($gen->error ?? ''))
        ->and($gen->data['intro'])->toBe('Ein sommerliches Grill-Erlebnis.')
        ->and($gen->data['slots_set'])->toBe(1);
    expect($this->concept->fresh()->description)->toBe('Ein sommerliches Grill-Erlebnis.')
        ->and($slot->fresh()->wording)->toBe('Knuspriger Hot-Dog-Traum');

    // Guards
    expect(($this->run)('foodalchemist.concept_wording.GENERATE', ['concept_id' => 999999])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.concept_wording.GENERATE', ['concept_id' => $this->concept->id], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
});

it('concepts.COHESION: read-only, zu_wenig bei <2 Gerichten; fremd/sichtbar noch lesbar', function () {
    $coh = ($this->run)('foodalchemist.concepts.COHESION', ['concept_id' => $this->concept->id]);
    expect($coh->success)->toBeTrue('coh: ' . ($coh->error ?? ''))
        ->and($coh->data['zu_wenig'])->toBeTrue()
        ->and($coh->data['concept_id'])->toBe($this->concept->id);

    expect(($this->run)('foodalchemist.concepts.COHESION', ['concept_id' => 999999])->errorCode)->toBe('NOT_FOUND');
});

/**
 * Spec 50 · Paket C-2: der Wording-Pass deckt Header-Titel + Kopf-Felder ab — mit Override-First:
 * nur Generator-Header (title === role) und nur leere consumer_name/claim werden geschrieben.
 */
it('concept_wording.GENERATE (C-2): Generator-Header umgetextet, Mensch-Header bleibt, Kopf-Lücken gefüllt, gesetzter Kundenname unantastbar', function () {
    $this->svc->update($this->rootTeam, $this->concept->id, ['consumer_name' => 'Sommerlust']);   // gesetzt → bleibt
    $dish = $this->svc->fillSlot($this->rootTeam, $this->svc->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Hauptgang'])->id,
        ['sales_recipe_id' => $this->makeRecipe($this->rootTeam, 'HG: Spareribs', ['is_sales_recipe' => true, 'sales_net' => 12])->id]);
    $genHeader = $this->svc->addBlock($this->rootTeam, $this->concept->id, 'header', ['title' => 'Hauptgang', 'role' => 'Hauptgang']);
    $menschHeader = $this->svc->addBlock($this->rootTeam, $this->concept->id, 'header', ['title' => 'Vom Grill', 'role' => 'Hauptgang']);
    $freiHeader = $this->svc->addBlock($this->rootTeam, $this->concept->id, 'header', ['title' => 'Hinweis']); // role leer → kein Generator-Header

    $spy = new class($genHeader->id, $menschHeader->id, $freiHeader->id, $dish->id) extends FakeAiProvider
    {
        public array $kontext = [];

        public function __construct(public int $gen, public int $mensch, public int $frei, public int $slot) {}

        public function chat(array $messages, array $options = []): array
        {
            $user = collect($messages)->where('role', 'user')->last()['content'] ?? '';
            if (preg_match('/Kontext:\s*(\{.*\})/s', $user, $m)) {
                $this->kontext = json_decode($m[1], true) ?? [];
            }

            return [
                'content' => json_encode(['werte' => [
                    'intro' => 'Feuer, Rauch und Sommer.',
                    'slots' => [$this->slot => 'Ribs vom Buchenholz'],
                    'header' => [$this->gen => 'Vom offenen Feuer', $this->mensch => 'DARF NICHT', $this->frei => 'DARF NICHT'],
                    'consumer_name' => 'DARF NICHT',
                    'claim' => 'Grillen wie am Fluss.',
                ], 'confidence' => 0.9]),
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'spy', 'tool_calls' => null,
            ];
        }
    };
    app()->instance(FakeAiProvider::class, $spy);

    $gen = ($this->run)('foodalchemist.concept_wording.GENERATE', ['concept_id' => $this->concept->id]);
    expect($gen->success)->toBeTrue('gen: ' . ($gen->error ?? ''))
        ->and($gen->data['header_set'])->toBe(1)
        ->and($gen->data['kopf_set'])->toBe(['claim'])
        ->and($gen->data['slots_set'])->toBe(1);

    // Prompt-Kontext: nur der Generator-Header angeboten, Kopf-Lücken benannt.
    expect(collect($spy->kontext['header'])->pluck('slot_id')->all())->toBe([$genHeader->id])
        ->and($spy->kontext['kopf']['luecken'])->toBe(['claim']);

    $c = $this->concept->fresh();
    expect($c->consumer_name)->toBe('Sommerlust')
        ->and($c->claim)->toBe('Grillen wie am Fluss.')
        ->and($c->description)->toBe('Feuer, Rauch und Sommer.')
        ->and($genHeader->fresh()->title)->toBe('Vom offenen Feuer')
        ->and($genHeader->fresh()->role)->toBe('Hauptgang')          // role bleibt das Frame-Label
        ->and($menschHeader->fresh()->title)->toBe('Vom Grill')
        ->and($freiHeader->fresh()->title)->toBe('Hinweis');

    // Zweiter Lauf mit nur_luecken: nichts wird überschrieben, der umgetextete Header ist jetzt „Mensch-gleich".
    $gen2 = ($this->run)('foodalchemist.concept_wording.GENERATE', ['concept_id' => $this->concept->id, 'nur_luecken' => true]);
    expect($gen2->data['header_set'])->toBe(0)->and($gen2->data['slots_set'])->toBe(0)->and($gen2->data['intro'])->toBeNull()
        ->and($spy->kontext['positionen'])->toBe([])->and($spy->kontext['header'])->toBe([]);
    expect($this->concept->fresh()->description)->toBe('Feuer, Rauch und Sommer.');
});

it('concepts.GENERATE (C-2): Wording-Pass hängt fail-soft am Gerüst-Pfad — Header betitelt, Positionen leer bleiben unangetastet', function () {
    // Gerüst-Pfad ohne KI-Erfindung: concepts.POST geruest → Frame + Header; GENERATE aus diesem Gerüst.
    $post = ($this->run)('foodalchemist.concepts.POST', ['name' => 'Menü', 'geruest' => ['typ' => 'menue']]);
    $conceptId = $post->data['concept']['id'];

    $spy = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;
            $user = collect($messages)->where('role', 'user')->last()['content'] ?? '';
            $k = preg_match('/Kontext:\s*(\{.*\})/s', $user, $m) ? (json_decode($m[1], true) ?? []) : [];
            $header = [];
            foreach ($k['header'] ?? [] as $h) {
                $header[$h['slot_id']] = 'Akt: ' . $h['title'];
            }

            return ['content' => json_encode(['werte' => ['intro' => 'Drei Akte.', 'header' => $header, 'consumer_name' => 'Drei-Akter', 'claim' => 'Ein Abend in drei Akten.'], 'confidence' => 0.8]),
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'spy', 'tool_calls' => null];
        }
    };
    app()->instance(FakeAiProvider::class, $spy);

    $gen = ($this->run)('foodalchemist.concepts.GENERATE', ['geruest_owner_type' => 'concept', 'geruest_owner_id' => $conceptId]);
    expect($gen->success)->toBeTrue('gen: ' . ($gen->error ?? ''));
    $neu = \Platform\FoodAlchemist\Models\FoodAlchemistConcept::find($gen->data['concept_id']);
    expect($gen->data['wording'])->not->toBeNull()
        ->and($gen->data['wording']['header_set'])->toBe(3)
        ->and($gen->data['wording']['kopf_set'])->toEqualCanonicalizing(['consumer_name', 'claim'])
        ->and($gen->data['consumer_name'])->toBe('Drei-Akter')
        ->and($neu->description)->toBe('Drei Akte.')
        ->and($neu->slots->where('type', 'header')->pluck('title')->all())->toBe(['Akt: Vorspeise', 'Akt: Hauptgang', 'Akt: Dessert']);

    // wording=false → reine Struktur, kein KI-Call fürs Wording.
    $calls = $spy->calls;
    $gen2 = ($this->run)('foodalchemist.concepts.GENERATE', ['geruest_owner_type' => 'concept', 'geruest_owner_id' => $conceptId, 'wording' => false]);
    expect($gen2->success)->toBeTrue()->and($gen2->data['wording'])->toBeNull()->and($spy->calls)->toBe($calls);
});
