<?php

use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Gemessen an Rezept 3751 (13.09.2026, eigener Ende-zu-Ende-Lauf auf demo).
 *
 * Im Auftrag stand „wird am Einsatztag im Kombidämpfer regeneriert", und der Generator hatte
 * das sauber übernommen — in der `description` stand wörtlich „Regeneration im Kombidämpfer am
 * Einsatztag". Der Regenerations-Schritt wählte trotzdem Induktion.
 *
 * Der Grund war kein Eigensinn des Modells: `proposeRegeneration` übergab Name, Kategorie,
 * Zutaten und die Geräteliste — die Beschreibung NICHT. Der Schritt hat eine ausdrückliche
 * Vorgabe überstimmt, weil sie im selben Datensatz stand und trotzdem nicht ankam.
 *
 * Dasselbe fehlende Feld erklärt die leeren Zahlenfelder: der Prompt verlangt zu Recht „nie
 * raten" — ohne Beschreibung und ohne Prozesstext gibt es aber nichts, worauf sich Temperatur
 * und Dauer stützen liessen. Die Zeile trug danach ein Gerät und keine einzige Zahl.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->rezept = $this->makeRecipe($this->rootTeam, 'Kontext-Test', [
        'description' => 'Cook-&-Chill-Produktion, Regeneration im Kombidämpfer am Einsatztag.',
        'preparation' => 'Gemüse anschwitzen, aufgiessen, mixen, abschmecken.',
    ]);
});

it('★ gibt Beschreibung UND Zubereitung an den Regenerations-Schritt weiter', function () {
    $gesehen = null;

    $this->mock(AiGatewayService::class, function ($mock) use (&$gesehen) {
        $mock->shouldReceive('propose')
            ->andReturnUsing(function (string $key, array $kontext) use (&$gesehen) {
                if ($key === 'recipe.regeneration') {
                    $gesehen = $kontext;
                }

                return new AiProposal(werte: ['kalt' => true], confidence: 0.5);
            });
    });

    app(BulkEnrichService::class)->starte($this->rootTeam, [$this->rezept->id], ['regeneration']);

    expect($gesehen)->not->toBeNull()
        // Ohne diese beiden Felder waehlt der Schritt blind — genau so ging bei 3751 eine
        // ausdrueckliche Kombidaempfer-Vorgabe verloren.
        ->and($gesehen)->toHaveKey('beschreibung')
        ->and($gesehen['beschreibung'])->toContain('Kombidämpfer')
        ->and($gesehen)->toHaveKey('zubereitung')
        ->and($gesehen['zubereitung'])->toContain('anschwitzen');
});

it('der Prompt sagt ausdruecklich, dass eine genannte Vorgabe Vorrang hat', function () {
    // ⚠ NICHT config('…prompts.recipe.regeneration.task'): der Prompt-Key enthaelt selbst einen
    // Punkt, die Punkt-Notation zerlegt ihn falsch und liefert still null.
    $task = (string) (config('foodalchemist.prompts')['recipe.regeneration']['task'] ?? '');

    expect($task)->toContain('beschreibung oder zubereitung')
        ->and($task)->toContain('dann gilt DAS');
});
