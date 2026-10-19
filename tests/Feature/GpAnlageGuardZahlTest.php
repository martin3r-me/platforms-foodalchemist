<?php

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Anlage-Guard (Kurator 19.10.2026): Der Tokenizer ignoriert Zahlen, deshalb galt „Scheiben 3 mm“ als Dublette von „Scheiben 2 mm“.
 * Abweichende Zahl (mm, g, %, Kaliber) = andere Variante; gleiche Zahl bleibt Dublette.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->team = Team::findOrFail($this->rootTeam->id);
    FoodAlchemistGp::create([
        'team_id' => $this->team->id, 'gp_key' => 'moehren|scheiben_2_mm|', 'name' => 'Moehren: frisch, Scheiben 2 mm', 'status' => 'approved',
        'commodity_group_code' => '01', 'condition' => 'frisch', 'main_ingredient_slug' => 'moehren',
    ]);
});

it('abweichende Zahl blockiert den Guard nicht (3 mm gegen 2 mm)', function () {
    $g = app(GpNamingService::class)->anlageGuard($this->team, 'moehren|scheiben_3_mm|', 'Moehren: frisch, Scheiben 3 mm');

    expect($g['blockiert'])->toBeFalse();
});

it('gleiche Zahl bleibt eine Dublette (jaccard)', function () {
    $g = app(GpNamingService::class)->anlageGuard($this->team, 'andere_schreibweise', 'Moehren: frisch, Scheiben, 2 mm');

    expect($g['blockiert'])->toBeTrue()->and($g['grund'])->toBe('jaccard');
});
