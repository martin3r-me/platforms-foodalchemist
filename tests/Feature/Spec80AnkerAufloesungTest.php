<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Services\TerminologyService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 80 A5 — Anker für Grundbegriffe und Komposita, ohne Teilwort-Raten (Spec 60). demo Lauf #79:
 * „Petersilie" hatte keinen Anker (nur „Glatte"/„Krause Petersilie"), „Petersilienpüree" wurde nie zerlegt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->anker = function (string $slug, string $name): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
});

it('löst exakt, über kuratierte Synonyme und über zerlegte Komposita auf', function () {
    $glatt = ($this->anker)('glatte_petersilie', 'Glatte Petersilie');
    $kuerbis = ($this->anker)('kuerbis', 'Kürbis');
    $wurzel = ($this->anker)('petersilienwurzel', 'Petersilienwurzel');
    $p = app(PairingService::class);

    expect($p->ankerAufgeloest('Petersilienwurzel'))->toBe(['id' => $wurzel, 'via' => 'exakt'])
        ->and($p->ankerAufgeloest('Petersilie'))->toBeNull()                 // ohne Synonym: ehrlich keine
        ->and($p->ankerAufgeloest('Kürbispüree'))->toBe(['id' => $kuerbis, 'via' => 'zerlegt']);

    app(TerminologyService::class)->createAlias(['petersilie', 'glatte petersilie'], 'Spec 80 Test');

    expect(app(PairingService::class)->ankerAufgeloest('Petersilie'))->toBe(['id' => $glatt, 'via' => 'synonym'])
        ->and(app(PairingService::class)->ankerAufgeloest('Petersilienpüree'))->toBe(['id' => $glatt, 'via' => 'zerlegt']);
});

it('rät keine Teilwörter: ohne bekannten Kopf kein Anker', function () {
    ($this->anker)('mais', 'Mais');

    expect(app(PairingService::class)->ankerAufgeloest('Maispoularde'))->toBeNull()
        ->and(app(PairingService::class)->ankerAufgeloest(''))->toBeNull();
});
