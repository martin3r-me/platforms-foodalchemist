<?php

use Platform\FoodAlchemist\Console\MatcherEvalCommand;

/**
 * #507 Weg-2: reine Auswertungslogik des Matcher-Eval-Harness (Recall je Klasse +
 * Anti-Marker-Leaks), provider- und DB-los — wie beim EmbedEval-Pendant.
 */
beforeEach(function () {
    $this->cmd = new MatcherEvalCommand();
});

it('misst Recall je Klasse und Anti-Marker-Leaks korrekt', function () {
    $golden = [
        ['query' => 'Paradeiser', 'expect' => 'Tomate',  'forbid' => null,    'relation' => 'regional', 'polarity' => 'positive'],
        ['query' => 'Erdapfel',   'expect' => 'Kartoffel','forbid' => null,    'relation' => 'regional', 'polarity' => 'positive'],
        ['query' => 'Beef',       'expect' => 'Rindfleisch','forbid' => null,  'relation' => 'translation', 'polarity' => 'positive'],
        ['query' => 'Brie',       'expect' => null,       'forbid' => 'Bries', 'relation' => 'anti_marker', 'polarity' => 'negative'],
        ['query' => 'Cointreau',  'expect' => null,       'forbid' => 'Orange','relation' => 'anti_marker', 'polarity' => 'negative'],
    ];

    // Paradeiser trifft, Erdapfel verfehlt, Beef trifft; Brie leakt Bries, Cointreau sauber.
    $names = [
        'Paradeiser' => ['Tomate', 'Tomatenmark'],
        'Erdapfel'   => ['Topinambur'],
        'Beef'       => ['Rindfleisch, frisch'],
        'Brie'       => ['Bries', 'Brie'],     // Leak: Bries in der Shortlist
        'Cointreau'  => ['Cointreau'],         // sauber
    ];

    $r = $this->cmd->evaluate($golden, $names);

    expect($r['recall_overall'])->toBe(2 / 3)                 // 2 von 3 Positiven
        ->and($r['by_relation']['regional'])->toBe(0.5)       // Paradeiser ja, Erdapfel nein
        ->and($r['by_relation']['translation'])->toBe(1.0)    // Beef ja
        ->and($r['anti_leaks'])->toBe(1)                      // Brie→Bries geleakt
        ->and($r['n_negative'])->toBe(2);
});

it('nameMatches nutzt Token-Subset, kein Substring (Brie ⊄ Bries)', function () {
    expect($this->cmd->nameMatches('Brie', 'Bries'))->toBeFalse();
    expect($this->cmd->nameMatches('Tomate', 'Tomate, frisch'))->toBeTrue();
    expect($this->cmd->nameMatches('Rote Bete', 'Rote Bete gehobelt'))->toBeTrue();
});
