<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\Pairing\AnkerVarianten;
use Platform\FoodAlchemist\Services\Pairing\RezeptProfil;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 · P5: Aromenprofil eines Basisrezepts — Gramm × Intensität × Rolle, rekursiv über
 * Unterrezepte, Verfahren wählt die Inspire-Variante, offene Bedarfe, Quell-Hash.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->anker = [];
    $mk = function (string $slug, string $name, float $intensitaet) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => $name,
            'aroma_intensitaet' => $intensitaet, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->anker[$slug] = (int) DB::getPdo()->lastInsertId();
    };
    $mk('potato', 'Kartoffel', 1.0);
    $mk('rosemary', 'Rosmarin', 3.0);
    $mk('olive_oil', 'Olivenöl', 1.0);
    $mk('sea_salt', 'Meersalz', 0.0);
    $mk('pumpkin', 'Kürbis', 1.0);
    $mk('roasted_pumpkin', 'Kürbis, im Ofen geröstet', 1.0);
    $mk('parsley', 'Petersilie', 3.0);
    $mk('red_wine_vinegar', 'Rotweinessig', 2.0);
    app(AnkerVarianten::class)->ableiten();

    $this->gp = function (string $name, string $slug) {
        $gp = $this->makeGp($this->rootTeam, $name);
        DB::table('foodalchemist_gp_anchor_mappings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id, 'gp_id' => $gp->id,
            'anchor_id' => $this->anker[$slug], 'role' => 'kern', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $gp;
    };
    $this->bedarf = fn (string $slug, string $achse, string $staerke = 'soll') => DB::table('foodalchemist_anchor_bedarfe')->insert([
        'anchor_id' => $this->anker[$slug], 'achse' => $achse, 'staerke' => $staerke, 'status' => 'entwurf',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->liefert = fn (string $slug, string $achse, int $stufe) => DB::table('foodalchemist_anchor_eigenschaften')->insert([
        'anchor_id' => $this->anker[$slug], 'achse' => $achse, 'stufe' => $stufe, 'quelle' => 'dossier', 'status' => 'entwurf',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->svc = fn () => tap(app(RezeptProfil::class))->vergiss();
    $this->anteile = fn (array $p) => collect($p['anker'])->mapWithKeys(fn ($a) => [array_search($a['anchor_id'], $this->anker, true) => $a['anteil']])->all();
});

it('Anteil = Gramm × Intensität; Salz ohne Aroma, Anteile unter 5 % fallen weg', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Rosmarinkartoffeln');
    $this->makeIngredient($r, 'Kartoffeln', ($this->gp)('Kartoffeln: frisch', 'potato'), '400', 1);
    $this->makeIngredient($r, 'Rosmarin', ($this->gp)('Rosmarin: frisch', 'rosemary'), '20', 2);
    $this->makeIngredient($r, 'Olivenöl', ($this->gp)('Olivenöl', 'olive_oil'), '10', 3);    // 10 / 470 ≈ 2 % → fällt weg
    $this->makeIngredient($r, 'Meersalz', ($this->gp)('Meersalz', 'sea_salt'), '8', 4);

    $p = ($this->svc)()->fuer($r->id);

    // 400 × 1 = 400 · 20 × 3 = 60 · Öl 10 (< 5 %) raus → 400/460 = 86,96 %, 60/460 = 13,04 %
    expect(($this->anteile)($p))->toBe(['potato' => 86.96, 'rosemary' => 13.04])
        ->and($p['abdeckung'])->toBe(100.0)
        ->and(DB::table('foodalchemist_recipe_profile_anker')->where('recipe_id', $r->id)->count())->toBe(2);
});

it('Verfahren im Zutatentext wählt die Inspire-Variante', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Kürbisspalten');
    $this->makeIngredient($r, 'Kürbis, im Ofen geröstet', ($this->gp)('Kürbis: frisch', 'pumpkin'), '500', 1);

    $p = ($this->svc)()->fuer($r->id);

    expect(($this->anteile)($p))->toBe(['roasted_pumpkin' => 100.0])
        ->and($p['anker'][0]['verfahren'])->toBe('geroestet');
});

it('Unterrezept geht mit seinem eigenen Profil anteilig ein', function () {
    $chimi = $this->makeRecipe($this->rootTeam, 'Chimichurri');
    $this->makeIngredient($chimi, 'Petersilie', ($this->gp)('Petersilie: frisch', 'parsley'), '50', 1);
    $this->makeIngredient($chimi, 'Rotweinessig', ($this->gp)('Rotweinessig', 'red_wine_vinegar'), '50', 2);
    $teller = $this->makeRecipe($this->rootTeam, 'Kartoffel mit Chimichurri');
    $this->makeIngredient($teller, 'Kartoffeln', ($this->gp)('Kartoffeln: frisch', 'potato'), '300', 1);
    FoodAlchemistRecipeIngredient::create(['team_id' => $teller->team_id, 'recipe_id' => $teller->id,
        'referenced_recipe_id' => $chimi->id, 'raw_text' => 'Chimichurri', 'quantity' => '100',
        'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 2]);

    $p = ($this->svc)()->fuer($teller->id);

    // Chimichurri roh: Petersilie 150, Essig 100 je 100 g → voll eingesetzt; Kartoffel 300
    expect(($this->anteile)($p))->toBe(['potato' => 54.55, 'parsley' => 27.27, 'red_wine_vinegar' => 18.18])
        ->and(DB::table('foodalchemist_recipe_profile')->where('recipe_id', $chimi->id)->exists())->toBeTrue();
});

it('offene Bedarfe: was der Kern braucht und das Rezept nicht selbst liefert', function () {
    ($this->bedarf)('potato', 'saeure', 'muss');
    ($this->bedarf)('potato', 'fett');
    ($this->liefert)('olive_oil', 'fett', 3);
    ($this->liefert)('red_wine_vinegar', 'saeure', 3);

    $r = $this->makeRecipe($this->rootTeam, 'Ofenkartoffeln');
    $this->makeIngredient($r, 'Kartoffeln', ($this->gp)('Kartoffeln: frisch', 'potato'), '400', 1);
    $this->makeIngredient($r, 'Olivenöl', ($this->gp)('Olivenöl', 'olive_oil'), '60', 2);

    $p = ($this->svc)()->fuer($r->id);

    expect($p['eigenschaften']['fett']['stufe'])->toBe(3.0)                       // 60/460 = 13 % ≥ 10 % → volle Stufe
        ->and($p['offene_bedarfe'])->toBe([['achse' => 'saeure', 'staerke' => 'muss', 'von' => $this->anker['potato']]]);
});

it('Quell-Hash: unverändert wird nicht neu geschrieben, eine Mengenänderung schon', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Rosmarinkartoffeln');
    $kart = $this->makeIngredient($r, 'Kartoffeln', ($this->gp)('Kartoffeln: frisch', 'potato'), '400', 1);
    $this->makeIngredient($r, 'Rosmarin', ($this->gp)('Rosmarin: frisch', 'rosemary'), '20', 2);
    ($this->svc)()->fuer($r->id);
    $hash = DB::table('foodalchemist_recipe_profile')->where('recipe_id', $r->id)->value('quelle_hash');

    ($this->svc)()->fuer($r->id);
    expect(DB::table('foodalchemist_recipe_profile')->where('recipe_id', $r->id)->value('quelle_hash'))->toBe($hash);

    $kart->update(['quantity' => '100']);
    ($this->svc)()->fuer($r->id);
    expect(DB::table('foodalchemist_recipe_profile')->where('recipe_id', $r->id)->value('quelle_hash'))->not->toBe($hash);
});

it('ein Zyklus zwischen Rezepten bricht ab, ohne ein leeres Profil zu speichern', function () {
    $a = $this->makeRecipe($this->rootTeam, 'Basis A');
    $b = $this->makeRecipe($this->rootTeam, 'Basis B');
    $this->makeIngredient($a, 'Kartoffeln', ($this->gp)('Kartoffeln: frisch', 'potato'), '100', 1);
    foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
        FoodAlchemistRecipeIngredient::create(['team_id' => $x->team_id, 'recipe_id' => $x->id, 'referenced_recipe_id' => $y->id,
            'raw_text' => $y->name, 'quantity' => '50', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 5]);
    }

    $p = ($this->svc)()->fuer($a->id);

    expect(($this->anteile)($p))->toBe(['potato' => 100.0])
        ->and(DB::table('foodalchemist_recipe_profile')->where('recipe_id', $a->id)->exists())->toBeTrue();
});
