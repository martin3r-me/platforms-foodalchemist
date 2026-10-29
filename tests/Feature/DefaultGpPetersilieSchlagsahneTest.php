<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * §5 Default-GP (Entscheid Dominique 29.10.): „Petersilie“ ohne Angabe → ganz, frisch (auch „1 Bund Petersilie“); „Schlagsahne“ trifft ihr eigenes GP,
 * nicht den Sahne-Default (30 %). Grundlage ist die Regel-Migration 2026_11_21_100000.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->gp = fn (string $name, string $slug, ?string $zustand, string $wg) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 'dg-' . bin2hex(random_bytes(4)) . '||', 'status' => 'approved', 'requires_la' => true,
        'commodity_group_code' => $wg, 'condition' => $zustand, 'main_ingredient_slug' => $slug]);
    $this->auto = function (string $t) {
        $r = app(IngredientMatchService::class)->matchIngredient($this->rootTeam, $t, null, 'gp_first', 'fresh_first', false, 'conventional');

        return IngredientMatchService::istAutomatischVerdrahtbar($r) ? ($r['gp_id'] ?? null) : null;
    };
});

it('Petersilie ohne Angabe → glatt, ganz, frisch — gehackt nur, wenn der Text es sagt', function () {
    $ganz = ($this->gp)('Petersilie glatt: frisch, ganz', 'petersilie_glatt', 'frisch', '03');
    $gehackt = ($this->gp)('Petersilie glatt: frisch, gehackt', 'petersilie_glatt', 'frisch', '03');

    expect(($this->auto)('Petersilie'))->toBe($ganz->id)
        ->and(($this->auto)('1 Bund Petersilie'))->toBe($ganz->id)
        ->and(($this->auto)('Petersilie gehackt TK'))->not->toBe($ganz->id);   // genannte Form schlägt den Default
});

it('Schlagsahne → eigenes GP, nicht der Sahne-Default (30 %)', function () {
    $sahne = ($this->gp)('Sahne: konserviert, 30 % Fett', 'sahne', 'konserviert', '06');
    $schlag = ($this->gp)('Schlagsahne: konserviert, 35 % Fett', 'schlagsahne', 'konserviert', '06');

    expect(($this->auto)('Schlagsahne'))->toBe($schlag->id)
        ->and(($this->auto)('Sahne'))->toBe($sahne->id);
});
