<?php

use Platform\FoodAlchemist\Services\FavoriteGpService;
use Platform\FoodAlchemist\Services\GenerationContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 06·H3/H4b — opt-in Favoriten-Modus in der Grounding-Kaskade: Default aus =
 * kein Favoriten-Block (Regression), an = separater Block, getrennt vom Reuse.
 * H4b: optionaler Convenience-nur-Filter (Favoriten ∩ tag_is_convenience).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $gp = $this->makeGp($this->rootTeam, 'TK-Blätterteig');
    $gp->update(['status' => 'approved', 'tag_is_convenience' => true]);
    app(FavoriteGpService::class)->pin($gp, 1);

    $this->ctx = app(GenerationContextService::class);
});

it('Default (aus) liefert KEINEN favorites-Block', function () {
    $out = $this->ctx->forGeneration($this->rootTeam, 'Blätterteig-Tarte mit Gemüse');
    expect($out)->not->toHaveKey('favorites');
});

it('opt-in (an) liefert den separaten Highlight-Block', function () {
    $out = $this->ctx->forGeneration($this->rootTeam, 'Blätterteig-Tarte mit Gemüse', false, true);
    expect($out)->toHaveKey('favorites');
    expect($out['favorites']['treffer'][0]['name'])->toBe('TK-Blätterteig');
    // getrennt vom Reuse-Block
    expect($out['favorites'])->not->toBe($out['gp_kandidaten'] ?? null);
});

it('opt-in spielt die Liste auch ohne Leit-Tokens ein', function () {
    $out = $this->ctx->forGeneration($this->rootTeam, 'xy', false, true); // keine Leit-Tokens (<4 Zeichen)
    expect($out)->toHaveKey('favorites');
});

it('opt-in ohne gepinnte Favoriten → kein Block', function () {
    app(FavoriteGpService::class)->exclude(
        \Platform\FoodAlchemist\Models\FoodAlchemistGp::where('name', 'TK-Blätterteig')->first()
    );
    $out = $this->ctx->forGeneration($this->rootTeam, 'Blätterteig-Tarte', false, true);
    expect($out)->not->toHaveKey('favorites');
});

it('H4b: convenienceOnly verengt den Favoriten-Block auf Convenience-getaggte GPs', function () {
    // zusätzlich ein nicht-Convenience-Favorit
    $roh = $this->makeGp($this->rootTeam, 'Frische Möhre');
    $roh->update(['status' => 'approved', 'tag_is_convenience' => false]);
    app(FavoriteGpService::class)->pin($roh, 2);

    // ohne Filter: beide Favoriten im Block
    $alle = $this->ctx->forGeneration($this->rootTeam, 'xy', false, true, false);
    $namenAlle = collect($alle['favorites']['treffer'])->pluck('name');
    expect($namenAlle)->toContain('TK-Blätterteig')->toContain('Frische Möhre');

    // convenienceOnly: nur der Convenience-getaggte Favorit
    $nurConv = $this->ctx->forGeneration($this->rootTeam, 'xy', false, true, true);
    $namenConv = collect($nurConv['favorites']['treffer'])->pluck('name');
    expect($namenConv)->toContain('TK-Blätterteig')->not->toContain('Frische Möhre');
});
