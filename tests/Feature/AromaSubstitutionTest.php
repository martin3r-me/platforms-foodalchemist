<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\ComponentEquivalentService;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R6.8 — Aroma-treue Substitution: Ersatz, der den GESCHMACK erhält, nicht nur den
 * Preis. Estragon↔Kerbel (Klassiker-Tausch, geteilte Anker) muss vor einem aroma-fernen,
 * gleich teuren Ersatz (Schokolade) ranken. Aroma-Vektoren sind hier NICHT geseedet →
 * Ranking läuft graceful über die reine Kanten-Überlappung (aroma_cos = null).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PairingService::class);

    $mkAnker = function (string $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->anis = $mkAnker('anis');
    $this->kraeuter = $mkAnker('kraeuter');
    $this->kakao = $mkAnker('kakao');
    $this->gefluegel = $mkAnker('gefluegel');

    $mkKante = function (int $a, int $b, string $typ) {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            DB::table('foodalchemist_pairing_anchor_edges')->insert([
                'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
                'type' => $typ, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    };
    // Schokolade (kakao) dockt nur SCHWACH an Kräuter an (Kontrast) — nicht an Anis.
    $mkKante($this->kakao, $this->kraeuter, 'kontrast');
    // Anis brückt aufs Geflügel (fürs Kohäsions-Delta im Rezept-Kontext).
    $mkKante($this->anis, $this->gefluegel, 'erprobt');

    // GPs: alle in derselben Warengruppe (Kandidaten-Pool via Gruppe UND geteilte Anker).
    $this->mkGpMitAnkern = function (string $name, array $ankerIds, array $overrides = []) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(array_merge(['is_derivat' => false, 'is_platzhalter' => false, 'commodity_group_code' => 'GEW'], $overrides));
        foreach ($ankerIds as $aid) {
            DB::table('foodalchemist_gp_anchor_mappings')->insert([
                'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
                'gp_id' => $gp->id, 'anchor_id' => $aid, 'role' => 'kern',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $gp->refresh();
    };

    $this->estragon = ($this->mkGpMitAnkern)('Estragon', [$this->anis, $this->kraeuter]);
    $this->kerbel = ($this->mkGpMitAnkern)('Kerbel', [$this->anis, $this->kraeuter], ['allergen_gluten' => 'enthalten']);
    $this->schokolade = ($this->mkGpMitAnkern)('Schokolade', [$this->kakao]);
});

it('rankt den aroma-treuen Ersatz vor dem aroma-fernen, gleich teuren; graceful ohne Aroma-Vektoren', function () {
    $out = $this->svc->aromaTrueSubstitutes($this->rootTeam, $this->estragon->id, 8);

    expect($out['source']['name'])->toBe('Estragon')
        ->and($out['source']['anker'])->toEqualCanonicalizing(['Anis', 'Kraeuter']);

    $namen = array_column($out['candidates'], 'name');
    $idxKerbel = array_search('Kerbel', $namen, true);
    $idxScho = array_search('Schokolade', $namen, true);

    expect($idxKerbel)->not->toBeFalse()
        ->and($idxScho)->not->toBeFalse()
        ->and($idxKerbel)->toBeLessThan($idxScho);              // Klassiker-Tausch zuerst

    $kerbel = $out['candidates'][$idxKerbel];
    $scho = $out['candidates'][$idxScho];

    expect($kerbel['flavor_score'])->toBe(1.0)                   // beide Anker erhalten
        ->and($kerbel['aroma_cos'])->toBeNull()                 // keine Aroma-Vektoren → graceful
        ->and($kerbel['erhaltene_bruecken'])->toEqualCanonicalizing(['Anis', 'Kraeuter'])
        ->and($kerbel['verlorene_bruecken'])->toBe([])
        ->and($kerbel['flavor_score'])->toBeGreaterThan($scho['flavor_score']);

    // Schokolade: Kräuter über die Kontrast-Kante erhalten, Anis verloren → 0,5.
    expect($scho['flavor_score'])->toBe(0.5)
        ->and($scho['erhaltene_bruecken'])->toBe(['Kraeuter'])
        ->and($scho['verlorene_bruecken'])->toBe(['Anis']);

    // Allergen-Neubewertung VOR Tausch: Kerbel bringt Gluten neu ein.
    expect($kerbel['allergen_warnungen'])->toHaveKey('gluten')
        ->and($kerbel['allergen_warnungen']['gluten'])->toBe('enthalten')
        ->and($scho['allergen_warnungen'])->toBe([]);

    // Evidenz durchgereicht (E1): abgeleitet, ohne Aroma-Vektor.
    expect($kerbel['evidenz']['tier'])->toBe('abgeleitet')
        ->and($kerbel['evidenz']['aroma_vektor'])->toBeFalse();
});

it('boostet manuell kuratierte Äquivalente (kuratiert zuerst, trotz schwacher Aroma-Treue)', function () {
    // Liebstöckel ohne geteilte Anker → aroma-schwach, aber manuell verknüpft.
    $liebstoeckel = ($this->mkGpMitAnkern)('Liebstoeckel', []);
    app(ComponentEquivalentService::class)->verknuepfe($this->rootTeam, 'gp', $this->estragon->id, 'gp', $liebstoeckel->id);

    $out = $this->svc->aromaTrueSubstitutes($this->rootTeam, $this->estragon->id, 8);

    expect($out['candidates'][0]['name'])->toBe('Liebstoeckel')
        ->and($out['candidates'][0]['is_manual_equiv'])->toBeTrue()
        ->and($out['candidates'][0]['evidenz']['tier'])->toBe('kuratiert');

    // Kerbel bleibt drin und steht (als aroma-treuer) vor Schokolade.
    $namen = array_column($out['candidates'], 'name');
    expect(array_search('Kerbel', $namen, true))->toBeLessThan(array_search('Schokolade', $namen, true));
});

it('liefert im Rezept-Kontext base_cohesion, swap_locked und ein Kohäsions-Delta', function () {
    $g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $huhn = ($this->mkGpMitAnkern)('Huhn', [$this->gefluegel]);

    $recipeSvc = app(RecipeService::class);
    $rezept = $recipeSvc->create($this->rootTeam, ['name' => 'Fond: Huhn-Estragon']);
    $recipeSvc->syncIngredients($this->rootTeam, $rezept->id, [
        ['gp_id' => $this->estragon->id, 'raw_text' => '5 g Estragon', 'quantity' => '5', 'unit_vocab_id' => $g->id],
        ['gp_id' => $huhn->id, 'raw_text' => '500 g Huhn', 'quantity' => '500', 'unit_vocab_id' => $g->id],
    ]);
    $estragonZutat = $rezept->ingredients()->where('gp_id', $this->estragon->id)->first();
    $estragonZutat->update(['swap_locked' => true]);

    $out = $this->svc->aromaTrueSubstitutes($this->rootTeam, 0, 8, (int) $estragonZutat->id);

    expect($out['source']['name'])->toBe('Estragon')                 // aus der Zutat abgeleitet
        ->and($out['context']['recipe_id'])->toBe($rezept->id)
        ->and($out['context']['swap_locked'])->toBeTrue()
        ->and($out['context']['base_cohesion'])->toBeInt();

    $namen = array_column($out['candidates'], 'name');
    $kerbel = $out['candidates'][array_search('Kerbel', $namen, true)];
    $scho = $out['candidates'][array_search('Schokolade', $namen, true)];

    // Aroma-treuer Tausch erhält die Teller-Kohäsion besser als der aroma-ferne.
    expect($kerbel['kohaesions_delta'])->not->toBeNull()
        ->and($kerbel['kohaesions_delta'])->toBeGreaterThanOrEqual($scho['kohaesions_delta']);
});
