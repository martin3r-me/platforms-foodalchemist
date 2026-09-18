<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Briefing Zutaten-Bulk-Import, Live-PREVIEW nach Deploy 21 (2026-09-18): "Aprikosen-Kompott
 * mit Ahornsirup und Anis" — Ahornsirup erdete sauber, aber `aprikosen` blieb `ohne_anker`, weil
 * ankerSlugExakt() nur exakte Gleichheit prüft und leitTokens() den Plural liefert (Anker-Label
 * ist "Aprikose"). Konservativer Ein-Stufen-Singular-Fallback: mehrere Endungen gleichzeitig
 * versuchen, nur bei GENAU EINEM Treffer akzeptieren.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->mkAnker = function (string $slug, string $displayDe) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => $displayDe,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->svc = app(PairingService::class);
});

it('loest den exakten Treffer weiterhin direkt auf (via: exakt)', function () {
    ($this->mkAnker)('acerola', 'Acerola');

    $res = $this->svc->ankerSlugExakt('acerola');

    expect($res)->toBe(['slug' => 'acerola', 'via' => 'exakt']);
});

it('aprikosen -> apricot ueber den Singular-Fallback (Endung "-n" weg -> "Aprikose")', function () {
    ($this->mkAnker)('apricot', 'Aprikose');

    $res = $this->svc->ankerSlugExakt('aprikosen');

    expect($res)->toBe(['slug' => 'apricot', 'via' => 'singular']);
});

it('tomaten -> tomato ueber den Singular-Fallback', function () {
    ($this->mkAnker)('tomato', 'Tomate');

    $res = $this->svc->ankerSlugExakt('tomaten');

    expect($res)->toBe(['slug' => 'tomato', 'via' => 'singular']);
});

it('kartoffeln -> potato ueber den Singular-Fallback (Endung "-n" weg -> "Kartoffel")', function () {
    ($this->mkAnker)('potato', 'Kartoffel');

    $res = $this->svc->ankerSlugExakt('kartoffeln');

    expect($res)->toBe(['slug' => 'potato', 'via' => 'singular']);
});

it('"gelee" bleibt ohne Treffer, auch mit Singular-Fallback (kein Anker heisst "Gele")', function () {
    ($this->mkAnker)('apple_jelly', 'Apfel Gelee');

    $res = $this->svc->ankerSlugExakt('gelee');

    expect($res)->toBeNull();
});

/**
 * Konstruierter Mehrdeutigkeits-Fall: "sennen" liesse sich auf zwei verschiedene Weisen kuerzen
 * (Endung "-n" weg -> "senne", Endung "-en" weg -> "senn") und trifft dabei ZWEI unterschiedliche,
 * aber real existierende Kurzformen -- keine der beiden darf gewinnen.
 */
it('bleibt ohne Anker, wenn zwei verschiedene Endungen auf zwei verschiedene Anker treffen', function () {
    ($this->mkAnker)('alpine_hut', 'Senne');
    ($this->mkAnker)('shepherd', 'Senn');

    $res = $this->svc->ankerSlugExakt('sennen');

    expect($res)->toBeNull();
});

it('lehnt einen zu kurzen Kandidaten ab (unter 3 Zeichen nach dem Abschneiden)', function () {
    ($this->mkAnker)('e', 'E');   // absurd kurz, darf nie treffen

    $res = $this->svc->ankerSlugExakt('en');

    expect($res)->toBeNull();
});
