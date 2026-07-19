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
 * R6.11 · S1 — Hypothesen-Modus: Ranking nach geteilten Compound-Klassen
 * (Aroma-key_components + Molekül-chem_class), Mechanismus + Evidenz-Stufe T3,
 * Novität-Markierung gegen bestehende Kanten, graceful bei leeren Chem-Daten.
 * Chem-Tabellen haben KEINE FKs → freie ingredient_ids im Fixture ok.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PairingService::class);
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);

    $mkAnker = function (string $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    // A = Quelle, B = starke Hypothese (teilt 2 kc + chem_class), C = schwächer (1 kc, ETABLIERT), D = ohne Profil.
    $this->a = $mkAnker('quelle');
    $this->b = $mkAnker('kandidat_stark');
    $this->c = $mkAnker('kandidat_etabliert');
    $this->d = $mkAnker('ohne_profil');

    // anchor → ingredient (frei gewählte IDs; Tabellen ohne FK)
    foreach ([[$this->a, 1001], [$this->b, 1002], [$this->c, 1003]] as [$anchor, $ing]) {
        DB::table('foodalchemist_anchor_ingredient_map')->insert([
            'anchor_id' => $anchor, 'slug_de' => 'ing'.$ing, 'ingredient_id' => $ing,
            'has_profile' => 1, 'n_key_components' => 0, 'match_method' => 'test',
        ]);
    }

    // key_components kc1..kc3
    $mkKc = function (string $key, string $family, string $aroma) {
        DB::table('foodalchemist_key_components')->insert([
            'key' => $key, 'family' => $family, 'aroma_type' => $aroma, 'character' => $key,
            'kind' => 'aroma', 'n_molecules' => 0, 'n_ingredients' => 0,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $kc1 = $mkKc('vanillin', 'Vanille-Familie', 'sweet');
    $kc2 = $mkKc('furaneol', 'Karamell-Familie', 'caramel');
    $kc3 = $mkKc('linalool', 'Blumig-Familie', 'floral');

    // ingredient → key_component: A={kc1,kc2,kc3}, B={kc1,kc2} (2 geteilt), C={kc3} (1 geteilt)
    $ikc = [[1001, $kc1], [1001, $kc2], [1001, $kc3], [1002, $kc1], [1002, $kc2], [1003, $kc3]];
    foreach ($ikc as [$ing, $comp]) {
        DB::table('foodalchemist_ingredient_key_component')->insert(['ingredient_id' => $ing, 'component_id' => $comp, 'n_molecules' => 1]);
    }

    // molecules mit chem_class + ingredient_molecule: A hat Furans+Pyrazines, B hat Furans (geteilt), C nichts
    $mkMol = function (string $class) {
        DB::table('foodalchemist_molecules')->insert(['name' => 'mol_'.$class, 'chem_class' => $class, 'source' => 'test']);

        return (int) DB::getPdo()->lastInsertId();
    };
    $mFuran = $mkMol('Furans');
    $mPyra = $mkMol('Pyrazines');
    foreach ([[1001, $mFuran], [1001, $mPyra], [1002, $mFuran]] as [$ing, $mol]) {
        DB::table('foodalchemist_ingredient_molecule')->insert(['ingredient_id' => $ing, 'molecule_id' => $mol, 'source' => 'test']);
    }

    // Bestehende Kante A–C (symmetrisch) → C ist ETABLIERT, B bleibt novel.
    foreach ([[$this->a, $this->c], [$this->c, $this->a]] as [$x, $y]) {
        DB::table('foodalchemist_pairing_anchor_edges')->insert([
            'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
            'type' => 'erprobt', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

it('rankt Kandidaten nach geteilten Compound-Klassen, mit Mechanismus + T3 + Novität', function () {
    $res = $this->svc->hypothesizeFor(['anchor' => $this->a], 10);

    expect($res['methode'])->toBe('compound_class')
        ->and($res['source']['n_key_components'])->toBe(3);

    $ids = array_column($res['hypothesen'], 'anchor_id');
    expect($ids)->toContain($this->b)
        ->and($ids)->toContain($this->c)
        ->and($ids)->not->toContain($this->d)          // ohne Profil → nicht gerankt
        ->and($ids)->not->toContain($this->a);         // sich selbst nie

    // B (2 geteilt) vor C (1 geteilt)
    expect(array_search($this->b, $ids, true))->toBeLessThan(array_search($this->c, $ids, true));

    $b = collect($res['hypothesen'])->firstWhere('anchor_id', $this->b);
    expect($b['n_geteilt'])->toBe(2)
        ->and($b['score'])->toBe(2)
        ->and($b['evidenz_tier'])->toBe('T3')
        ->and($b['ist_etabliert'])->toBeFalse()          // A–B keine Kante → Hypothese
        ->and($b['mechanismus'])->toContain('Familie')
        ->and($b['geteilte_chem_klassen'])->toContain('Furans');

    $c = collect($res['hypothesen'])->firstWhere('anchor_id', $this->c);
    expect($c['ist_etabliert'])->toBeTrue()              // A–C Kante existiert → bekannt
        ->and($c['edge_typ'])->toBe('erprobt');
});

it('sharedCompoundClasses liefert Schnitt der Aroma- + Molekül-Klassen', function () {
    $shared = $this->svc->sharedCompoundClasses($this->a, $this->b);
    expect($shared['n_key_components'])->toBe(2)
        ->and($shared['chem_classes'])->toContain('Furans')
        ->and($shared['chem_classes'])->not->toContain('Pyrazines');  // B hat kein Pyrazine

    // Ohne Profil (D) → leer, kein Fehler.
    expect($this->svc->sharedCompoundClasses($this->a, $this->d)['n_key_components'])->toBe(0);
});

it('GP-Quelle löst über kern-Anker auf und rankt identisch', function () {
    $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'gp_key' => 'hypo|test|test', 'name' => 'Testquelle',
    ]);
    DB::table('foodalchemist_gp_anchor_mappings')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'gp_id' => $gp->id, 'anchor_id' => $this->a, 'role' => 'kern', 'source' => 'manual',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $res = $this->svc->hypothesizeFor(['gp' => $gp->id], 10);
    expect($res['source']['typ'])->toBe('gp')
        ->and($res['source']['anchor_ids'])->toContain($this->a)
        ->and(collect($res['hypothesen'])->firstWhere('anchor_id', $this->b)['n_geteilt'])->toBe(2);
});

it('graceful: Quelle ohne Compound- und Aroma-Daten → Fallback, leer, ohne Fehler', function () {
    $leer = $this->svc->hypothesizeFor(['anchor' => $this->d], 10);   // D hat kein anchor_ingredient_map
    expect($leer['methode'])->toBe('aroma_vector_fallback')
        ->and($leer['hypothesen'])->toBe([]);
});

it('MCP knowledge.HYPOTHESIZE: read-only, liefert Hypothesen für einen Anker', function () {
    $tool = $this->registry->get('foodalchemist.knowledge.HYPOTHESIZE');
    expect($tool)->not->toBeNull()
        ->and($tool->getMetadata()['read_only'])->toBeTrue();

    $res = $tool->execute(['anchor' => 'quelle', 'limit' => 5], $this->kontext);
    expect($res->success)->toBeTrue()
        ->and($res->data['methode'])->toBe('compound_class')
        ->and(collect($res->data['hypothesen'])->pluck('anchor_id'))->toContain($this->b);

    // ohne gp_id/anchor → sauberer Fehler
    expect($tool->execute([], $this->kontext)->success)->toBeFalse();
});
