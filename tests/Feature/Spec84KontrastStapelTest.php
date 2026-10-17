<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Pairing\KontrastAbleitung;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 E-5 · Kontrast-Kanten werden in Stapeln von 1 000 geschrieben statt alle im RAM gesammelt. Der Test erzeugt
 * mehr als einen Stapel (30 Bedarfsträger × 50 Lieferanten = 1 500 Kanten) und prüft Zahl, Inhalt und Wiederholbarkeit.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $anker = function (string $slug): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert(['uuid' => (string) UuidV7::generate(), 'slug' => $slug,
            'display_de' => $slug, 'grundname' => $slug, 'aroma_intensitaet' => 1.0, 'created_at' => now(), 'updated_at' => now()]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $w = fn (string $t, array $z) => DB::table($t)->insert($z + ['status' => 'entwurf', 'created_at' => now(), 'updated_at' => now()]);
    for ($k = 0; $k < 30; $k++) {
        $w('foodalchemist_anchor_bedarfe', ['anchor_id' => $anker("traeger-{$k}"), 'achse' => 'saeure', 'staerke' => $k % 2 ? 'muss' : 'soll']);
    }
    for ($k = 0; $k < 60; $k++) {
        $w('foodalchemist_anchor_eigenschaften', ['anchor_id' => $anker("saeure-{$k}"), 'achse' => 'saeure', 'stufe' => 2 + $k % 2, 'quelle' => 'dossier']);
    }
});

it('Spec 84 E-5 · mehr als ein Stapel: alle Kanten geschrieben, Zahl stimmt, zweiter Lauf ersetzt statt verdoppelt', function () {
    $zaehle = fn () => DB::table('foodalchemist_anchor_beziehungen')->where('art', 'kontrast')->where('grundlage', 'bedarf_x_eigenschaft');

    $r1 = app(KontrastAbleitung::class)->baue();
    $stand1 = $zaehle()->orderBy('anchor_a_id')->orderBy('anchor_b_id')->get(['anchor_a_id', 'anchor_b_id', 'achse', 'rang', 'status'])->toJson();
    $r2 = app(KontrastAbleitung::class)->baue();

    expect($r1['kanten'])->toBe(30 * KontrastAbleitung::MAX_JE_BEDARF)   // 1 500 > ein Stapel
        ->and($zaehle()->count())->toBe($r1['kanten'])
        ->and($r2['kanten'])->toBe($r1['kanten'])
        ->and($zaehle()->orderBy('anchor_a_id')->orderBy('anchor_b_id')->get(['anchor_a_id', 'anchor_b_id', 'achse', 'rang', 'status'])->toJson())->toBe($stand1);
});
