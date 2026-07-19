<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SurplusToDishService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R6.10 — Überschuss-zu-Gericht: Mock-Bestand [{gp_id, menge}] → Gerichte, die den
 * GP über den Aroma-Anker-Graph TRAGEN (nicht bloß enthalten) + verwertete Menge +
 * nicht verwertbare Überschüsse.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(SurplusToDishService::class);

    $mkAnker = function (string $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->erdig = $mkAnker('erdig');
    $this->exotisch = $mkAnker('exotisch');   // kein Gericht trägt ihn → nicht verwertbar

    $mkGp = function (string $name, int $ankerId) {
        $gp = $this->makeGp($this->rootTeam, $name);
        DB::table('foodalchemist_gp_anchor_mappings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'gp_id' => $gp->id, 'anchor_id' => $ankerId, 'role' => 'kern',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $gp;
    };
    $this->roteBete = $mkGp('Rote Bete', $this->erdig);
    $this->jackfrucht = $mkGp('Jackfrucht', $this->exotisch);

    $this->vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'rbsuppe', 'name' => 'Rote-Bete-Suppe',
        'status' => 'approved', 'is_sales_recipe' => true,
    ]);
    DB::table('foodalchemist_recipe_anchor_mappings')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->vk->id, 'anchor_id' => $this->erdig, 'role' => 'kern',
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('schlägt ein tragendes Gericht vor + weist die verwertete Menge/GP aus', function () {
    $out = $this->svc->suggest($this->rootTeam, [
        ['gp_id' => $this->roteBete->id, 'menge' => 12, 'einheit' => 'kg'],
    ], 8);

    $kand = collect($out['kandidaten'])->firstWhere('recipe_id', $this->vk->id);
    expect($kand)->not->toBeNull()
        ->and($kand['shared_anker'])->toBe(1)
        ->and(collect($kand['verwertet_gps'])->pluck('gp_id')->all())->toContain($this->roteBete->id)
        ->and(collect($kand['verwertet_gps'])->firstWhere('gp_id', $this->roteBete->id)['menge'])->toBe(12.0)
        ->and($kand['begruendung'])->toContain('tragend');
});

it('listet nicht verwertbare Überschüsse (kein Bestandsgericht trägt den Anker)', function () {
    $out = $this->svc->suggest($this->rootTeam, [
        ['gp_id' => $this->roteBete->id, 'menge' => 5],
        ['gp_id' => $this->jackfrucht->id, 'menge' => 3],
    ], 8);

    $nv = collect($out['nicht_verwertbar'])->pluck('gp_id')->all();
    expect($nv)->toContain($this->jackfrucht->id)
        ->and($nv)->not->toContain($this->roteBete->id);
});
