<?php

use Platform\Core\Contracts\ToolMetadataContract;
use Platform\FoodAlchemist\Support\KiUmleitung;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * #1002 · Wächter „KI-Weg vollständig“: jedes MCP-Tool, das KI-Token kostet (cost_class `llm`/`ai_call`), hat einen externen Weg —
 * Tool-Hülle ({@see KiUmleitung::TOOLS}), Lauf-Flag/Job-Erbe ({@see KiUmleitung::LAUF_FLAG}) — oder steht mit Begründung in
 * {@see KiUmleitung::KI_AUSNAHMEN}. Anlass (03.11.): recipe_findings_run.POST und concepts.ENRICH liefen per MCP still über den
 * Plattform-Provider, weil kein Test die Liste gegen die Tool-Metadaten hielt.
 *
 * Umgekehrt muss jedes Tool in diesen Listen existieren; in TOOLS/LAUF_FLAG trägt es eine KI-cost_class (sonst lügt sie). Bild-,
 * Sprach- und Embedding-Wege tragen keine KI-cost_class oder stehen in KI_AUSNAHMEN.
 */
const KIWEG_KOSTENKLASSEN = ['llm', 'ai_call'];

function kiweg_tools(): array
{
    $liste = require __DIR__ . '/../../src/Tools/_liste.php';
    $tools = [];
    foreach ($liste as $klasse) {
        $tool = app($klasse);
        $tools[$tool->getName()] = $tool instanceof ToolMetadataContract ? (string) ($tool->getMetadata()['cost_class'] ?? '') : '';
    }

    return $tools;
}

it('jedes KI-Tool (cost_class llm/ai_call) steht in TOOLS, LAUF_FLAG oder KI_AUSNAHMEN', function () {
    $fehlend = [];
    foreach (kiweg_tools() as $name => $klasse) {
        if (! in_array($klasse, KIWEG_KOSTENKLASSEN, true)) {
            continue;
        }
        if (! isset(KiUmleitung::TOOLS[$name]) && ! isset(KiUmleitung::LAUF_FLAG[$name]) && ! isset(KiUmleitung::KI_AUSNAHMEN[$name])) {
            $fehlend[] = "{$name} ({$klasse})";
        }
    }

    expect($fehlend)->toBe([], 'KI-Tool ohne externen Weg und ohne Ausnahme — in KiUmleitung::TOOLS (Hülle), LAUF_FLAG (Job) oder KI_AUSNAHMEN (mit Grund) eintragen');
});

it('die Listen sind disjunkt, begründet und zeigen auf existierende KI-Tools', function () {
    $tools = kiweg_tools();
    $listen = ['TOOLS' => KiUmleitung::TOOLS, 'LAUF_FLAG' => KiUmleitung::LAUF_FLAG, 'KI_AUSNAHMEN' => KiUmleitung::KI_AUSNAHMEN];
    $gesehen = [];
    foreach ($listen as $liste => $eintraege) {
        foreach ($eintraege as $name => $grund) {
            expect(array_key_exists($name, $tools))->toBeTrue("{$liste}: {$name} ist kein registriertes Tool");
            if ($liste !== 'KI_AUSNAHMEN') {   // Ausnahmen dürfen eine andere KI-Art tragen (Bildgenerierung: external_api)
                expect(in_array($tools[$name], KIWEG_KOSTENKLASSEN, true))->toBeTrue("{$liste}: {$name} hat cost_class „{$tools[$name]}“ — ein KI-Tool trägt llm");
            }
            expect(trim($grund))->not->toBe('', "{$liste}: {$name} ohne Begründung");
            expect(array_key_exists($name, $gesehen))->toBeFalse("{$name} steht in {$liste} und " . ($gesehen[$name] ?? ''));
            $gesehen[$name] = $liste;
        }
    }
});

it('Ausnahmen sind entschieden oder als Folgepaket markiert', function () {
    foreach (KiUmleitung::KI_AUSNAHMEN as $name => $grund) {
        expect(preg_match('/^(ABGENOMMEN|AUSGENOMMEN|OFFEN, Folgepaket):/u', $grund))->toBe(1, "{$name}: Grund beginnt mit ABGENOMMEN:, AUSGENOMMEN: oder „OFFEN, Folgepaket:“");
    }
    // Dominique 03.11.: „Bilder bleiben so“ — die Bildgenerierung ist die bewusste Ausnahme
    expect(KiUmleitung::KI_AUSNAHMEN)->toHaveKey('foodalchemist.recipe_images.GENERATE');
});

it('eine Kostenklasse für KI: llm (llm_call ist vereinheitlicht)', function () {
    $alt = array_keys(array_filter(kiweg_tools(), fn ($k) => $k === 'llm_call'));

    expect($alt)->toBe([], 'cost_class llm_call → llm');
});
