<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * DER WASSER-ALIAS ZEIGTE AUF EINEN NAMEN, DEN ES NICHT GIBT.
 *
 * `MatchHeuristics::defaultGpAlias()` leitet bares »Wasser«/»Leitungswasser« seit dem
 * 2026-08-18 auf das Leitungswasser-GP — aber unter dem Namen »Wasser: Leitung«. So heisst
 * es nicht: das GP ist »Leitungswasser: frisch« (id 9359 auf demo, approved, über einen
 * Nullpreis-Artikel mit 0,00 €/kg korrekt modelliert). `resolveGpByName` lief also ins
 * Leere, der Alias blieb wirkungslos, und »Wasser« landete weiter auf
 * «Wasser: still, 0,5 l, Bio» — 64 Rezepte rechnen Leitungswasser als Bio-Flaschenwasser.
 *
 * Der eigene Code-Kommentar sagte „inert, solange das GP nicht existiert" — und hat damit
 * die falsche Ursache festgeschrieben. Das GP existierte; der Name war falsch.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->matcher = app(IngredientMatchService::class);

    $mk = fn (string $name, string $key) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'gp_key' => $key, 'name' => $name,
        'status' => 'approved', 'is_platzhalter' => false, 'main_ingredient_slug' => 'wasser',
    ]);
    // So heisst es wirklich …
    $this->leitung = $mk('Leitungswasser: frisch', 'leitungswasser_frisch');
    // … und das ist die gekaufte Ware, auf die »Wasser« fälschlich fiel.
    $this->flasche = $mk('Wasser: still, 0,5 l, Bio', 'wasser_still_bio');
});

it('bares »Wasser« landet auf dem Leitungswasser, nicht auf der Flasche', function () {
    $m = $this->matcher->matchIngredient($this->rootTeam, 'Wasser', null, 'hybrid');

    expect($m['target'])->toBe('gp')
        ->and($m['gp_id'])->toBe($this->leitung->id);
});

it('»Leitungswasser« ebenso', function () {
    $m = $this->matcher->matchIngredient($this->rootTeam, 'Leitungswasser', null, 'hybrid');

    expect($m['gp_id'])->toBe($this->leitung->id);
});

it('der Alias-Name muss ein GP treffen können — sonst ist er stumm wirkungslos', function () {
    // Der eigentliche Riegel: der im Code hinterlegte Name muss zu einem real existierenden
    // GP-Namen passen. Genau diese Lücke lief vom 18.08. bis 03.09. unbemerkt.
    $rc = new ReflectionClass(\Platform\FoodAlchemist\Services\Matching\MatchHeuristics::class);
    $m = $rc->getMethod('defaultGpAlias');
    $src = implode('', array_slice(file($m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));

    expect($src)->toContain("'Leitungswasser: frisch'")
        ->and($src)->not->toContain("'Wasser: Leitung'");
});
