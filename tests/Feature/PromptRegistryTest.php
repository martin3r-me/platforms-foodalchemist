<?php

use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * M7-04: Prompt-Registry == Anhang-A-Inventar (06_KI). Bewusst NICHT in der
 * Registry (dokumentiert in config): #2 TEMPLATE_FILL + #38 AGENTIC_RESOLVER
 * (Tool-Loops → M7-10/M8-01), #37 FOODBOOK_PLAN (Phase 2 ⚠D5),
 * #39 DISAMBIG (toter Code).
 */
/**
 * KI-Klassen (16.10.2026): jeder Prompt-Key gehört zu GENAU EINER Klasse (Feld `klasse`), die Klasse bestimmt Modell + Reasoning.
 *  stark  = Sicherheit/Fakten (Allergene, Nährwerte, Produktpass, Rezept einlesen) + alles Erzeugende, Planende, Prüfende, Chat
 *  mittel = fachliche Schätzungen, die in Mengen und Kosten einfließen (im Zweifel stark)
 *  leicht = Einzelwerte aus kurzen Listen, Titel-/Namensvorschläge, Schlagworte, Non-Food
 */
const REGISTRY_KLASSEN = [
    'stark' => [
        'planung.leitplanken', 'component.replacement_suggest', 'recipe.komponenten_plan', 'recipe.generator',
        'recipe.description', 'vk.generator', 'gp.allergene', 'gp.naehrwerte', 'anker.wissen_auslese', 'recipe.steps', 'recipe.review',
        'conformance.check', 'gp.conformance_revise', 'recipe.ueberarbeiten', 'recipe.extract', 'produktpass.extract', 'vk.plating',
        'vk.marketing', 'vk.wording', 'concept.brief_geruest', 'foodbook.grundgeruest', 'format.grundgeruest', 'concept.plan',
        'concept.wording', 'foodbook.kapitel_ideen', 'planning.dish_proposal_revise', 'foodbook.kundentext', 'praesentation.design_css',
        'vk.review', 'vk.ueberarbeiten', 'vk.kohaerenz', 'vk.teller_heber', 'price.plausi', 'chat.message', 'signal.supplier_inquiry',
        'signal.margin_levers', 'signal.vk_release_advice',
    ],
    'mittel' => [
        'gp.suggest',   // Dominique 19.10. (vorher stark)
        'recipe.geschmack', 'gp.domain',
        'recipe.posten', 'recipe.garverlust', 'recipe.ruestschritt', 'vk.rollen', 'gp.piece_default_g', 'gp.verluste',
        'gp.zaehl_einheiten', 'recipe.verpackungsmasse', 'gp.la_suggest', 'gp.la_abgleich', 'gp.term_la_rank', 'recipe.eigenschaften', 'recipe.sensorik', 'gp.sensorik',
        'recipe.regeneration', 'vk.regeneration',
    ],
    'leicht' => [
        'artikel.web_recherche',   // Kostenziel ≤ 0,05 € je Klick (Dominique 19.10., vorher stark)
        'la.nonfood', 'trend.eingang_erschliessen', 'gp.condition', 'recipe.category', 'recipe.name_putzen', 'recipe.titel_vorschlag', 'vk.speisen_klasse', 
        'gp.anker', 'recipe.sektor', 'recipe.level', 'recipe.production_depth', 'recipe.bauart', 'recipe.equipment',
        'vk.titel_vorschlag', 'recipe.dichteklasse', 'vk.servier_vehikel', 'gp.tags', 'signal.serving_form_suggest',
        'signal.recipe_category_suggest', 'signal.recipe_naming_suggest',
    ],
];

function registryKlasseSoll(): array
{
    $soll = [];
    foreach (REGISTRY_KLASSEN as $klasse => $keys) {
        foreach ($keys as $key) {
            expect($soll)->not->toHaveKey($key, "Key doppelt in der Soll-Liste: {$key}");
            $soll[$key] = $klasse;
        }
    }

    return $soll;
}

it('Registry vollständig: alle Soll-Keys vorhanden, mit task + genau einer gültigen Klasse', function () {
    $registry = config('foodalchemist.prompts');

    foreach (registryKlasseSoll() as $key => $klasse) {
        expect($registry)->toHaveKey($key);
        expect($registry[$key]['task'] ?? '')->not->toBe('', "Task fehlt: {$key}");
        expect($registry[$key]['klasse'] ?? null)->toBe($klasse, "Klasse von {$key}");
        expect($registry[$key])->not->toHaveKey('tier');   // keine zweite Wahrheit neben der Klasse
    }
});

it('keine unbekannten Keys außer demo.echo (Inventar-Disziplin)', function () {
    $extra = array_diff(array_keys(config('foodalchemist.prompts')), array_keys(registryKlasseSoll()), ['demo.echo']);

    expect(array_values($extra))->toBe([]);
});

it('jeder Key hat genau eine gültige Klasse (stark|mittel|leicht)', function () {
    foreach (config('foodalchemist.prompts') as $key => $p) {
        if ($key === 'demo.echo') {
            continue;
        }
        expect(\Platform\FoodAlchemist\Support\KiKlassen::gueltig($p['klasse'] ?? null))->toBeTrue("Klasse fehlt/ungültig: {$key}");
    }
});

it('Sicherheit und Fakten (Allergene, Nährwerte, Produktpass, Rezept einlesen) sind STARK', function () {
    foreach (['gp.allergene', 'gp.naehrwerte', 'produktpass.extract', 'recipe.extract', 'recipe.steps', 'vk.plating', 'vk.marketing', 'chat.message'] as $key) {
        expect(config('foodalchemist.prompts')[$key]['klasse'])->toBe('stark', $key);
    }
});

it('Non-Food-Erkennung und Titelvorschläge sind LEICHT, Mengen-Schätzungen MITTEL', function () {
    foreach (['la.nonfood', 'recipe.titel_vorschlag', 'vk.titel_vorschlag'] as $key) {
        expect(config('foodalchemist.prompts')[$key]['klasse'])->toBe('leicht', $key);
    }
    foreach (['gp.verluste', 'recipe.garverlust', 'gp.piece_default_g', 'recipe.verpackungsmasse'] as $key) {
        expect(config('foodalchemist.prompts')[$key]['klasse'])->toBe('mittel', $key);
    }
});

it('Entscheid Dominique 19.10.: gp.suggest ist MITTEL (vorher 16.10. STARK), recipe.geschmack und gp.domain sind MITTEL', function () {
    expect(config('foodalchemist.prompts')['gp.suggest']['klasse'])->toBe('mittel')
        ->and(config('foodalchemist.prompts')['gp.suggest']['task'])->toContain('§8.17')->toContain('Karottenpüree')
        ->and(config('foodalchemist.prompts')['recipe.geschmack']['klasse'])->toBe('mittel')
        ->and(config('foodalchemist.prompts')['gp.domain']['klasse'])->toBe('mittel');
});
