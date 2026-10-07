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
 * Ab P7c gilt EINE Regel: nur Stufe 3 (echtes Food Pairing) zählt (1,0). Zwei gemessene Inspire-Anker
 * ohne 3★ sind bewertet und neutral (0) — die Matrix ist vollständig gemessen. Unbewertet bleibt nur
 * ein Paar, bei dem ein Anker keine Inspire-ID hat (keine Messung).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PairingService::class);
    $this->inspire = 800;
    $this->mkAnker = function (string $slug, bool $gemessen = true) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'inspire_id' => $gemessen ? ++$this->inspire : null,
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

it('Stufe 2 zählt nicht: bewertet, neutral 0', function () {
    $a = ($this->mkAnker)('spargel');
    $b = ($this->mkAnker)('erdbeere');
    Harmonie::kante($a, $b, 2);

    $k = $this->svc->cohesionFor([($this->komp)($a), ($this->komp)($b)]);
    expect($k['score'])->toBe(0)->and($k['rated_pairs'])->toBe(1)
        ->and($k['weakest_pair']['type'])->toBe('neutral');
});

it('Stufe 1 (keine Kante) zwischen gemessenen Ankern: ebenfalls neutral 0', function () {
    $a = ($this->mkAnker)('tomate');
    $b = ($this->mkAnker)('vanille');

    $k = $this->svc->cohesionFor([($this->komp)($a), ($this->komp)($b)]);
    expect($k['score'])->toBe(0)->and($k['rated_pairs'])->toBe(1);
});

it('ohne Inspire-ID (keine Messung) bleibt das Paar unbewertet', function () {
    $a = ($this->mkAnker)('tomate', false);
    $b = ($this->mkAnker)('vanille');

    $k = $this->svc->cohesionFor([($this->komp)($a), ($this->komp)($b)]);
    expect($k['rated_pairs'])->toBe(0)->and($k['unrated_pairs'])->toHaveCount(1);
});
