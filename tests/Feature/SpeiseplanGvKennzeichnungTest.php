<?php

use Illuminate\Support\Carbon;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 31 / Stufe A (GV-Ausbau) — LMIV-Kennzeichnungs-Rollup (14 Allergene + 18 Zusatzstoffe,
 * ALL-MAXIMAL) + Kostformen-Tagesabdeckung.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->plan = app(SpeiseplanService::class);
    $this->agg = app(ConcepterAggregateService::class);
});

// function_exists-Guard: verhindert Redeclare, wenn Pest bei --parallel alle Testdateien
// zum Sammeln lädt (globale Test-Helfer sind sonst eine Parallel-Falle).
if (! function_exists('gvGericht')) {
    function gvGericht(int $teamId, string $key, array $felder = []): FoodAlchemistRecipe
    {
        $r = FoodAlchemistRecipe::create([
            'team_id' => $teamId, 'recipe_key' => $key, 'name' => 'G-' . $key, 'status' => 'approved',
            'is_sales_recipe' => true, 'sales_net' => 3.0, 'ek_total_eur' => 1.0,
        ]);
        $r->forceFill(array_merge(['allergens_confidence' => 'high'], $felder))->save();

        return $r->refresh();
    }
}

it('Stufe A: Kennzeichnung aggregiert ALL-MAXIMAL (ein »enthalten« gewinnt, Zusatzstoff 3 = ja)', function () {
    $mitGluten = gvGericht($this->rootTeam->id, 'k1', ['allergen_gluten' => 'enthalten', 'additive_with_dye' => 3]);
    $frei = gvGericht($this->rootTeam->id, 'k2', ['allergen_gluten' => 'nicht_enthalten', 'additive_with_dye' => 1]);

    $k = $this->agg->kennzeichnungFromGerichte(collect([$mitGluten, $frei]));

    expect(collect($k['allergene'])->firstWhere('slug', 'gluten')['status'])->toBe('enthalten')
        ->and(collect($k['zusatzstoffe'])->firstWhere('slug', 'with_dye')['status'])->toBe('ja')
        ->and($k['n_gerichte'])->toBe(2);
});

it('Stufe A: leere Sammlung + Unbekanntes zählt NIE als frei', function () {
    $leer = $this->agg->kennzeichnungFromGerichte(collect());
    expect(collect($leer['allergene'])->firstWhere('slug', 'milk')['status'])->toBe('unbekannt');

    // ein Gericht mit unbekanntem Milch-Status → Rollup bleibt »unbekannt«, nicht »nicht_enthalten«
    $unklar = gvGericht($this->rootTeam->id, 'u1'); // allergen_milk bleibt Default 'unbekannt'
    $k = $this->agg->kennzeichnungFromGerichte(collect([$unklar]));
    expect(collect($k['allergene'])->firstWhere('slug', 'milk')['status'])->toBe('unbekannt');
});

it('Stufe A: Kostformen-Abdeckung + Wochen-Kennzeichnung am Plan', function () {
    $veg = gvGericht($this->rootTeam->id, 'v1', ['spec_is_vegetarian' => true, 'allergen_gluten' => 'enthalten']);
    $sp = $this->plan->create($this->rootTeam, ['name' => 'GV-Woche', 'start_date' => '2026-07-06']);
    // nur Montag ein vegetarisches Gericht → Di–Fr offen
    $this->plan->addEintrag($this->rootTeam, $sp->id, ['entry_date' => '2026-07-06', 'mahlzeit' => 'mittag', 'sales_recipe_id' => $veg->id]);

    $montag = Carbon::parse('2026-07-06');
    $kf = collect($this->plan->kostformAbdeckung($sp->refresh(), 'mittag', $montag))->firstWhere('key', 'vegetarisch');
    expect($kf['abgedeckt'])->toBe(1)->and($kf['erfuellt'])->toBeFalse()->and($kf['fehltage'])->toHaveCount(4);

    $kz = $this->plan->wochenKennzeichnung($sp, 'mittag', $montag);
    expect(collect($kz['woche']['allergene'])->firstWhere('slug', 'gluten')['status'])->toBe('enthalten')
        ->and(collect($kz['pro_tag']['2026-07-06']['allergene'])->firstWhere('slug', 'gluten')['status'])->toBe('enthalten');
});

it('Stufe B: Aushang-Daten — Grid + Codes + nur-verwendete Legende', function () {
    $veg = gvGericht($this->rootTeam->id, 'b1', ['spec_is_vegetarian' => true, 'allergen_gluten' => 'enthalten', 'additive_with_dye' => 3]);
    $sp = $this->plan->create($this->rootTeam, ['name' => 'Aushang', 'start_date' => '2026-07-06']);
    $this->plan->addEintrag($this->rootTeam, $sp->id, ['entry_date' => '2026-07-06', 'mahlzeit' => 'mittag', 'sales_recipe_id' => $veg->id]);

    $d = $this->plan->dokumentDaten($this->rootTeam, $sp->refresh(), 'mittag', '2026-07-06');

    expect($d['tage'])->toHaveCount(5)->and($d['mahlzeitLabel'])->toBe('Mittag');
    // »Ohne Linie«-Zeile trägt das Gericht am Montag mit Codes A (Gluten, 1. Allergen) + 1 (Farbstoff, 1. Zusatzstoff)
    $ohne = collect($d['zeilen'])->firstWhere('linie', 'Ohne Linie');
    $mo = $ohne['zellen']['2026-07-06'];
    expect($mo)->toHaveCount(1)
        ->and($mo[0]['codes'])->toContain('A')->toContain('1');
    // Legende führt GENAU diese Kennzeichen (nur vorkommende)
    expect(collect($d['legende']['allergene'])->firstWhere('code', 'A')['label'])->toBe('Glutenhaltiges Getreide')
        ->and(collect($d['legende']['zusatzstoffe'])->firstWhere('code', '1')['label'])->toBe('mit Farbstoff');
});
