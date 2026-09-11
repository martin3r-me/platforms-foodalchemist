<?php

use Platform\FoodAlchemist\Services\VoiceCommandService;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Das Mikrofon soll handeln koennen wie der MCP-Agent (Entscheid Dominique 2026-09-11).
 *
 * Die Faehigkeit war da: die Policy laesst JEDES lesende `foodalchemist.*`-Tool zu, also auch
 * die Wissens-Werkzeuge. Nur wusste das Modell nichts davon — der Startkatalog nannte keines,
 * und die System-Nachricht erwaehnte das Wissensmodul mit keinem Wort. Ein Werkzeug, von dem
 * das Modell nichts weiss, existiert fuer es nicht.
 */
it('★ der Startkatalog nennt den Ablauf und die Wissenssuche', function () {
    expect(VoiceCommandService::TOOLS)
        ->toContain('foodalchemist.ablauf.GET')
        ->toContain('foodalchemist.knowledge.SEARCH');
});

it('die Wissens-Werkzeuge sind auch von der Policy freigegeben', function () {
    $registry = app(\Platform\Core\Tools\ToolRegistry::class);
    foreach (['foodalchemist.ablauf.GET', 'foodalchemist.knowledge.SEARCH'] as $name) {
        $tool = $registry->get($name);
        expect($tool)->not->toBeNull()
            ->and(VoiceCommandService::darfNutzen($name, $tool))->toBeTrue();
    }
});

it('★ schreibende Wissens-Tools bleiben gesperrt — Sprache aendert kein Wissen', function () {
    $registry = app(\Platform\Core\Tools\ToolRegistry::class);
    foreach (['foodalchemist.knowledge.PUT', 'foodalchemist.knowledge.EINORDNEN', 'foodalchemist.knowledge_budget.PUT'] as $name) {
        expect(VoiceCommandService::darfNutzen($name, $registry->get($name)))->toBeFalse();
    }
});
