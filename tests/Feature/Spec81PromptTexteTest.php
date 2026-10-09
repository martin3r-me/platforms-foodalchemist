<?php

use Platform\FoodAlchemist\Services\RecipeKomponentenPlanService;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Spec 81 „Regeln als Daten" — Prompt-Texte ohne zweite Wahrheit (2026-10-09).
 *
 * Die Regeln (Typ-Vokabular §1.2, Satzzahl §8.3) stehen in der Regel-Tabelle und kommen mitgegeben in den
 * Prompt. Die festen Anweisungstexte dürfen weder eine eigene Zahl daneben führen noch auf Dossier-Abschnitte
 * zeigen, die Paket 5 (Dossiers bereinigen) entfernt, noch die KI anweisen, einen Regelwerk-Verweis in den
 * Namen zu schreiben (demo Lauf 81: „Püree: Petersilienwurzel nach Basisrezept-Regelwerk §1").
 */
it('recipe.komponenten_plan verlangt keinen Regelwerk-Verweis im Komponenten-Namen', function () {
    $task = (string) (config('foodalchemist.prompts', [])['recipe.komponenten_plan']['task'] ?? '');

    expect($task)->not->toBe('')
        ->and($task)->toContain('name: "<Typ>: <Bezeichnung>"')   // der Platzhalter endet mit der Bezeichnung
        ->and($task)->toContain('Typ-Vokabular')
        ->and($task)->not->toContain('nach Basisrezept-Regelwerk')
        ->and($task)->not->toContain('Regelwerk §1');
});

it('bereinigeName bleibt als Netz: ein trotzdem angehängter Regelwerk-Verweis wird weiter abgeschnitten', function () {
    expect(RecipeKomponentenPlanService::bereinigeName('Püree: Petersilienwurzel nach Basisrezept-Regelwerk §1'))
        ->toBe('Püree: Petersilienwurzel');
});

it('recipe.description führt keine eigene Satzzahl neben der Regel basisrezept.8.3.saetze', function () {
    $task = (string) (config('foodalchemist.prompts', [])['recipe.description']['task'] ?? '');

    expect($task)->not->toBe('')
        ->and($task)->not->toMatch('/\d+\s*[-–]\s*\d+\s*S(ä|ae)tze/u')
        ->and($task)->toContain('Satzzahl laut dem mitgegebenen Regelwerk')
        ->and($task)->toContain('werte = {description}');
});

it('recipe.name_putzen und recipe.titel_vorschlag verweisen auf das mitgegebene Typ-Vokabular, nicht auf einen §1.2-Dossier-Abschnitt', function () {
    foreach (['recipe.name_putzen', 'recipe.titel_vorschlag'] as $key) {
        $task = (string) (config('foodalchemist.prompts', [])[$key]['task'] ?? '');

        expect($task)->not->toBe('')
            ->and($task)->toContain('§1-Syntax')                    // der Syntax-Name bleibt (Regel-Paragraf, kein Dossier-Text)
            ->and($task)->toContain('mitgegebenen Typ-Vokabular')
            ->and($task)->not->toContain('§1.2-Vokabular');
    }
});
