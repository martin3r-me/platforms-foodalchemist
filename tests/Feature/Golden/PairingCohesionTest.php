<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M5-04/05: GL-10 Golden T1–T4 + T9 (synthetisch) + Invarianten (Caps, manual
 * gewinnt). T5–T8 sind Realdaten-Spotchecks gegen den Sandbox-Seed (Roadmap-
 * Notiz: T4/T5/T6/T8 exakt; T7-Formel fix, Werte = Datenstands-Drift 10.→11.06.).
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
    $this->mkKante = function (int $a, int $b, string $typ) {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {                 // Inv. 4: bidirektional
            DB::table('foodalchemist_pairing_anchor_edges')->insert([
                'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
                'type' => $typ, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    };
});

it('T1/T2: Slug-Toleranz — Präfix ja, Geschwister-Sorten und Teilstring nein', function () {
    expect($this->svc->ankerSlugMatches('apfel', 'aepfel_fuji'))->toBeTrue()
        ->and($this->svc->ankerSlugMatches('koriander_blatt', 'koriander'))->toBeTrue()
        ->and($this->svc->ankerSlugMatches('apfel', 'aepfel'))->toBeTrue()
        ->and($this->svc->ankerSlugMatches('apfel_braeburn', 'aepfel_granny_smith'))->toBeFalse()
        ->and($this->svc->ankerSlugMatches('rum', 'rumpsteak'))->toBeFalse();
});

it('T3: Identitäts-Anker GERICHTET — nie eine Sorte für den generischen GP', function () {
    $vokabular = ['apfel', 'apfel_braeburn', 'apfel_granny_smith'];

    expect($this->svc->bestIdentityAnchor('aepfel_braeburn', $vokabular))->toBe('apfel_braeburn')
        ->and($this->svc->bestIdentityAnchor('aepfel_fuji', $vokabular))->toBe('apfel')
        ->and($this->svc->bestIdentityAnchor('aepfel', $vokabular))->toBe('apfel');
});

it('T4: Kohäsion durchgerechnet — 83/50/100 %, fits 75/75/100, weakest kontrast', function () {
    $e = ($this->mkAnker)('erdbeere');
    $b = ($this->mkAnker)('basilikum');
    $bal = ($this->mkAnker)('balsamico');
    ($this->mkKante)($e, $b, 'kontrast');            // 0.5 (schwächstes Paar)
    ($this->mkKante)($e, $bal, 'erprobt');           // 1.0
    ($this->mkKante)($b, $bal, 'erprobt');           // 1.0

    $k = $this->svc->cohesionFor([
        ['label' => 'Erdbeere', 'kern' => $e, 'prozess' => [], 'via' => 'name_match'],
        ['label' => 'Basilikum', 'kern' => $b, 'prozess' => [], 'via' => 'name_match'],
        ['label' => 'Balsamico', 'kern' => $bal, 'prozess' => [], 'via' => 'name_match'],
    ]);

    expect($k['score'])->toBe(83)                    // (0.5+1.0+1.0)/3
        ->and($k['min_score'])->toBe(50)
        ->and($k['coverage_pct'])->toBe(100)
        ->and($k['rated_pairs'])->toBe(3)
        ->and(collect($k['komponenten'])->pluck('fit', 'label')->all())->toBe(['Erdbeere' => 75, 'Basilikum' => 75, 'Balsamico' => 100])
        ->and($k['weakest_pair']['type'])->toBe('kontrast')
        ->and($k['weakest_pair']['score'])->toBe(50)
        ->and(collect($k['komponenten'])->contains(fn ($c) => $c['is_orphan']))->toBeFalse();
});

it('T9: neutral zählt nie als Orphan; 0 bewertete Paare ⇒ score 0 und NIEMAND Orphan', function () {
    $a = ($this->mkAnker)('zimt');
    $b = ($this->mkAnker)('safran');                                  // keine Kante zwischen beiden

    $k = $this->svc->cohesionFor([
        ['label' => 'Zimt', 'kern' => $a, 'prozess' => [], 'via' => 'name_match'],
        ['label' => 'Safran', 'kern' => $b, 'prozess' => [], 'via' => 'name_match'],
        ['label' => 'Gelatine', 'kern' => null, 'prozess' => [], 'via' => 'neutral'],
    ]);

    expect($k['score'])->toBe(0)
        ->and($k['min_score'])->toBe(0)
        ->and($k['rated_pairs'])->toBe(0)
        ->and(collect($k['komponenten'])->contains(fn ($c) => $c['is_orphan']))->toBeFalse()  // any_rated=false
        ->and(count($k['unrated_pairs']))->toBe(1);                   // neutral fällt aus total_pairs
});

it('Inv. 1/3: Rezept-Cap 5 blockt, manual gewinnt (nullt KI-Lineage)', function () {
    $rezept = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'cap_test', 'name' => 'Cap: Test', 'status' => 'draft',
    ]);
    $ids = [];
    foreach (range(1, 6) as $i) {
        $ids[] = ($this->mkAnker)("anker_{$i}");
    }
    foreach (array_slice($ids, 0, 5) as $id) {
        $this->svc->setRecipeAnker($this->rootTeam, $rezept->id, $id);
    }
    expect(fn () => $this->svc->setRecipeAnker($this->rootTeam, $rezept->id, $ids[5]))
        ->toThrow(RuntimeException::class, 'max 5');

    // manual gewinnt: bestehender KI-Anker wird beim Set auf manual gehoben, Lineage genullt
    DB::table('foodalchemist_recipe_anchor_mappings')->where('recipe_id', $rezept->id)->where('anchor_id', $ids[0])
        ->update(['source' => 'ai_inferred', 'ai_confidence' => 0.7]);
    $this->svc->setRecipeAnker($this->rootTeam, $rezept->id, $ids[0]);  // Update zählt nicht gegen Cap
    $zeile = DB::table('foodalchemist_recipe_anchor_mappings')->where('recipe_id', $rezept->id)->where('anchor_id', $ids[0])->first();
    expect($zeile->source)->toBe('manual')->and($zeile->ai_confidence)->toBeNull();
});
