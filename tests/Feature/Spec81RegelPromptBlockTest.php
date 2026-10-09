<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Services\Regeln\RegelPromptBlock;
use Platform\FoodAlchemist\Services\Regeln\RegelService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 81 F5 — aktive Regeln als Systemnachricht je Prompt-Key (Live-Test demo 09.10.: „Knusprige Komponente:"
 * als Typ erfunden). Byte-stabil hinter dem Kanon, an alle Konsumenten, nie im Kontext-JSON.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->block = fn (string $key) => app(RegelPromptBlock::class)->fuerPromptKey($key);
});

it('Generator: Typ-Vokabular gruppiert, nur schreibrelevante aktive Regeln', function () {
    $b = ($this->block)('recipe.generator');

    expect($b)->toContain('- Knusprige Komponenten: ')->toContain('Krokant')
        ->toContain('die Gruppennamen selbst sind KEINE Typen')
        ->toContain('§2 Schnittform')->toContain('FRISCHEN Grundprodukten')
        ->not->toContain('Verpackungswort')     // GP-Benennung gehört nicht in den Rezept-Generator
        ->not->toContain('Default-Grundprodukte'); // Zuordnung korrigiert der Code selbst
});

it('geht an alle Konsumenten: Überarbeiten (Heilung), Review, Plan, Namen, GP-Vorschlag', function () {
    foreach (['recipe.ueberarbeiten', 'recipe.review', 'recipe.komponenten_plan', 'recipe.name_putzen', 'vk.generator'] as $key) {
        expect(($this->block)($key))->toContain('Typ-Vokabular');
    }
    expect(($this->block)('gp.suggest'))->toContain('Verpackungswort')->not->toContain('Typ-Vokabular')
        ->and(($this->block)('recipe.category'))->toBeNull();
});

it('VK-Regeln nur im Gericht, ausgeschaltete Regeln nirgends', function () {
    expect(($this->block)('vk.generator'))->not->toContain('Hauptgruppen-Kürzel');

    app(RegelService::class)->setzeAktiv(FoodAlchemistRule::where('schluessel', 'vk.1.1.hg')->firstOrFail()->id, true);

    expect(($this->block)('vk.generator'))->toContain('Hauptgruppen-Kürzel')
        ->and(($this->block)('recipe.generator'))->not->toContain('Hauptgruppen-Kürzel');
});

it('ist byte-stabil (Prefix-Cache): gleiche Regeln → identischer Block, auch nach Neuladen', function () {
    $a = ($this->block)('recipe.generator');
    RegelBuch::vergessen();   // frisch aus der DB geladen → derselbe Block

    expect(($this->block)('recipe.generator'))->toBe($a)->not->toMatch('/\d{4}-\d{2}-\d{2}/');
});

it('der Gateway sendet den Block als Systemnachricht hinter dem Kanon, nicht im Kontext-JSON', function () {
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $spion = new class extends \Platform\FoodAlchemist\Services\Ai\FakeAiProvider {
        public array $gesendet = [];

        public function chat(array $messages, array $options = []): array
        {
            $this->gesendet = $messages;

            return parent::chat($messages, $options);
        }
    };
    app()->instance(\Platform\FoodAlchemist\Services\Ai\FakeAiProvider::class, $spion);

    try {
        app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose('recipe.generator', ['x' => 1]);
    } catch (\Throwable $e) {
        // Antwort-Validierung des Fake-Echos ist hier egal — es zählt, was gesendet wurde.
    }

    $system = collect($spion->gesendet)->where('role', 'system')->pluck('content')->implode("\n");
    $user = collect($spion->gesendet)->where('role', 'user')->pluck('content')->implode("\n");
    expect($spion->gesendet)->not->toBe([])
        ->and($system)->toContain('VERBINDLICHE REGELN')->toContain('Krokant')
        ->and($user)->not->toContain('VERBINDLICHE REGELN');
});
