<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * §2 VERARBEITUNGS-REDUKTION — die Schnittform machte den Treffer KAPUTT.
 *
 * Das Regelwerk Basisrezepte §2 verlangt, dass eine Schnittform auf die Rohform reduziert
 * wird: »Karotten, Brunoise« ist eine Karotte. Der Code tat das nicht — und die Schnittform
 * verschlechterte das Ergebnis sogar. Gemessen auf demo (2026-09-03):
 *
 *   »Karotten, Brunoise« → 1. «Gemuesemix: TK, Brunoises» 0,742
 *                          4. «Karotten: TK, Baby»        0,686  → Entscheid NONE
 *   »Karotten«           → 1. «Moehren / Karotten: …»     1,001  → Entscheid GP
 *
 * `brunoise` wurde wie ein Identitäts-Token gewertet und zog einen GEMÜSEMIX über die echte
 * Karotte. `isQualifierToken('brunoise')` war längst true — die Bewertung nutzte es nur nicht.
 * Dieselbe Lage bei »Zwiebeln gehackt«: beide fielen auf `none`, obwohl die Rohform sauber trifft.
 *
 * Anlass war Dominiques Rückfrage „ich dachte du hättest die Regelwerke an den Code
 * gebunden" — §5, §7 und §12 waren gebunden, §2 nie geprüft.
 *
 * Der Fallback folgt dem Hausmuster der beiden bestehenden (Alias-Phrasen, Decompound):
 * er feuert NUR unter der Mindestschwelle und kann darum keinen Golden-Test brechen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->matcher = app(IngredientMatchService::class);

    $mk = fn (string $name, string $slug) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'gp_key' => 'sf|' . mb_strtolower(str_replace([' ', ',', ':'], ['-', '', ''], $name)),
        'name' => $name, 'status' => 'approved', 'is_platzhalter' => false, 'main_ingredient_slug' => $slug,
    ]);
    // Die Konstellation von demo: ein Gemüsemix, der die Schnittform im NAMEN trägt, und
    // die echte Rohform. Ohne Reduktion gewinnt der Mix.
    $this->mix = $mk('Gemuesemix: TK, Brunoises', 'gemuesemix');
    $this->karotte = $mk('Karotten: frisch, ganz', 'karotten');
    $this->zwiebel = $mk('Zwiebeln: frisch, ganz', 'zwiebeln');
});

it('»Karotten, Brunoise« findet die Karotte — nicht den Gemüsemix', function () {
    $m = $this->matcher->matchIngredient($this->rootTeam, 'Karotten, Brunoise', null, 'hybrid');

    expect($m['target'])->toBe('gp')
        ->and($m['gp_id'])->toBe($this->karotte->id)
        ->and($m['gp_id'])->not->toBe($this->mix->id);
});

it('»Zwiebeln gehackt« findet die Zwiebel', function () {
    $m = $this->matcher->matchIngredient($this->rootTeam, 'Zwiebeln gehackt', null, 'hybrid');

    expect($m['target'])->toBe('gp')
        ->and($m['gp_id'])->toBe($this->zwiebel->id);
});

it('ohne Schnittform bleibt alles wie vorher — der Fallback darf nicht überschiessen', function () {
    $m = $this->matcher->matchIngredient($this->rootTeam, 'Karotten', null, 'hybrid');

    expect($m['gp_id'])->toBe($this->karotte->id);
});

it('ein GP, das die Schnittform WIRKLICH trägt, gewinnt weiter direkt', function () {
    // Wenn die Schnittform ein echtes Merkmal des Ziel-GPs ist, greift der Fallback nicht
    // (der erste Lauf liegt schon über der Schwelle) — sonst würde die Reduktion die
    // präzisere Variante wegwerfen.
    $wuerfel = FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'gp_key' => 'sf|sellerie-wuerfel',
        'name' => 'Sellerie: frisch, Wuerfel 5 mm', 'status' => 'approved',
        'is_platzhalter' => false, 'main_ingredient_slug' => 'sellerie',
    ]);

    $m = $this->matcher->matchIngredient($this->rootTeam, 'Sellerie, Würfel', null, 'hybrid');

    expect($m['gp_id'])->toBe($wuerfel->id);
});

it('isCutFormToken ist enger als isQualifierToken — Zustand bleibt eine eigene Achse', function () {
    $e = app(TokenEngine::class);

    // Schnittformen: ja
    expect($e->isCutFormToken('brunoise'))->toBeTrue()
        ->and($e->isCutFormToken('gehackt'))->toBeTrue()
        ->and($e->isCutFormToken('julienne'))->toBeTrue();

    // Zustand/Größe/Verarbeitungsgrad: NEIN — die dürfen bei der Reduktion nicht
    // verschwinden, sonst verlöre »Tomaten konserviert« seine Zustands-Achse.
    expect($e->isCutFormToken('frisch'))->toBeFalse()
        ->and($e->isCutFormToken('konserviert'))->toBeFalse()
        ->and($e->isCutFormToken('pulver'))->toBeFalse()
        ->and($e->isCutFormToken('karotten'))->toBeFalse();
    // …während isQualifierToken sie alle einschliesst:
    expect($e->isQualifierToken('frisch'))->toBeTrue()
        ->and($e->isQualifierToken('pulver'))->toBeTrue();
});
