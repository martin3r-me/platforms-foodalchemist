<?php

use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * M7-04: Prompt-Registry == Anhang-A-Inventar (06_KI). Bewusst NICHT in der
 * Registry (dokumentiert in config): #2 TEMPLATE_FILL + #38 AGENTIC_RESOLVER
 * (Tier-D-Tool-Loops → M7-10/M8-01), #37 FOODBOOK_PLAN (Phase 2 ⚠D5),
 * #39 DISAMBIG (toter Code).
 */
const REGISTRY_SOLL = [
    // GP-Welt
    'gp.suggest' => 'B', 'gp.zustand' => null, 'gp.tags' => 'C', 'gp.allergene' => 'A',
    'gp.domain' => 'B', 'gp.stk_default_g' => 'B', 'gp.zaehl_einheiten' => 'B',
    'gp.anker' => 'B', 'gp.rolle' => 'B', 'gp.la_suggest' => 'B', 'gp.term_la_rank' => 'B',
    // Rezept-Welt
    'recipe.generator' => 'B', 'recipe.beschreibung' => 'C', 'recipe.kategorie' => 'D',
    'recipe.garverlust' => 'C', 'recipe.name_putzen' => 'D', 'recipe.sektor' => 'B',
    'recipe.niveau' => 'B', 'recipe.sub_typ' => 'B', 'recipe.fertigungstiefe' => 'B',
    'recipe.zubereitung' => 'A', 'recipe.eigenschaften' => 'B', 'recipe.geschmack' => 'B',
    'recipe.review' => 'A', 'recipe.pairing' => 'A', 'recipe.anker' => 'B',
    'recipe.equipment' => 'B', 'recipe.extract' => 'C',
    'recipe.ueberarbeiten' => 'A',                                    // R6: KI-Überarbeiten (freie Anweisung, Ist-Button)
    'gp.naehrwerte' => 'B',                                           // R10: Nährwert-Fallback ohne LA-Daten (Ist-Feature)
    // VK-Welt
    'vk.generator' => 'B', 'vk.speisen_klasse' => 'B', 'vk.rollen' => 'B',
    'vk.plating' => 'A', 'vk.name_putzen' => 'B', 'vk.marketing' => 'A', 'vk.wording' => 'A',
    'vk.behaelter' => 'B', 'vk.regeneration' => 'B', 'vk.servier_vehikel' => 'B',
    'vk.review' => 'A', 'vk.kohaerenz' => 'A', 'vk.teller_heber' => 'A',
    // Concepter
    'concept.wording' => 'A',                                         // Concept-übergreifendes Wording (Schreibstil → Position-Namen + Intro)
    // Sonstiges
    'price.plausi' => 'B', 'chat.message' => 'A',
];

it('Registry vollständig: alle Soll-Keys vorhanden, mit task + gültigem Tier', function () {
    $registry = config('foodalchemist.prompts');

    foreach (array_keys(REGISTRY_SOLL) as $key) {
        expect($registry)->toHaveKey($key);
        expect($registry[$key]['task'] ?? '')->not->toBe('', "Task fehlt: {$key}");
        expect($registry[$key]['tier'] ?? '')->toBeIn(['A', 'B', 'C', 'D'], "Tier ungültig: {$key}");
    }
});

it('keine unbekannten Keys außer demo.echo (Inventar-Disziplin)', function () {
    $extra = array_diff(array_keys(config('foodalchemist.prompts')), array_keys(REGISTRY_SOLL), ['demo.echo']);

    expect(array_values($extra))->toBe([]);
});

it('Compliance- und V-02-Features sind Tier A (06_KI §2-Begründung)', function () {
    foreach (['gp.allergene', 'recipe.zubereitung', 'vk.plating', 'recipe.pairing', 'vk.marketing'] as $key) {
        expect(config('foodalchemist.prompts')[$key]['tier'])->toBe('A', $key);
    }
});
