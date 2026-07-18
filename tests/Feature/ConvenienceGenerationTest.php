<?php

use Platform\FoodAlchemist\Services\ConvenienceHighlightService;
use Platform\FoodAlchemist\Services\GenerationContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 06·H3 — opt-in Convenience-Modus in der Grounding-Kaskade: Default aus =
 * kein Highlight-Block (Regression), an = separater Block, getrennt vom Reuse.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $gp = $this->makeGp($this->rootTeam, 'TK-Blätterteig');
    $gp->update(['status' => 'approved', 'tag_is_convenience' => true]);
    app(ConvenienceHighlightService::class)->pin($gp, 1);

    $this->ctx = app(GenerationContextService::class);
});

it('Default (aus) liefert KEINEN convenience_highlights-Block', function () {
    $out = $this->ctx->forGeneration($this->rootTeam, 'Blätterteig-Tarte mit Gemüse');
    expect($out)->not->toHaveKey('convenience_highlights');
});

it('opt-in (an) liefert den separaten Highlight-Block', function () {
    $out = $this->ctx->forGeneration($this->rootTeam, 'Blätterteig-Tarte mit Gemüse', false, true);
    expect($out)->toHaveKey('convenience_highlights');
    expect($out['convenience_highlights']['treffer'][0]['name'])->toBe('TK-Blätterteig');
    // getrennt vom Reuse-Block
    expect($out['convenience_highlights'])->not->toBe($out['gp_kandidaten'] ?? null);
});

it('opt-in spielt die Liste auch ohne Leit-Tokens ein', function () {
    $out = $this->ctx->forGeneration($this->rootTeam, 'xy', false, true); // keine Leit-Tokens (<4 Zeichen)
    expect($out)->toHaveKey('convenience_highlights');
});

it('opt-in ohne gepinnte Highlights → kein Block', function () {
    app(ConvenienceHighlightService::class)->exclude(
        \Platform\FoodAlchemist\Models\FoodAlchemistGp::where('name', 'TK-Blätterteig')->first()
    );
    $out = $this->ctx->forGeneration($this->rootTeam, 'Blätterteig-Tarte', false, true);
    expect($out)->not->toHaveKey('convenience_highlights');
});
