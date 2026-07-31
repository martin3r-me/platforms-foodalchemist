<?php

use Platform\FoodAlchemist\Services\RecipeStepService;

/**
 * Spec 27 §4 — der Zubereitungs-Parser ist DETERMINISTISCH und trägt drei Wege
 * (Bestands-Backfill, „Markdown einfügen" im Editor, Markdown-Eingang der
 * Schreibwege). Diese Golden-Fälle friert das Verhalten ein: ändert sich der
 * Parser, muss der Bestands-Backfill neu bewertet werden.
 *
 * Reine Unit-Tests, keine DB (parse/render sind pure Funktionen).
 */
beforeEach(function () {
    $this->svc = new RecipeStepService;
});

it('§4.1 Phasen aus ## + nummerierte Schritte', function () {
    $md = <<<'MD'
    ## Mise en Place
    1. Zwiebeln in Brunoise schneiden.
    2. Fond auf 80 °C bringen.

    ## Finish
    3. Mit Butter montieren, mit Salz abschmecken.
    MD;

    expect($this->svc->parse($md))->toBe([
        ['phase' => 'Mise en Place', 'text' => 'Zwiebeln in Brunoise schneiden.'],
        ['phase' => 'Mise en Place', 'text' => 'Fond auf 80 °C bringen.'],
        ['phase' => 'Finish', 'text' => 'Mit Butter montieren, mit Salz abschmecken.'],
    ]);
});

it('§4.1 Fließtext ohne Marker wird an den vorigen Schritt gehängt (konservativ)', function () {
    $md = "1. Fleisch scharf anbraten.\nDabei die Pfanne nicht überfüllen.\n2. Ablöschen.";

    expect($this->svc->parse($md))->toBe([
        ['phase' => null, 'text' => 'Fleisch scharf anbraten. Dabei die Pfanne nicht überfüllen.'],
        ['phase' => null, 'text' => 'Ablöschen.'],
    ]);
});

it('§4.1 Fließtext ohne vorigen Schritt wird ein eigener Schritt', function () {
    expect($this->svc->parse("Alles zusammenführen und aufkochen."))->toBe([
        ['phase' => null, 'text' => 'Alles zusammenführen und aufkochen.'],
    ]);
});

it('erkennt alle Schritt-Marker (1. / 1) / - / * / •) und keine leeren Schritte', function () {
    $md = "1. eins\n2) zwei\n- drei\n* vier\n• fünf\n\n\n";

    expect(array_column($this->svc->parse($md), 'text'))->toBe(['eins', 'zwei', 'drei', 'vier', 'fünf']);
});

it('komplett fette Zeile ist eine Phase, gemischt fetter Text nicht', function () {
    $md = "**Vorbereitung**\n1. Ofen vorheizen.\n2. **Wichtig:** nicht rühren.\n**A** und **B** mischen.";

    expect($this->svc->parse($md))->toBe([
        ['phase' => 'Vorbereitung', 'text' => 'Ofen vorheizen.'],
        // Der Inline-Fettdruck bleibt im Text (markdown-lite), wird nicht als Phase gelesen.
        ['phase' => 'Vorbereitung', 'text' => '**Wichtig:** nicht rühren. **A** und **B** mischen.'],
    ]);
});

it('säubert Überschriften (Nummer, Doppelpunkt, Auszeichnung)', function () {
    expect($this->svc->parse("### 2. Garen:\n1. schmoren")[0]['phase'])->toBe('Garen');
});

it('leerer/fehlender Text ergibt keine Schritte', function () {
    expect($this->svc->parse(null))->toBe([])
        ->and($this->svc->parse('   '))->toBe([])
        ->and($this->svc->parse("## Nur eine Phase\n\n"))->toBe([]);
});

it('render: Phasen als ## + fortlaufende Nummerierung über Phasen hinweg', function () {
    $steps = [
        ['phase' => 'Mise en Place', 'text' => 'Zwiebeln schneiden.'],
        ['phase' => 'Mise en Place', 'text' => 'Fond erhitzen.'],
        ['phase' => 'Finish', 'text' => 'Montieren.'],
    ];

    expect($this->svc->render($steps))->toBe(
        "## Mise en Place\n1. Zwiebeln schneiden.\n2. Fond erhitzen.\n\n## Finish\n3. Montieren."
    );
});

it('render: Schritte ohne Phase bekommen keine Überschrift', function () {
    expect($this->svc->render([['phase' => null, 'text' => 'eins'], ['phase' => '', 'text' => 'zwei']]))
        ->toBe("1. eins\n2. zwei");
});

it('Round-Trip: parse(render(steps)) === steps', function () {
    $steps = [
        ['phase' => null, 'text' => 'Alles bereitstellen.'],
        ['phase' => 'Garen', 'text' => 'Bei 160 °C 40 Minuten schmoren.'],
        ['phase' => 'Garen', 'text' => 'Zwischendurch **einmal** wenden.'],
        ['phase' => 'Finish', 'text' => 'Abschmecken und anrichten.'],
    ];

    expect($this->svc->parse($this->svc->render($steps)))->toBe($steps);
});
