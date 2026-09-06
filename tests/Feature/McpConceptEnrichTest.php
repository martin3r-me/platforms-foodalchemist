<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\ConceptOneShotService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 · Paket C-4: `ConceptOneShotService::anreichern()` + `concepts.ENRICH` — das Rezept-Analogon
 * für Konzepte. Lücken-getrieben (Override-First), Frame-Kopf deterministisch, EIN KI-Call für Text,
 * Run-Protokoll `enrich_concept`.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->svc = app(ConceptService::class);

    // Spy: liefert für JEDEN angebotenen Header/Slot einen Text und zählt seine Calls.
    $this->spy = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public array $kontext = [];

        public bool $kippen = false;

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;
            if ($this->kippen) {
                throw new \RuntimeException('Provider weg');
            }
            $user = collect($messages)->where('role', 'user')->last()['content'] ?? '';
            if (preg_match('/Kontext:\s*(\{.*\})/s', $user, $m)) {
                $this->kontext = json_decode($m[1], true) ?? [];
            }
            $header = [];
            foreach ($this->kontext['header'] ?? [] as $h) {
                $header[$h['slot_id']] = 'Akt: ' . $h['title'];
            }
            $slots = [];
            foreach ($this->kontext['positionen'] ?? [] as $p) {
                $slots[$p['slot_id']] = 'Zart: ' . $p['name'];
            }

            return [
                'content' => json_encode(['werte' => [
                    'intro' => 'Drei Akte am Herbstabend.', 'slots' => $slots, 'header' => $header,
                    'consumer_name' => 'Herbstglut', 'claim' => 'Wärme auf dem Teller.',
                ], 'confidence' => 0.9]),
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'spy', 'tool_calls' => null,
            ];
        }
    };
    app()->instance(FakeAiProvider::class, $this->spy);
});

/** Gerüst-Konzept mit einer gefüllten Position, Kopf bewusst geleert (Frame trägt den Zielpreis weiter). */
function c4Konzept($t): array
{
    // Closure an den Testfall binden — makeRecipe()/rootTeam sind protected (SeedsTeamHierarchy).
    return (function () {
        $res = ($this->run)('foodalchemist.concepts.POST', [
            'name' => 'Herbstmenü', 'target_price_per_person' => 45, 'geruest' => ['typ' => 'menue', 'gaenge' => 3],
        ]);
        expect($res->success)->toBeTrue('post: ' . ($res->error ?? ''));
        $id = (int) $res->data['concept']['id'];
        $this->svc->update($this->rootTeam, $id, ['target_price_per_person' => null, 'description' => null]);

        $leer = FoodAlchemistConceptSlot::where('concept_id', $id)->where('type', 'gericht')->orderBy('position')->first();
        $dish = $this->makeRecipe($this->rootTeam, 'Kürbis & Ingwer', ['is_sales_recipe' => true, 'sales_net' => 9]);
        expect(($this->run)('foodalchemist.concept_slots.PUT', ['slot_id' => $leer->id, 'felder' => ['sales_recipe_id' => $dish->id]])->success)->toBeTrue();

        return [$id, (int) $leer->id];
    })->call($t);
}

it('luecken(): Kopf-Lücken + Generator-Header + gefüllte Positionen ohne Wording — leere Positionen sind keine Wording-Lücke', function () {
    [$id, $slotId] = c4Konzept($this);
    $l = app(ConceptOneShotService::class)->luecken($this->rootTeam, $this->svc->detail($this->rootTeam, $id));

    expect($l['kopf'])->toBe(['description', 'consumer_name', 'claim', 'target_price_per_person'])   // price_display: NOT NULL + DB-Default ⇒ nie eine Lücke
        ->and($l['struktur']['header_titel'])->toHaveCount(3)
        ->and($l['struktur']['slot_wording'])->toBe([$slotId]);
});

it('concepts.ENRICH: Frame-Kopf deterministisch, Text in EINEM Call, Run-Protokoll enrich_concept, zweiter Lauf kostet nichts', function () {
    [$id, $slotId] = c4Konzept($this);

    $res = ($this->run)('foodalchemist.concepts.ENRICH', ['concept_id' => $id]);
    expect($res->success)->toBeTrue('enrich: ' . ($res->error ?? ''))
        ->and($res->data['deterministisch'])->toBe(['target_price_per_person'])
        ->and($res->data['schritte'])->toBe(['description', 'consumer_name', 'claim', 'header_titel', 'slot_wording'])
        ->and($res->data['fehler'])->toBeNull()
        ->and($res->data['wording']['header_set'])->toBe(3)
        ->and($res->data['wording']['slots_set'])->toBe(1)
        ->and($res->data['wording']['kopf_set'])->toBe(['consumer_name', 'claim'])
        ->and($res->data['luecken_nachher'])->toBe(['kopf' => [], 'struktur' => ['header_titel' => [], 'slot_wording' => []]])
        ->and($this->spy->calls)->toBe(1);

    $c = $this->svc->detail($this->rootTeam, $id);
    expect((float) $c->target_price_per_person)->toBe(45.0)
        ->and($c->price_display)->toBe('gesamt')
        ->and($c->description)->toBe('Drei Akte am Herbstabend.')
        ->and($c->consumer_name)->toBe('Herbstglut')
        ->and($c->claim)->toBe('Wärme auf dem Teller.')
        ->and(FoodAlchemistConceptSlot::find($slotId)->wording)->toBe('Zart: Kürbis & Ingwer');
    $header = FoodAlchemistConceptSlot::where('concept_id', $id)->where('type', 'header')->orderBy('position')->get();
    expect($header->pluck('title')->all())->toBe(['Akt: Vorspeise', 'Akt: Hauptgang', 'Akt: Dessert'])
        ->and($header->pluck('role')->all())->toBe(['Vorspeise', 'Hauptgang', 'Dessert']);   // role bleibt Frame-Label

    // Run-Protokoll: gleiche Buchhaltung wie beim Rezept.
    $run = FoodAlchemistBulkRun::find($res->data['run_id']);
    expect($run->type)->toBe(BulkRunType::EnrichConcept)
        ->and($run->status)->toBe(BulkRunStatus::Done)
        ->and($run->done)->toBe(1)
        ->and($run->context['quelle'])->toBe('one_shot')
        ->and($run->context['concept_id'])->toBe($id)
        ->and($run->context['schritte'])->toContain('header_titel');

    // Zweiter Lauf: keine Lücken → kein Lauf, kein Call, nichts überschrieben (Override-First).
    $res2 = ($this->run)('foodalchemist.concepts.ENRICH', ['concept_id' => $id]);
    expect($res2->success)->toBeTrue()
        ->and($res2->data['run_id'])->toBeNull()
        ->and($res2->data['schritte'])->toBe([])
        ->and($res2->data['deterministisch'])->toBe([])
        ->and($this->spy->calls)->toBe(1)
        ->and($this->svc->detail($this->rootTeam, $id)->consumer_name)->toBe('Herbstglut');
});

it('concepts.ENRICH: von Menschen gesetzte Werte bleiben — nur die echten Lücken gehen in den Prompt', function () {
    [$id, $slotId] = c4Konzept($this);
    $this->svc->update($this->rootTeam, $id, ['consumer_name' => 'Kundenname', 'target_price_per_person' => 52]);
    $hg = FoodAlchemistConceptSlot::where('concept_id', $id)->where('role', 'Hauptgang')->where('type', 'header')->first();
    $this->svc->updateSlot($this->rootTeam, (int) $hg->id, ['title' => 'Vom Feuer']);   // Mensch-Header

    $res = ($this->run)('foodalchemist.concepts.ENRICH', ['concept_id' => $id]);
    expect($res->success)->toBeTrue()
        ->and($res->data['schritte'])->toBe(['description', 'claim', 'header_titel', 'slot_wording'])
        ->and($res->data['deterministisch'])->toBe([])                     // Zielpreis stand schon
        ->and($this->spy->kontext['kopf']['luecken'])->toBe(['claim'])
        ->and(collect($this->spy->kontext['header'])->pluck('title')->all())->toBe(['Vorspeise', 'Dessert']);

    $c = $this->svc->detail($this->rootTeam, $id);
    expect($c->consumer_name)->toBe('Kundenname')
        ->and((float) $c->target_price_per_person)->toBe(52.0)
        ->and($hg->fresh()->title)->toBe('Vom Feuer');
});

it('concepts.ENRICH: kippender Provider → fehler gemeldet, Lauf failed, Konzept unverändert', function () {
    [$id] = c4Konzept($this);
    $this->spy->kippen = true;

    $res = ($this->run)('foodalchemist.concepts.ENRICH', ['concept_id' => $id]);
    expect($res->success)->toBeTrue()                                   // der Pass wirft nie
        ->and($res->data['fehler'])->toContain('Provider weg')
        ->and($res->data['wording'])->toBeNull()
        ->and($res->data['deterministisch'])->toBe(['target_price_per_person'])
        ->and($res->data['luecken_nachher']['kopf'])->toBe(['description', 'consumer_name', 'claim']);
    $run = FoodAlchemistBulkRun::find($res->data['run_id']);
    expect($run->status)->toBe(BulkRunStatus::Failed)
        ->and($run->context['fehler'])->toContain('Provider weg');
});

it('concepts.ENRICH: fremdes Konzept wird nicht angereichert; ohne concept_id VALIDATION_ERROR', function () {
    $fremd = Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false]);
    $fremdes = $this->svc->create($fremd, ['name' => 'Fremd-Menü']);

    expect(($this->run)('foodalchemist.concepts.ENRICH', ['concept_id' => $fremdes->id])->success)->toBeFalse()
        ->and(($this->run)('foodalchemist.concepts.ENRICH', [])->errorCode)->toBe('VALIDATION_ERROR')
        ->and($this->spy->calls)->toBe(0);
});

it('BulkRunType::EnrichConcept ist ein KI-Lauf mit Label — die Positiv-Liste wurde bewusst ergänzt', function () {
    expect(BulkRunType::EnrichConcept->value)->toBe('enrich_concept')
        ->and(BulkRunType::EnrichConcept->istKiLauf())->toBeTrue()
        ->and(BulkRunType::EnrichConcept->label())->toBe('Anreicherung (Konzepte)');
});
