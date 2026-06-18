<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Manuelle Pairings: setRecipePairing schreibt nach recipe_pairings mit
 * created_via='manual'; removeRecipePairing soft-deletet; Re-Add nach Löschen
 * funktioniert trotz Unique-Index (recipe_id, anker_id, typ).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->mkAnker = function (string $slug): int {
        DB::table('foodalchemist_vocab_pairing_ankers')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
});

it('setzt + löst ein manuelles Pairing, Re-Add nach Löschen geht', function () {
    $erd = ($this->mkAnker)('erdbeere');
    $r = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'mp', 'name' => 'Test: Manuelles Pairing', 'status' => 'draft',
    ]);
    $svc = app(PairingService::class);

    $svc->setRecipePairing($this->rootTeam, $r->id, $erd, 'kontrast');
    $p = $svc->recipePairings($r->id);
    expect($p)->toHaveCount(1)
        ->and($p->first()->slug)->toBe('erdbeere')
        ->and($p->first()->typ)->toBe('kontrast')
        ->and($p->first()->created_via)->toBe('manual');

    $svc->removeRecipePairing($this->rootTeam, $r->id, $erd, 'kontrast');
    expect($svc->recipePairings($r->id))->toHaveCount(0);

    $svc->setRecipePairing($this->rootTeam, $r->id, $erd, 'kontrast');   // Re-Add trotz Soft-Delete
    expect($svc->recipePairings($r->id))->toHaveCount(1);
});
