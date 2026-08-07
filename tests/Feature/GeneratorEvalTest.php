<?php

use Platform\FoodAlchemist\Console\GeneratorEvalCommand;

/**
 * Phase 4 (Kohärenz-Gate): reine Auswertungslogik des Golden-Gerichte-Harness —
 * Fremdkörper-Rate (ohne/mit Gate) + False-Positive-Rate, provider- und DB-los
 * (wie das MatcherEval-Pendant). Der Live-Lauf gegen echten Gemini ist separat.
 */
beforeEach(function () {
    $this->cmd = new GeneratorEvalCommand();
});

it('misst Fremdkörper-Rate ohne/mit Gate und False-Positives korrekt', function () {
    $golden = [
        ['name' => 'Tomatensuppe', 'class' => 'risk', 'forbid' => ['rahmeis', 'sorbet']],
        ['name' => 'Gulasch',      'class' => 'risk', 'forbid' => ['parfait', 'dessert']],
        ['name' => 'Schokomousse', 'class' => 'clean'],
        ['name' => 'Rinderbraten', 'class' => 'clean'],
    ];
    $results = [
        // Rahmeis wurde vom Gate ENTDRAHTET → ohne Gate ein Fremdkörper, mit Gate keiner.
        'Tomatensuppe' => ['wired' => ['Tomaten: frisch', 'Zwiebel'], 'entdrahtet' => ['Rahmeis: Balsamico']],
        // Parfait blieb VERDRAHTET (Gate verpasst) → ohne UND mit Gate ein Fremdkörper.
        'Gulasch'      => ['wired' => ['Rindfleisch', 'Parfait: Vanille'], 'entdrahtet' => []],
        // Dessert (clean): nichts entdrahtet → kein False Positive.
        'Schokomousse' => ['wired' => ['Schokolade', 'Sahne'], 'entdrahtet' => []],
        // Legitime Komponente fälschlich entdrahtet → False Positive.
        'Rinderbraten' => ['wired' => ['Rindfleisch'], 'entdrahtet' => ['Sauce: Rotwein']],
    ];

    $r = $this->cmd->evaluate($golden, $results);

    expect($r['n_risk'])->toBe(2)
        ->and($r['n_clean'])->toBe(2)
        ->and($r['fremdkoerper_ohne_gate'])->toBe(2)            // Tomatensuppe(Rahmeis) + Gulasch(Parfait)
        ->and($r['fremdkoerper_mit_gate'])->toBe(1)             // nur Gulasch — Parfait blieb wired
        ->and($r['rate_ohne_gate'])->toBe(1.0)
        ->and($r['rate_mit_gate'])->toBe(0.5)
        ->and($r['fp_dishes'])->toBe(1)                         // Rinderbraten
        ->and($r['fp_rate'])->toBe(0.5);
});

it('Ideal-Lauf: Gate fängt alles, kein False Positive → beide Zielraten 0', function () {
    $golden = [
        ['name' => 'Tomatensuppe', 'class' => 'risk', 'forbid' => ['rahmeis']],
        ['name' => 'Schokomousse', 'class' => 'clean'],
    ];
    $results = [
        'Tomatensuppe' => ['wired' => ['Tomaten'], 'entdrahtet' => ['Rahmeis: Balsamico']],
        'Schokomousse' => ['wired' => ['Schokolade', 'Sahne'], 'entdrahtet' => []],
    ];

    $r = $this->cmd->evaluate($golden, $results);

    expect($r['rate_ohne_gate'])->toBe(1.0)                     // ohne Gate wäre der Rahmeis drin
        ->and($r['rate_mit_gate'])->toBe(0.0)                   // mit Gate: sauber
        ->and($r['fp_rate'])->toBe(0.0);
});

it('anyForbidden ist Substring + Umlaut-Fold, meidet aber Fehlmatches distinktiver Muster', function () {
    expect($this->cmd->anyForbidden(['Rahmeis: Balsamico'], ['rahmeis']))->toBeTrue()
        ->and($this->cmd->anyForbidden(['Rindfleisch, frisch', 'Zwiebel'], ['rahmeis', 'sorbet']))->toBeFalse()
        ->and($this->cmd->anyForbidden(['Püree: Kürbis'], ['pueree']))->toBeTrue()   // ü→ue
        ->and($this->cmd->anyForbidden([], ['rahmeis']))->toBeFalse()
        ->and($this->cmd->anyForbidden(['Sorbet: Zitrone'], []))->toBeFalse();       // kein Muster ⇒ nie Treffer
});
