<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\PairingProjectionService;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Station 2: gradiertes computed-Gewicht (weight-Spalte) gewinnt in edgeBest()/
 * cohesionFor() über das typ-getriebene GEWICHTE, kuratiert (weight NULL) bleibt
 * typ-getrieben. Bulk-Projektion selbst = MySQL-Smoke (UUID/TEMPORARY/GROUP_CONCAT).
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
    $this->mkKante = function (int $a, int $b, string $typ, ?float $weight = null) {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            DB::table('foodalchemist_pairing_anchor_edges')->insert([
                'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
                'type' => $typ, 'weight' => $weight, 'source_slug' => $weight !== null ? 'computed' : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    };
    $this->komp = fn (int $id) => ['label' => "k{$id}", 'kern' => $id, 'prozess' => [], 'via' => 'test'];
});

it('kuratiert (weight NULL) bleibt typ-getrieben — erprobt = 100', function () {
    $a = ($this->mkAnker)('erdbeere');
    $b = ($this->mkAnker)('minze');
    ($this->mkKante)($a, $b, 'erprobt');           // GEWICHTE['erprobt'] = 1.0

    $k = $this->svc->cohesionFor([($this->komp)($a), ($this->komp)($b)]);
    expect($k['score'])->toBe(100)->and($k['rated_pairs'])->toBe(1);
});

it('computed weight gewinnt über GEWICHTE — aroma mit weight 0.42 = 42, nicht 90', function () {
    $a = ($this->mkAnker)('spargel');
    $b = ($this->mkAnker)('erdbeere');
    ($this->mkKante)($a, $b, 'aroma', 0.42);        // ohne Override wäre GEWICHTE['aroma'] = 0.9

    $k = $this->svc->cohesionFor([($this->komp)($a), ($this->komp)($b)]);
    expect($k['score'])->toBe(42);
});

it('kuratiert gewinnt bei Kollision — stärkere kuratierte Kante schlägt schwache computed', function () {
    $a = ($this->mkAnker)('tomate');
    $b = ($this->mkAnker)('basilikum');
    ($this->mkKante)($a, $b, 'erprobt');            // 1.0 kuratiert
    ($this->mkKante)($a, $b, 'aroma', 0.42);        // schwache computed daneben

    // edgeBest nimmt das MAX → kuratiert 1.0 gewinnt, computed verzerrt nicht nach unten.
    $k = $this->svc->cohesionFor([($this->komp)($a), ($this->komp)($b)]);
    expect($k['score'])->toBe(100);
});

it('Projektions-Service verlangt MySQL (Guard greift auf SQLite)', function () {
    expect(fn () => app(PairingProjectionService::class)->project(false, 0.0, 1, 0.6))
        ->toThrow(RuntimeException::class);
});
