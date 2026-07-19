<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R6.11 · S4 — Kontrast-Hypothesen: Paarung über SPANNUNG (Geschmacks-Gegensatz)
 * statt Aroma-Verwandtschaft + die kuratierten kontrast-Kanten offensiv. Harmonie-
 * Kandidaten (gleiche Achse) tauchen NICHT auf.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PairingService::class);
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);

    $mkAnker = function (string $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $mkTaste = function (int $anchor, array $achsen) {
        $row = ['anchor_id' => $anchor, 'suess' => 0, 'salzig' => 0, 'sauer' => 0,
            'bitter' => 0, 'umami' => 0, 'fettig' => 0, 'scharf' => 0, 'source' => 'test'];
        DB::table('foodalchemist_anchor_taste_vectors')->insert(array_merge($row, $achsen));
    };

    $this->zitrone = $mkAnker('zitrone');     // Quelle: stark sauer
    $this->sahne = $mkAnker('sahne');         // stark fettig → Kontrast fettig↔sauer (neu)
    $this->limette = $mkAnker('limette');     // stark sauer → Harmonie, KEIN Kontrast
    $this->sojasauce = $mkAnker('sojasauce'); // umami/salzig → Kontrast umami↔sauer + kuratierte Kante
    $this->quitte = $mkAnker('quitte');       // OHNE Geschmacks-Vektor
    $this->apfel = $mkAnker('apfel');

    $mkTaste($this->zitrone, ['sauer' => 0.9]);
    $mkTaste($this->sahne, ['fettig' => 0.9]);
    $mkTaste($this->limette, ['sauer' => 0.9]);
    $mkTaste($this->sojasauce, ['salzig' => 0.7, 'umami' => 0.6, 'sauer' => 0.1]);
    // quitte bewusst OHNE taste-vector

    // kuratierte kontrast-Kanten (symmetrisch): zitrone–sojasauce, quitte–apfel
    foreach ([[$this->zitrone, $this->sojasauce], [$this->quitte, $this->apfel]] as [$a, $b]) {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            DB::table('foodalchemist_pairing_anchor_edges')->insert([
                'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
                'type' => 'kontrast', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
});

it('rankt Geschmacks-Gegensatz: Sahne (fettig) top, Limette (Harmonie) fehlt', function () {
    $res = $this->svc->contrastHypothesesFor(['anchor' => $this->zitrone], 10);

    expect($res['methode'])->toBe('kontrast_geschmack');
    $ids = array_column($res['hypothesen'], 'anchor_id');

    expect($ids)->toContain($this->sahne)
        ->and($ids)->not->toContain($this->limette)     // gleiche Achse = Harmonie, kein Kontrast
        ->and($ids)->not->toContain($this->zitrone);    // nie sich selbst

    $sahne = collect($res['hypothesen'])->firstWhere('anchor_id', $this->sahne);
    expect($sahne['evidenz_tier'])->toBe('T3')
        ->and($sahne['ist_etabliert'])->toBeFalse()
        ->and(collect($sahne['opponierende_achsen'])->implode(' '))->toContain('fettig')
        ->and($sahne['score'])->toBeGreaterThan(0);

    // Sahne (0.81) vor Sojasauce (0.54)
    expect(array_search($this->sahne, $ids, true))->toBeLessThan(array_search($this->sojasauce, $ids, true));
});

it('liefert die kuratierten kontrast-Kanten (T0) + markiert bekannte generative Treffer', function () {
    $res = $this->svc->contrastHypothesesFor(['anchor' => $this->zitrone], 10);

    $kuratiert = collect($res['kuratiert']);
    expect($kuratiert->pluck('anchor_id'))->toContain($this->sojasauce)
        ->and($kuratiert->firstWhere('anchor_id', $this->sojasauce)['evidenz_tier'])->toBe('T0');

    // Sojasauce ist auch generativ getroffen (umami↔sauer) → dort ist_etabliert=true
    $soja = collect($res['hypothesen'])->firstWhere('anchor_id', $this->sojasauce);
    expect($soja['ist_etabliert'])->toBeTrue();
});

it('Quelle ohne Geschmacks-Vektor → nur_kuratiert, generative Liste leer', function () {
    $res = $this->svc->contrastHypothesesFor(['anchor' => $this->quitte], 10);

    expect($res['methode'])->toBe('nur_kuratiert')
        ->and($res['hypothesen'])->toBe([])
        ->and(collect($res['kuratiert'])->pluck('anchor_id'))->toContain($this->apfel);
});

it('MCP knowledge.HYPOTHESIZE mode=kontrast liefert kuratiert + hypothesen', function () {
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($this->user, $this->rootTeam);
    $res = $registry->get('foodalchemist.knowledge.HYPOTHESIZE')
        ->execute(['anchor' => 'zitrone', 'mode' => 'kontrast', 'limit' => 5], $kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['methode'])->toBe('kontrast_geschmack')
        ->and(collect($res->data['hypothesen'])->pluck('anchor_id'))->toContain($this->sahne)
        ->and(collect($res->data['kuratiert'])->pluck('anchor_id'))->toContain($this->sojasauce);
});
