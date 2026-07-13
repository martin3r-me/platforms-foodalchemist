<?php

use Platform\FoodAlchemist\Models\FoodAlchemistCanvasEntry;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrame;
use Platform\FoodAlchemist\Models\FoodAlchemistSaison;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R4.1 — Planungs-Gerüst: strukturierte Soll-Daten (Mengengerüst, Preisarchitektur,
 * Kunden-Politik, Saison, Dramaturgie) an Foodbook UND Konzept, D1-Write-Guard,
 * deklaratives Ersetzen (MCP-PUT-Pfad) und Kollisionsfreiheit zum Freitext-Canvas.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PlanningFrameService::class);
    $this->foodbook = FoodAlchemistFoodbook::create(['team_id' => $this->childA->id, 'label' => 'Sommer-FB']);
    $this->concept = FoodAlchemistConcept::create(['team_id' => $this->childA->id, 'name' => 'Grill-Buffet']);
});

it('legt je Owner genau EIN Gerüst an (foodbook UND concept) — draft + Team', function () {
    $f1 = $this->svc->frameFor($this->childA, 'foodbook', $this->foodbook->id);
    $f2 = $this->svc->frameFor($this->childA, 'foodbook', $this->foodbook->id);
    $f3 = $this->svc->frameFor($this->childA, 'concept', $this->concept->id);

    expect($f1->id)->toBe($f2->id)
        ->and($f1->status)->toBe('draft')
        ->and((int) $f1->team_id)->toBe((int) $this->childA->id)
        ->and($f3->id)->not->toBe($f1->id)
        ->and(FoodAlchemistPlanningFrame::count())->toBe(2);
});

it('Kopf: Preisarchitektur p. P. setzen, leerer Wert löscht — jedes Feld optional', function () {
    $frame = $this->svc->frameFor($this->childA, 'foodbook', $this->foodbook->id);
    $frame = $this->svc->setHead($this->childA, $frame, ['target_price_pp' => '42.50', 'price_min_pp' => '35', 'price_max_pp' => '55']);

    expect((float) $frame->target_price_pp)->toBe(42.5)
        ->and((float) $frame->price_min_pp)->toBe(35.0);

    $frame = $this->svc->setHead($this->childA, $frame, ['price_min_pp' => '']);
    expect($frame->price_min_pp)->toBeNull()
        ->and((float) $frame->target_price_pp)->toBe(42.5); // nicht übergebene Felder bleiben
});

it('Slots: Dramaturgie-Reihenfolge + Mengengerüst + Preis-Anker je Slot', function () {
    $frame = $this->svc->frameFor($this->childA, 'concept', $this->concept->id);
    $this->svc->addSlot($this->childA, $frame, ['label' => 'Vorspeisen', 'slot_type' => 'gang', 'target_count' => 3, 'price_anchor' => 8.5]);
    $this->svc->addSlot($this->childA, $frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 4, 'is_pflicht' => true]);

    $summary = $this->svc->summary($frame->refresh());
    expect($summary['slots'])->toHaveCount(2)
        ->and($summary['slots'][0]['label'])->toBe('Vorspeisen')
        ->and($summary['slots'][0]['position'])->toBe(0)
        ->and($summary['slots'][1]['position'])->toBe(1)
        ->and($summary['slots'][1]['is_pflicht'])->toBeTrue()
        ->and($summary['slots'][0]['target_count'])->toBe(3);
});

it('Regeln validieren: diet_quota nur mit kanonischer Diätform + Wert, nogo_allergen nur EU-14-Key', function () {
    $frame = $this->svc->frameFor($this->childA, 'concept', $this->concept->id);

    expect(fn () => $this->svc->addRule($this->childA, $frame, ['rule_type' => 'diet_quota', 'ref_key' => 'flexitarisch', 'value_num' => 2, 'unit' => 'count']))
        ->toThrow(RuntimeException::class);
    expect(fn () => $this->svc->addRule($this->childA, $frame, ['rule_type' => 'diet_quota', 'ref_key' => 'vegan']))
        ->toThrow(RuntimeException::class); // value_num/unit fehlen
    expect(fn () => $this->svc->addRule($this->childA, $frame, ['rule_type' => 'nogo_allergen', 'ref_key' => 'erdnuss']))
        ->toThrow(RuntimeException::class); // deutscher Key ≠ EU-14-Vokabular

    $quota = $this->svc->addRule($this->childA, $frame, ['rule_type' => 'diet_quota', 'ref_key' => 'vegan', 'operator' => 'min', 'value_num' => 25, 'unit' => 'percent']);
    $nogo = $this->svc->addRule($this->childA, $frame, ['rule_type' => 'nogo_allergen', 'ref_key' => 'peanuts']);

    expect($quota->ref_key)->toBe('vegan')
        ->and($nogo->severity)->toBe('hart'); // No-Go-Default hart
});

it('season_coverage braucht eine team-sichtbare Saison', function () {
    $frame = $this->svc->frameFor($this->childA, 'concept', $this->concept->id);
    $saison = FoodAlchemistSaison::create(['team_id' => $this->rootTeam->id, 'name' => 'Sommer']); // geerbt sichtbar

    $rule = $this->svc->addRule($this->childA, $frame, ['rule_type' => 'season_coverage', 'ref_id' => $saison->id]);
    expect((int) $rule->ref_id)->toBe($saison->id);

    expect(fn () => $this->svc->addRule($this->childA, $frame, ['rule_type' => 'season_coverage', 'ref_id' => 999999]))
        ->toThrow(RuntimeException::class);
});

it('D1-Write-Guard: geerbtes Gerüst ist für Kind-Teams read-only', function () {
    $rootFb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Katalog-FB']);
    $frame = $this->svc->frameFor($this->rootTeam, 'foodbook', $rootFb->id);

    expect(fn () => $this->svc->setHead($this->childA, $frame, ['target_price_pp' => 10]))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
    expect(fn () => $this->svc->addSlot($this->childA, $frame, ['label' => 'Fremd-Slot']))
        ->toThrow(RuntimeException::class);
});

it('Owner-Sichtbarkeit: fremdes (Geschwister-)Foodbook ist kein gültiger Owner', function () {
    $fbB = FoodAlchemistFoodbook::create(['team_id' => $this->childB->id, 'label' => 'Geschwister-FB']);

    expect(fn () => $this->svc->frameFor($this->childA, 'foodbook', $fbB->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('replaceStructure (MCP-PUT-Pfad): deklarativ + idempotent, Slot-Regeln eingebettet', function () {
    $frame = $this->svc->frameFor($this->childA, 'concept', $this->concept->id, 'mcp_tool');
    expect($frame->created_via)->toBe('mcp_tool');

    $payload = [
        ['label' => 'Vorspeisen', 'slot_type' => 'gang', 'target_count' => 3,
            'rules' => [['rule_type' => 'diet_quota', 'ref_key' => 'vegan', 'operator' => 'min', 'value_num' => 1, 'unit' => 'count']]],
        ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 4],
    ];
    $rules = [['rule_type' => 'nogo_ingredient', 'value_text' => 'Innereien', 'severity' => 'hart']];

    $frame = $this->svc->replaceStructure($this->childA, $frame, $payload, $rules);
    $frame = $this->svc->replaceStructure($this->childA, $frame, $payload, $rules); // 2. Lauf = gleicher Zustand

    $summary = $this->svc->summary($frame);
    expect($summary['slots'])->toHaveCount(2)
        ->and($summary['slots'][0]['rules'])->toHaveCount(1)
        ->and($summary['rules'])->toHaveCount(1)
        ->and($summary['rules'][0]['value_text'])->toBe('Innereien');
});

it('promptKontext: nur gefüllte Dimensionen, leeres Gerüst → NULL', function () {
    $frame = $this->svc->frameFor($this->childA, 'concept', $this->concept->id);
    expect($this->svc->promptKontext($frame))->toBeNull();

    $this->svc->setHead($this->childA, $frame, ['target_price_pp' => 42.5]);
    $this->svc->addSlot($this->childA, $frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 4]);
    $this->svc->addRule($this->childA, $frame->refresh(), ['rule_type' => 'nogo_allergen', 'ref_key' => 'peanuts']);

    $kontext = $this->svc->promptKontext($frame->refresh());
    expect($kontext)->toContain('Planungs-Gerüst')
        ->and($kontext)->toContain('42,50')
        ->and($kontext)->toContain('Hauptgang')
        ->and($kontext)->toContain('Soll 4 Gerichte')
        ->and($kontext)->toContain('peanuts');
});

it('UI-Klick → DB: Gerüst über den Foodbook-Editor pflegen (Livewire-Host, Trait ManagesPlanningFrame)', function () {
    $user = $this->makeUser($this->childA);
    $this->actingAs($user);

    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Foodbooks\Index::class)
        ->call('waehle', $this->foodbook->id)
        ->set('frameHead.target_price_pp', '39.90')
        ->call('frameKopfSpeichern')
        ->set('frameNeuSlot.label', 'Dessert-Station')
        ->set('frameNeuSlot.slot_type', 'station')
        ->set('frameNeuSlot.target_count', '2')
        ->call('frameSlotHinzu')
        ->set('frameNeuRule.rule_type', 'nogo_ingredient')
        ->set('frameNeuRule.value_text', 'Aal')
        ->call('frameRegelHinzu')
        ->assertHasNoErrors();

    $frame = FoodAlchemistPlanningFrame::where('owner_type', 'foodbook')->where('owner_id', $this->foodbook->id)->first();
    expect($frame)->not->toBeNull()
        ->and((float) $frame->target_price_pp)->toBe(39.9)
        ->and($frame->created_via)->toBe('ui')
        ->and($frame->slots()->count())->toBe(1)
        ->and($frame->slots()->first()->label)->toBe('Dessert-Station')
        ->and((int) $frame->slots()->first()->target_count)->toBe(2)
        ->and($frame->rules()->first()->value_text)->toBe('Aal');
});

it('Kollisionsfreiheit: Gerüst-Anlage lässt bestehende food_dna-Canvas-Werte unangetastet', function () {
    $canvasSvc = app(CanvasService::class);
    $canvas = $canvasSvc->canvasFor($this->childA, 'food_dna', 'team', $this->childA->id);
    $canvasSvc->setSkalar($canvas, 'no_gos', 'keine Innereien, kein Aal');
    $fbCanvas = $canvasSvc->canvasFor($this->childA, 'foodbook', 'foodbook', $this->foodbook->id);
    $canvasSvc->setSkalar($fbCanvas, 'leitidee', 'Sommer, leicht, regional');
    $entriesVorher = FoodAlchemistCanvasEntry::count();

    $frame = $this->svc->frameFor($this->childA, 'foodbook', $this->foodbook->id);
    $this->svc->setHead($this->childA, $frame, ['target_price_pp' => 39]);
    $this->svc->addRule($this->childA, $frame, ['rule_type' => 'nogo_ingredient', 'value_text' => 'Innereien']);

    expect(FoodAlchemistCanvasEntry::count())->toBe($entriesVorher)
        ->and($canvasSvc->werte($canvas->refresh())['no_gos'])->toBe('keine Innereien, kein Aal')
        ->and($canvasSvc->werte($fbCanvas->refresh())['leitidee'])->toBe('Sommer, leicht, regional');
});
