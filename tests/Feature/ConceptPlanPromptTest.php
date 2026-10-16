<?php

use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Et.2b »Kreativ-Kopf« — der erste Baustein ist der Prompt `concept.plan`:
 * Kunden-Brief → kreative Concept-Canvas (Leitidee/USP/Inszenierung/Geschmackswelten).
 * Reiner Contract-Test (die Service-Verdrahtung `planAusBrief` folgt im nächsten Chunk).
 */

it('concept.plan ist mit KI-Klasse STARK und Task registriert', function () {
    // Seit #447 (KI-Klassen STARK/MITTEL/LEICHT statt Stufen A–D) trägt jeder Prompt `klasse` statt `tier`.
    // Der Kreativ-Kopf formt die Leitidee eines Konzepts — er läuft bewusst auf der starken Klasse.
    $p = config('foodalchemist.prompts', [])['concept.plan'] ?? null;

    expect($p)->toBeArray()
        ->and($p)->not->toHaveKey('tier')
        ->and(\Platform\FoodAlchemist\Support\KiKlassen::von($p))->toBe(\Platform\FoodAlchemist\Support\KiKlassen::STARK)
        ->and($p['klasse'] ?? null)->toBe('stark')
        ->and($p['task'] ?? '')->not->toBe('');
});

it('concept.plan liefert genau die kreativen Concept-Canvas-Felder', function () {
    $task = config('foodalchemist.prompts', [])['concept.plan']['task'] ?? '';

    // Die vom Prompt geforderten werte-Felder decken die kreativen Canvas-Felder ab.
    foreach (['name_claim', 'leitidee', 'usp_eignung', 'inszenierung', 'geschmackswelten'] as $feld) {
        expect($task)->toContain($feld);
    }

    // Gegenprobe: die Canvas kennt diese Feld-Keys wirklich (kein Auseinanderlaufen Prompt ↔ Template).
    $canvasKeys = array_column(CanvasService::TEMPLATES['concept']['felder'], 'key');
    foreach (['name_claim', 'leitidee', 'usp_eignung', 'inszenierung', 'geschmackswelten'] as $feld) {
        expect($canvasKeys)->toContain($feld);
    }
});

it('concept.plan hängt in der Food-DNA-Kette (Concept-Leitidee = Marke)', function () {
    expect(AiGatewayService::FOOD_DNA_KEYS)->toContain('concept.plan');
});
