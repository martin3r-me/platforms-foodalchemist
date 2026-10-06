<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * „Komplettiert den Teller" (A): die signature-Rangliste (spec = cover×w/√degree)
 * rechnet Allrounder raus — sie wird jetzt zusätzlich zur klassiker-Rangliste
 * (reine Abdeckung) ausgespielt. Rein deterministisch, kein Embedding.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PairingService::class);

    $this->mkAnker = function (string $slug): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->mkKante = function (int $a, int $b, string $typ = 'erprobt') {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {                  // bidirektional (Inv. 4)
            \Platform\FoodAlchemist\Tests\Support\Harmonie::ausFixture([
                'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
                'type' => $typ, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    };
    $this->mkRezeptMitZutaten = function (array $rawTexte, bool $istGericht = true): FoodAlchemistRecipe {
        $r = FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => 'sig-' . count($rawTexte) . '-' . uniqid(),
            'name' => 'Test: Signature', 'status' => 'draft', 'is_sales_recipe' => $istGericht,
        ]);
        $g = FoodAlchemistVocabEinheit::create([
            'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
        ]);
        foreach ($rawTexte as $i => $txt) {
            DB::table('foodalchemist_recipe_ingredients')->insert([
                'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
                'recipe_id' => $r->id, 'raw_text' => $txt, 'quantity' => 100,
                'unit_vocab_id' => $g->id, 'position' => $i + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $r;
    };
});

it('signature stuft den Allrounder runter, klassiker nicht', function () {
    // Teller-Anker (über raw_text aufgelöst)
    $erd = ($this->mkAnker)('erdbeere');
    $bas = ($this->mkAnker)('basilikum');
    $tom = ($this->mkAnker)('tomate');
    // Kandidaten
    $salz = ($this->mkAnker)('salz');        // Allrounder: hoher Grad
    $vanille = ($this->mkAnker)('vanille');  // gericht-eigen: niedriger Grad
    $n1 = ($this->mkAnker)('noise_eins');
    $n2 = ($this->mkAnker)('noise_zwei');

    // salz deckt alle 3 Teller-Anker + 2 Rausch-Anker → cover 3, degree 5
    foreach ([$erd, $bas, $tom, $n1, $n2] as $z) {
        ($this->mkKante)($salz, $z);
    }
    // vanille deckt 2 Teller-Anker → cover 2, degree 2
    ($this->mkKante)($vanille, $erd);
    ($this->mkKante)($vanille, $bas);

    $recipe = ($this->mkRezeptMitZutaten)(['Erdbeere', 'Basilikum', 'Tomate']);

    $sug = $this->svc->componentSuggestions($recipe);

    // klassiker (Abdeckung) → Allrounder vorn
    expect($sug['klassiker'][0]['slug'])->toBe('salz')
        // signature (spec) → gericht-eigenes Aroma vorn, Allrounder runtergestuft
        ->and($sug['signature'][0]['slug'])->toBe('vanille');
});

it('Gericht: panelRecipe liefert dieselbe Aussage wie die Detail-Spalte (Kombinationslogik + Netz)', function () {
    $erd = ($this->mkAnker)('erdbeere');
    $bas = ($this->mkAnker)('basilikum');
    ($this->mkKante)($erd, $bas);

    $recipe = ($this->mkRezeptMitZutaten)(['Erdbeere', 'Basilikum'], istGericht: true);
    $panel = $this->svc->panelRecipe($recipe);

    expect($panel['ist_gericht'])->toBeTrue()
        ->and($panel['kombination']['art'])->toBe('gericht')
        ->and($panel['kombination']['kennzahlen']['harmoniert'] ?? 0)->toBe(1)
        ->and($panel['netz']['meta']['art'])->toBe('gericht')
        ->and($panel)->toHaveKeys(['passt_dazu', 'deckt_bedarf', 'profil'])
        ->and($panel)->not->toHaveKey('signature')                           // alte Anker-Teller-Listen sind raus
        ->and($panel)->not->toHaveKey('score');
});

it('Basisrezept: Aromenprofil mit Anteil, nur ★★★-Partner, keine Teller-Blöcke', function () {
    $erd = ($this->mkAnker)('erdbeere');
    $van = ($this->mkAnker)('vanille');
    $salz = ($this->mkAnker)('salz');
    ($this->mkKante)($erd, $van);              // ★★★
    ($this->mkKante)($erd, $salz, 'aroma');    // nur ★★ → kein Partner

    $basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'basis-sauce',
        'name' => 'Basis: Sauce', 'status' => 'draft', 'is_sales_recipe' => false,
    ]);
    \Platform\FoodAlchemist\Tests\Support\RezeptAnker::gib($basis, $erd);

    $panel = $this->svc->panelRecipe($basis);

    expect($panel['ist_gericht'])->toBeFalse()
        ->and($panel['profil'])->toBe([['name' => 'Erdbeere', 'anteil' => 100.0]])
        ->and($panel['partner'])->toBe(['Vanille'])
        ->and($panel['passt_dazu'])->toBe([]);
});

it('GP-Editor: nur ★★★-Partner, was der Anker braucht, wer es liefert, womit er sich stört', function () {
    $kuerbis = ($this->mkAnker)('kuerbis');
    $salbei = ($this->mkAnker)('salbei');
    $nuss = ($this->mkAnker)('walnuss');
    $essig = ($this->mkAnker)('reisessig');
    $anis = ($this->mkAnker)('anis');
    ($this->mkKante)($kuerbis, $salbei);                 // ★★★
    ($this->mkKante)($kuerbis, $nuss, 'aroma');          // ★★ → kein Partner
    ($this->mkKante)($kuerbis, $essig);                  // ★★★ (Lieferant muss zur Auswahl passen)
    $w = fn (string $t, array $z) => DB::table($t)->insert($z + ['status' => 'entwurf', 'created_at' => now(), 'updated_at' => now()]);
    $w('foodalchemist_anchor_bedarfe', ['anchor_id' => $kuerbis, 'achse' => 'saeure', 'staerke' => 'muss']);
    $w('foodalchemist_anchor_eigenschaften', ['anchor_id' => $essig, 'achse' => 'saeure', 'stufe' => 2, 'quelle' => 'dossier']);
    DB::table('foodalchemist_anchor_beziehungen')->insert(['anchor_a_id' => $kuerbis, 'anchor_b_id' => $anis, 'art' => 'konflikt',
        'achse' => '', 'grundlage' => 'dossier', 'status' => 'entwurf', 'created_at' => now(), 'updated_at' => now()]);
    app(\Platform\FoodAlchemist\Services\Pairing\KontrastAbleitung::class)->baue();
    $gp = $this->makeGp($this->rootTeam, 'Kürbis: frisch');
    DB::table('foodalchemist_gp_anchor_mappings')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'gp_id' => $gp->id, 'anchor_id' => $kuerbis, 'role' => 'kern', 'created_at' => now(), 'updated_at' => now()]);

    $p = $this->svc->panelGp((int) $gp->id);

    expect($p['aroma'])->toBe(['Reisessig', 'Salbei'])
        ->and($p['braucht'])->toBe([['achse' => 'Säure', 'staerke' => 'muss', 'grundlage' => 'aus Dossier, ungeprüft']])
        ->and($p['kontrast'])->toBe([['name' => 'Reisessig', 'achse' => 'Säure']])
        ->and($p['konflikt'])->toBe(['Anis']);
});
