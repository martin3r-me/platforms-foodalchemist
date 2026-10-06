<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Pairing\AnkerGraph;
use Platform\FoodAlchemist\Tests\Support\Harmonie;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 · P2: AnkerGraph ist die einzige Lesestelle für die Harmonie. Vertrag:
 * Stufe 3/2 gespeichert, fehlendes Paar = Stufe 1, symmetrisch, stärkste Partner zuerst.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $mk = function (string $slug): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->kuerbis = $mk('kuerbis');
    $this->salbei = $mk('salbei');
    $this->apfel = $mk('apfel');
    $this->essig = $mk('reisessig');
    Harmonie::kante($this->kuerbis, $this->salbei, 3);
    Harmonie::kante($this->kuerbis, $this->apfel, 2);
    $this->g = app(AnkerGraph::class);
});

it('liefert die gespeicherte Stufe in beiden Richtungen, sonst Stufe 1', function () {
    expect($this->g->stufe($this->kuerbis, $this->salbei))->toBe(3)
        ->and($this->g->stufe($this->salbei, $this->kuerbis))->toBe(3)
        ->and($this->g->stufe($this->apfel, $this->kuerbis))->toBe(2)
        ->and($this->g->stufe($this->kuerbis, $this->essig))->toBe(1)          // gemessen „kein nennenswerter Bezug"
        ->and($this->g->stufe($this->essig, $this->essig))->toBe(3);
});

it('Partner: stärkste zuerst, Mindeststufe greift', function () {
    expect($this->g->partner($this->kuerbis)->pluck('slug')->all())->toBe(['salbei', 'apfel'])
        ->and($this->g->partner($this->kuerbis, AnkerGraph::HARMONIERT)->pluck('slug')->all())->toBe(['salbei']);
});

it('Stufen im Set, Kanten mit Ausschluss und Grad', function () {
    $ids = [$this->kuerbis, $this->salbei, $this->apfel];
    $stufen = $this->g->stufen($ids);
    expect($stufen[$this->salbei])->toBe([$this->kuerbis => 3])
        ->and($stufen[$this->apfel])->toBe([$this->kuerbis => 2])
        ->and($stufen[$this->kuerbis])->toEqualCanonicalizing([$this->salbei => 3, $this->apfel => 2])
        ->and($this->g->kanten([$this->kuerbis], null, 2, [$this->apfel])->pluck('zu')->all())->toBe([$this->salbei])
        ->and($this->g->grad([$this->kuerbis, $this->essig]))->toBe([$this->kuerbis => 2]);
});

it('Stufe 1 setzen entfernt das Paar in beiden Richtungen', function () {
    $this->g->setze($this->kuerbis, $this->apfel, 1);
    expect($this->g->stufe($this->kuerbis, $this->apfel))->toBe(1)
        ->and(DB::table(AnkerGraph::TABELLE)->count())->toBe(2);
});
