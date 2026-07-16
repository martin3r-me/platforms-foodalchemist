<?php

use Platform\FoodAlchemist\Console\EmbedEvalCommand;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * E5 (#507): reine Auswertungslogik der Kalibrierungs-Harness — Floor-Sweep,
 * Recall@K je Fallklasse, Anti-Marker-Gegenprobe, Floor-Vorschlag. Provider-los
 * getestet (die echte Recall-Messung läuft online gegen den Embedder). Kern-Schutz:
 * der Token-Subset-Match darf „Brie" NICHT in „Bries" hineinlesen.
 */

it('nameMatches ist token-subset (Anti-Marker-Falschpositiv Brie↛Bries verhindert)', function () {
    $c = new EmbedEvalCommand();

    expect($c->nameMatches('Rindfleisch', 'Rindfleisch, frisch'))->toBeTrue()      // Subset
        ->and($c->nameMatches('Püree: Kürbis', 'Püree: Kürbis, TK'))->toBeTrue()   // Umlaut + Doppelpunkt
        ->and($c->nameMatches('Brie', 'Bries'))->toBeFalse()                       // KEIN Substring-Match
        ->and($c->nameMatches('Brie', 'Brie de Meaux'))->toBeTrue()                // echtes Token
        ->and($c->nameMatches('Triple Chocolate', 'Cookie Triple Sec'))->toBeFalse();
});

it('sweept Floors, misst Recall je Klasse und schlägt den floor-mit-0-Anti-Verletzung vor', function () {
    $golden = [
        ['query' => 'Beef',       'expect' => 'Rindfleisch', 'forbid' => null,  'relation' => 'translation', 'polarity' => 'positive'],
        ['query' => 'Möhre',      'expect' => 'Karotte',     'forbid' => null,  'relation' => 'synonym',     'polarity' => 'positive'],
        ['query' => 'Bries',      'expect' => null,          'forbid' => 'Brie','relation' => 'anti_marker', 'polarity' => 'negative'],
    ];
    // Treffer schon name-aufgelöst + Score-absteigend. Beef→Rindfleisch stark (0.80);
    // Möhre→Karotte schwach (0.42); Bries→Brie fälschlich nah (0.50) → muss durch den
    // Floor rausgehalten werden.
    $hits = [
        'Beef'  => [['name' => 'Rindfleisch, frisch', 'score' => 0.80], ['name' => 'Corned Beef', 'score' => 0.55]],
        'Möhre' => [['name' => 'Karotte: frisch', 'score' => 0.42]],
        'Bries' => [['name' => 'Brie de Meaux', 'score' => 0.50], ['name' => 'Kalbsbries', 'score' => 0.30]],
    ];

    $rep = (new EmbedEvalCommand())->evaluate($golden, $hits, [0.40, 0.55, 0.70], 15);

    // Bei 0.40: beide Positive treffen (Recall 100%) ABER Bries→Brie (0.50≥0.40) = 1 Verletzung.
    $f040 = collect($rep['per_floor'])->firstWhere('floor', 0.40);
    expect($f040['recall_overall'])->toBe(1.0)->and($f040['anti_violations'])->toBe(1);

    // Bei 0.55: Brie (0.50) fällt raus → 0 Verletzungen; Möhre (0.42) fällt auch raus → Recall 50%.
    $f055 = collect($rep['per_floor'])->firstWhere('floor', 0.55);
    expect($f055['anti_violations'])->toBe(0)
        ->and($f055['recall_overall'])->toBe(0.5)
        ->and($f055['recall_by_relation']['translation'])->toBe(1.0)
        ->and($f055['recall_by_relation']['synonym'])->toBe(0.0);

    // Vorschlag = niedrigster sauberer Floor mit max Recall → 0.55 (0.70 wäre auch sauber, aber schlechterer Recall).
    expect($rep['suggested_floor'])->toBe(0.55)
        ->and($rep['n_positive'])->toBe(2)->and($rep['n_negative'])->toBe(1);
});

it('schlägt keinen Floor vor, wenn keiner ohne Anti-Verletzung auskommt', function () {
    $golden = [
        ['query' => 'Bries', 'expect' => null, 'forbid' => 'Brie', 'relation' => 'anti_marker', 'polarity' => 'negative'],
    ];
    $hits = ['Bries' => [['name' => 'Brie', 'score' => 0.95]]];   // untrennbar nah in jedem Floor

    $rep = (new EmbedEvalCommand())->evaluate($golden, $hits, [0.40, 0.90], 15);
    expect($rep['suggested_floor'])->toBeNull();
});
