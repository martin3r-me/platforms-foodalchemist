<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * E7-a (#507 Weg-2): S1-Alias + S3-Decompound wirken jetzt auch in der
 * matchIngredient-ENTSCHEIDUNG (vorher nur in candidatesFor/Shortlist). „Paradeiser"
 * gewinnt jetzt im Urteil auf „Tomate". Rein additiv (nur unter der Schwelle) → die
 * GL-04-Goldens bleiben grün (volle Suite läuft mit).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->matcher = app(IngredientMatchService::class);
    $this->mkGp = fn (string $name) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id,
        'gp_key' => 'alias|' . mb_strtolower(str_replace([' ', ','], ['-', ''], $name)),
        'name' => $name, 'status' => 'approved', 'is_platzhalter' => false,
    ]);
});

it('AT-Dialekt in der Entscheidung: Paradeiser → GP Tomate', function () {
    $tomate = ($this->mkGp)('Tomate, frisch');

    $m = $this->matcher->matchIngredient($this->rootTeam, 'Paradeiser');

    expect($m['target'])->toBe('gp')
        ->and($m['gp_id'])->toBe($tomate->id);
});

it('EN→DE-Übersetzung in der Entscheidung: Beef → GP Rindfleisch', function () {
    $rind = ($this->mkGp)('Rindfleisch, frisch');

    $m = $this->matcher->matchIngredient($this->rootTeam, 'Beef');

    expect($m['gp_id'])->toBe($rind->id);
});

it('kein Alias, kein Treffer ⇒ ehrlich unmatched (additiv, keine Falsch-Auflösung)', function () {
    ($this->mkGp)('Tomate, frisch');

    $m = $this->matcher->matchIngredient($this->rootTeam, 'Drachenfrucht');

    expect($m['target'])->toBe('none');
});
