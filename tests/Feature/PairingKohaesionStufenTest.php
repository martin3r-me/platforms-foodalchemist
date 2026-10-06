<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Tests\Support\Harmonie;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 · P2: Kohäsion liest die Harmonie-Stufe ({@see \Platform\FoodAlchemist\Services\Pairing\AnkerGraph}).
 * Übergang bis P6: Stufe 3 → 1,0, Stufe 2 → 0,9, Stufe 1 (keine Kante) → unbewertet.
 * Löst den früheren Test der berechneten Molekül-Gewichte ab (Moleküle sind raus, P3).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PairingService::class);
    $this->mkAnker = function (string $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->komp = fn (int $id) => ['label' => "k{$id}", 'kern' => $id, 'prozess' => [], 'via' => 'test'];
});

it('Stufe 3 zählt 100', function () {
    $a = ($this->mkAnker)('erdbeere');
    $b = ($this->mkAnker)('minze');
    Harmonie::kante($a, $b, 3);

    $k = $this->svc->cohesionFor([($this->komp)($a), ($this->komp)($b)]);
    expect($k['score'])->toBe(100)->and($k['rated_pairs'])->toBe(1);
});

it('Stufe 2 zählt 90 (Übergang bis P6)', function () {
    $a = ($this->mkAnker)('spargel');
    $b = ($this->mkAnker)('erdbeere');
    Harmonie::kante($a, $b, 2);

    $k = $this->svc->cohesionFor([($this->komp)($a), ($this->komp)($b)]);
    expect($k['score'])->toBe(90);
});

it('Stufe 1 (keine Kante) bleibt unbewertet', function () {
    $a = ($this->mkAnker)('tomate');
    $b = ($this->mkAnker)('vanille');

    $k = $this->svc->cohesionFor([($this->komp)($a), ($this->komp)($b)]);
    expect($k['rated_pairs'])->toBe(0)->and($k['unrated_pairs'])->toHaveCount(1);
});
