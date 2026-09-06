<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Spec 50 · C-8: Struktur-Vokabular (Header-Presets, Sektions-Gerüst, Gerüst-Vorschau) als Read. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->run = fn (string $n, array $a = []) => $this->registry->get($n)->execute($a, $this->kontext);
});

it('struktur_vokabular.GET liefert Header-Presets mit Slug-Lineage, Sektions-Gerüst und alle Gerüst-Vorschauen', function () {
    $res = ($this->run)('foodalchemist.struktur_vokabular.GET');
    expect($res->success)->toBeTrue()
        ->and($res->data['header_presets'])->toBe(FoodbookService::headerPresets())
        ->and($res->data['sektions_geruest'])->toBe(FormatService::SEKTIONS_GERUEST)
        ->and(array_keys($res->data['geruest_vorschau']))->toBe(['buffet', 'menue_3', 'menue_4', 'menue_5', 'menue_6', 'menue_7', 'menue_8', 'menue_9']);

    // Jedes Preset trägt slug + label + type — das ist die Lineage für header_source.
    foreach ($res->data['header_presets'] as $gruppe => $items) {
        foreach ($items as $p) {
            expect($p)->toHaveKeys(['slug', 'label', 'type'])->and($p['slug'])->toContain('.');
        }
    }
    expect($res->data['geruest_vorschau']['menue_3'])->toHaveCount(3)
        ->and(array_column($res->data['geruest_vorschau']['menue_3'], 'label'))->toBe(['Vorspeise', 'Hauptgang', 'Dessert'])
        ->and($res->data['geruest_vorschau']['buffet'][0])->toMatchArray(['slot_type' => 'station', 'target_count' => 3, 'is_pflicht' => true]);
});

it('struktur_vokabular.GET typ=menue gaenge=5: Vorschau = exakt die Header, die concepts.POST geruest anlegt', function () {
    $vorschau = ($this->run)('foodalchemist.struktur_vokabular.GET', ['typ' => 'menue', 'gaenge' => 5]);
    expect($vorschau->success)->toBeTrue()->and($vorschau->data)->not->toHaveKey('header_presets');
    $labels = array_column($vorschau->data['geruest_vorschau']['menue'], 'label');
    expect($labels)->toHaveCount(5);

    $angelegt = ($this->run)('foodalchemist.concepts.POST', ['name' => 'Fünf Gänge', 'geruest' => ['typ' => 'menue', 'gaenge' => 5]]);
    $header = FoodAlchemistConceptSlot::where('concept_id', $angelegt->data['concept']['id'])->where('type', 'header')->orderBy('position')->pluck('title')->all();
    expect($header)->toBe($labels);
});

it('struktur_vokabular.GET: unbekannter Typ ⇒ VALIDATION_ERROR', function () {
    $res = ($this->run)('foodalchemist.struktur_vokabular.GET', ['typ' => 'cocktail']);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('VALIDATION_ERROR');
});
