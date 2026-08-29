<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatImage;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D6: Format-Aufbau — formats.STATUS + format_slots.REORDER/MOVE/WORDING
 * + format_blocks.POST/PUT + format_images.HERO/CAPTION/REORDER/CLEAR. Binär-Upload deferred.
 * Concept-Slot-Insert/Delete = bestehende format_editions.POST/DELETE.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->fmt = app(FormatService::class);
    $this->format = $this->fmt->create($this->rootTeam, ['name' => 'URBAN FUSION']);
});

it('Registry-Smoke: alle 10 D6-Tools registriert mit type=object', function () {
    $namen = [
        'formats.STATUS', 'format_slots.REORDER', 'format_slots.MOVE', 'format_slots.WORDING',
        'format_blocks.POST', 'format_blocks.PUT',
        'format_images.HERO', 'format_images.CAPTION', 'format_images.REORDER', 'format_images.CLEAR',
    ];
    foreach ($namen as $n) {
        $tool = $this->registry->get("foodalchemist.{$n}");
        expect($tool)->not->toBeNull($n);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $n);
    }
});

it('formats.STATUS: draft→active (confirm-Marker, kein Hard-Flag)', function () {
    $st = ($this->run)('foodalchemist.formats.STATUS', ['id' => $this->format->id, 'status' => 'active']);
    expect($st->success)->toBeTrue('status: ' . ($st->error ?? ''));
    expect($this->format->fresh()->status)->toBe('active');

    expect(($this->run)('foodalchemist.formats.STATUS', ['id' => $this->format->id, 'status' => 'quatsch'])->errorCode)->toBe('VALIDATION_ERROR');
});

it('format_blocks.POST/PUT + format_slots.REORDER/MOVE', function () {
    $b1 = ($this->run)('foodalchemist.format_blocks.POST', ['format_id' => $this->format->id, 'type' => 'header', 'felder' => ['title' => 'Vorspeisen']]);
    $b2 = ($this->run)('foodalchemist.format_blocks.POST', ['format_id' => $this->format->id, 'type' => 'text', 'felder' => ['text_content' => 'Frisch & regional']]);
    expect($b1->success)->toBeTrue('b1: ' . ($b1->error ?? ''))->and($b2->success)->toBeTrue('b2: ' . ($b2->error ?? ''));
    $s1 = $b1->data['slot_id'];
    $s2 = $b2->data['slot_id'];

    $put = ($this->run)('foodalchemist.format_blocks.PUT', ['slot_id' => $s1, 'felder' => ['title' => 'Amuse']]);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''));
    expect(FoodAlchemistFormatSlot::find($s1)->title)->toBe('Amuse');

    $re = ($this->run)('foodalchemist.format_slots.REORDER', ['format_id' => $this->format->id, 'ids' => [$s2, $s1]]);
    expect($re->success)->toBeTrue('reorder: ' . ($re->error ?? ''));
    expect(FoodAlchemistFormatSlot::find($s2)->position)->toBe(1);

    $mv = ($this->run)('foodalchemist.format_slots.MOVE', ['slot_id' => $s2, 'after_slot_id' => $s1]);
    expect($mv->success)->toBeTrue('move: ' . ($mv->error ?? ''));
    expect(FoodAlchemistFormatSlot::find($s1)->position)->toBeLessThan(FoodAlchemistFormatSlot::find($s2)->position);
});

it('format_slots.WORDING: format-lokaler Override eines Concept-Slots', function () {
    $concepts = app(ConceptService::class);
    $concept = $concepts->create($this->rootTeam, ['name' => 'Menü A']);
    $cslot = $concepts->addSlot($this->rootTeam, $concept->id, ['role' => 'Vorspeise']);

    $formatSlot = $this->fmt->slotConceptEinfuegen($this->rootTeam, $this->format->id, $concept->id);

    $w = ($this->run)('foodalchemist.format_slots.WORDING', [
        'format_slot_id' => $formatSlot->id, 'concept_slot_id' => $cslot->id, 'text' => 'Gruß aus der Küche',
    ]);
    expect($w->success)->toBeTrue('wording: ' . ($w->error ?? ''));
    expect(FoodAlchemistFormatSlot::find($formatSlot->id)->payload_json['wording_overrides'][(string) $cslot->id] ?? null)->toBe('Gruß aus der Küche');
});

it('format_images.HERO/CAPTION/REORDER/CLEAR (fileless Rows)', function () {
    $img1 = FoodAlchemistFormatImage::create(['team_id' => $this->rootTeam->id, 'format_id' => $this->format->id, 'sort_order' => 10, 'is_hero' => true]);
    $img2 = FoodAlchemistFormatImage::create(['team_id' => $this->rootTeam->id, 'format_id' => $this->format->id, 'sort_order' => 20, 'is_hero' => false]);

    $hero = ($this->run)('foodalchemist.format_images.HERO', ['image_id' => $img2->id]);
    expect($hero->success)->toBeTrue('hero: ' . ($hero->error ?? ''));
    expect((bool) $img2->fresh()->is_hero)->toBeTrue()->and((bool) $img1->fresh()->is_hero)->toBeFalse();

    $cap = ($this->run)('foodalchemist.format_images.CAPTION', ['image_id' => $img1->id, 'caption' => 'Buffet']);
    expect($cap->success)->toBeTrue('caption: ' . ($cap->error ?? ''));
    expect($img1->fresh()->caption)->toBe('Buffet');

    $re = ($this->run)('foodalchemist.format_images.REORDER', ['format_id' => $this->format->id, 'ids' => [$img2->id, $img1->id]]);
    expect($re->success)->toBeTrue('reorder: ' . ($re->error ?? ''));
    expect($img2->fresh()->sort_order)->toBeLessThan($img1->fresh()->sort_order);

    expect(($this->run)('foodalchemist.format_images.CLEAR', ['image_id' => $img1->id])->errorCode)->toBe('CONFIRM_REQUIRED');
    $del = ($this->run)('foodalchemist.format_images.CLEAR', ['image_id' => $img1->id, 'confirm' => true]);
    expect($del->success)->toBeTrue('clear: ' . ($del->error ?? ''));
    expect(FoodAlchemistFormatImage::find($img1->id))->toBeNull();
});

it('Guards: unbekannt NOT_FOUND, fremd ACCESS_DENIED', function () {
    expect(($this->run)('foodalchemist.formats.STATUS', ['id' => 999999, 'status' => 'active'])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.formats.STATUS', ['id' => $this->format->id, 'status' => 'active'], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
    expect(($this->run)('foodalchemist.format_blocks.PUT', ['slot_id' => 999999, 'felder' => ['title' => 'x']])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.format_images.HERO', ['image_id' => 999999])->errorCode)->toBe('NOT_FOUND');
});
