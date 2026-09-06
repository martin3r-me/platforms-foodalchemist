<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\WordingResolver;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 · Paket C-1: Header bei Concepten — `concepts.POST geruest={typ,gaenge}` legt die kanonische
 * Struktur (Header + leere Positionen) ohne KI an; `concept_slots.PUT felder.sales_recipe_id` füllt sie.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
});

it('concepts.POST geruest=menue: je Gang ein Header (title=role=Label) vor genau einer leeren Position', function () {
    $res = ($this->run)('foodalchemist.concepts.POST', [
        'name' => 'Herbstmenü', 'target_price_per_person' => 58, 'geruest' => ['typ' => 'menue', 'gaenge' => 4],
    ]);
    expect($res->success)->toBeTrue()
        ->and($res->data['geruest']['typ'])->toBe('menue')
        ->and($res->data['geruest']['positionen_leer'])->toBe(4)
        ->and($res->data['geruest']['header'])->toBe(4)
        ->and($res->data['geruest']['struktur'])->toHaveCount(8)
        ->and($res->data['note'])->toContain('concept_slots.PUT');

    $slots = FoodAlchemistConceptSlot::where('concept_id', $res->data['concept']['id'])->orderBy('position')->get();
    expect($slots->pluck('type')->all())->toBe(['header', 'gericht', 'header', 'gericht', 'header', 'gericht', 'header', 'gericht']);
    foreach ($slots->where('type', 'header') as $h) {
        expect($h->title)->not->toBeEmpty()->and($h->role)->toBe($h->title);
    }
    // Dramaturgie endet mit dem Dessert; Zielpreis wandert an den Gerüst-Kopf.
    expect(mb_strtolower((string) $slots->where('type', 'header')->last()->title))->toContain('dessert');
    $frame = app(\Platform\FoodAlchemist\Services\PlanningFrameService::class)->find('concept', (int) $res->data['concept']['id']);
    expect($frame)->not->toBeNull()->and((float) $frame->target_price_pp)->toBe(58.0)->and($frame->slots()->count())->toBe(4);
});

it('concepts.POST geruest=buffet: Stationen ≥2 als Paket mit innerem Header, Einzel-Stationen flach mit Header', function () {
    $res = ($this->run)('foodalchemist.concepts.POST', ['name' => 'Lunchbuffet', 'geruest' => ['typ' => 'buffet']]);
    expect($res->success)->toBeTrue();

    $slots = FoodAlchemistConceptSlot::where('concept_id', $res->data['concept']['id'])->orderBy('position')->get();
    // 6 Sektionen: 4× Paket (Kalte Vorspeisen 3, Warm 2, Beilagen 2, Dessert 2) + 2× flach (Suppe, Getränke) je mit Header
    expect($slots->where('type', 'paket'))->toHaveCount(4)
        ->and($slots->where('type', 'header'))->toHaveCount(2)
        ->and($slots->where('type', 'gericht'))->toHaveCount(2)
        ->and($res->data['geruest']['positionen_leer'])->toBe(11);
    $paket = FoodAlchemistConcept::find($slots->firstWhere('type', 'paket')->embedded_concept_id);
    expect($paket->kind)->toBe('paket')
        ->and(FoodAlchemistConceptSlot::where('concept_id', $paket->id)->where('type', 'header')->count())->toBe(1);
});

it('concepts.POST geruest: ungültiger Typ ⇒ VALIDATION_ERROR, Konzept wird nicht halb angelegt', function () {
    $vorher = FoodAlchemistConcept::count();
    $res = ($this->run)('foodalchemist.concepts.POST', ['name' => 'X', 'geruest' => ['typ' => 'flying']]);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('VALIDATION_ERROR');
    // Das Konzept selbst ist angelegt (create lief vor dem Gerüst) — aber ohne Struktur; ehrlich im Fehler.
    expect(FoodAlchemistConcept::count())->toBe($vorher + 1)
        ->and(FoodAlchemistConceptSlot::where('concept_id', FoodAlchemistConcept::latest('id')->first()->id)->count())->toBe(0);
});

it('concept_slots.PUT felder.sales_recipe_id füllt eine leere Gerüst-Position (vorher still verworfen)', function () {
    $res = ($this->run)('foodalchemist.concepts.POST', ['name' => 'Menü', 'geruest' => ['typ' => 'menue']]);
    $leer = FoodAlchemistConceptSlot::where('concept_id', $res->data['concept']['id'])->where('type', 'gericht')->orderBy('position')->first();
    $dish = $this->makeRecipe($this->rootTeam, 'VSP: Kürbissuppe', ['is_sales_recipe' => true, 'sales_net' => 9.5]);

    $put = ($this->run)('foodalchemist.concept_slots.PUT', ['slot_id' => $leer->id, 'felder' => ['sales_recipe_id' => $dish->id, 'wording' => 'Kürbis & Ingwer']]);
    expect($put->success)->toBeTrue();
    $leer->refresh();
    expect((int) $leer->sales_recipe_id)->toBe($dish->id)
        ->and($leer->type)->toBe('gericht')
        ->and($leer->wording)->toBe('Kürbis & Ingwer');

    // Der WordingResolver rendert Header + gefüllte Position — die Struktur ist kundendokument-fähig.
    $zeilen = app(WordingResolver::class)->gerichtZeilen(FoodAlchemistConcept::with('slots.dish')->find($res->data['concept']['id']));
    // Leere Gänge bleiben als Header sichtbar (Vorspeise/Gericht, Hauptgang, Dessert) — leere Positionen selbst nicht.
    expect(collect($zeilen)->pluck('type')->all())->toBe(['header', 'gericht', 'header', 'header'])
        ->and($zeilen[0]['text'])->toBe('Vorspeise')
        ->and($zeilen[1]['text'])->toBe('Kürbis & Ingwer');
});

it('concept_slots.PUT: fremdes/unsichtbares Gericht ⇒ NOT_FOUND, XOR-Verstoß ⇒ VALIDATION_ERROR', function () {
    $res = ($this->run)('foodalchemist.concepts.POST', ['name' => 'Menü', 'geruest' => ['typ' => 'menue']]);
    $leer = FoodAlchemistConceptSlot::where('concept_id', $res->data['concept']['id'])->where('type', 'gericht')->first();
    $fremd = $this->makeRecipe($this->childA, 'HG: Fremd', ['is_sales_recipe' => true]);

    expect(($this->run)('foodalchemist.concept_slots.PUT', ['slot_id' => $leer->id, 'felder' => ['sales_recipe_id' => $fremd->id]])->errorCode)->toBe('NOT_FOUND')
        ->and(($this->run)('foodalchemist.concept_slots.PUT', ['slot_id' => $leer->id, 'felder' => ['sales_recipe_id' => 1, 'package_id' => 1]])->errorCode)->toBe('VALIDATION_ERROR')
        ->and($leer->refresh()->sales_recipe_id)->toBeNull();
});

it('kanonischesGeruest: schreibt nicht in ein Konzept mit Positionen oder eigenem Gerüst (GL 5)', function () {
    $svc = app(ConceptService::class);
    $c = $svc->create($this->rootTeam, ['name' => 'Voll', 'status' => 'draft']);
    $svc->addSlot($this->rootTeam, $c->id, ['role' => 'Gang']);
    $gen = app(\Platform\FoodAlchemist\Services\ConceptGeneratorService::class);
    expect(fn () => $gen->kanonischesGeruest($this->rootTeam, $c->fresh(), 'menue'))->toThrow(RuntimeException::class, 'schon Positionen');

    $c2 = $svc->create($this->rootTeam, ['name' => 'Gerüst da', 'status' => 'draft']);
    app(\Platform\FoodAlchemist\Services\PlanningFrameService::class)->frameFor($this->rootTeam, 'concept', $c2->id);
    expect(fn () => $gen->kanonischesGeruest($this->rootTeam, $c2->fresh(), 'buffet'))->toThrow(RuntimeException::class, 'schon ein Planungs-Gerüst');
});

it('C-3: name_claim-Split respektiert gesetzte Kopf-Felder und Zeilen ohne Trenner', function () {
    $svc = app(ConceptService::class);
    $gen = app(\Platform\FoodAlchemist\Services\ConceptGeneratorService::class);
    $m = new ReflectionMethod($gen, 'uebernehmeNameClaim');

    $c = $svc->create($this->rootTeam, ['name' => 'A']);
    $m->invoke($gen, $c, '„Alpenglühen“ – der Berg auf dem Teller');
    expect($c->fresh()->consumer_name)->toBe('Alpenglühen')->and($c->fresh()->claim)->toBe('der Berg auf dem Teller');

    $c2 = $svc->create($this->rootTeam, ['name' => 'B']);
    $svc->update($this->rootTeam, $c2->id, ['consumer_name' => 'Fest gesetzt']);   // create() kennt nur 6 Felder
    $m->invoke($gen, $c2, 'Neu: mit Claim');
    expect($c2->fresh()->consumer_name)->toBe('Fest gesetzt')->and($c2->fresh()->claim)->toBe('mit Claim');

    $c3 = $svc->create($this->rootTeam, ['name' => 'C']);
    $m->invoke($gen, $c3, 'Nur ein Name');
    expect($c3->fresh()->consumer_name)->toBe('Nur ein Name')->and($c3->fresh()->claim)->toBeNull();
});
